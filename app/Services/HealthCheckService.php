<?php

namespace App\Services;

use App\Enums\HealthStatus;
use App\Enums\UserTypes;
use App\Models\Artist;
use App\Models\Studio;
use App\Models\Tattoo;
use App\Models\User;
use App\Scopes\ArtistScope;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Larelastic\Elastic\Facades\Elastic;
use Throwable;

/**
 * Production health checks.
 *
 * These assert invariants rather than uptime. The failures worth catching here
 * have all looked identical from outside: the site up, every request returning
 * 200, and accounts or posts quietly missing from search.
 */
class HealthCheckService
{
    public function __construct(protected ElasticService $elastic)
    {
    }

    /**
     * Cheap liveness only. Backs the public endpoint, so it returns no counts
     * and no identifiers.
     */
    public function shallow(): array
    {
        return $this->summarise([
            $this->checkDatabase(),
            $this->checkRedis(),
            $this->checkElasticsearch(),
        ]);
    }

    /**
     * Every check. Backs the scheduled run and the authenticated endpoint.
     */
    public function deep(): array
    {
        $elasticsearch = $this->checkElasticsearch();

        $checks = [
            $this->checkDatabase(),
            $this->checkRedis(),
            $elasticsearch,
        ];

        // Every remaining check queries Elasticsearch. Running them while it is
        // unreachable turns one real failure into a dozen misleading ones.
        if ($elasticsearch['status'] === HealthStatus::OK) {
            $checks = array_merge($checks, [
                $this->checkUnindexedAccounts(),
                $this->checkIndexDrift(),
                $this->checkRecentPostIndexed(),
                $this->checkOrphanedDocuments(),
                $this->checkCanonicalSearch(),
                $this->checkStudioDocumentsCarryClaimFlag(),
            ]);
        }

        $checks[] = $this->checkQueueDepth();
        $checks[] = $this->checkFailedJobs();
        $checks[] = $this->checkClaimedStudiosHaveOwners();

        return $this->summarise($checks);
    }

    private function checkDatabase(): array
    {
        return $this->attempt('database', function () {
            DB::select('select 1');

            return $this->ok('database', 'Reachable.');
        });
    }

    private function checkRedis(): array
    {
        return $this->attempt('redis', function () {
            Redis::connection()->ping();

            return $this->ok('redis', 'Reachable.');
        }, 'Queued indexing stops silently when Redis is unreachable.');
    }

    private function checkElasticsearch(): array
    {
        return $this->attempt('elasticsearch', function () {
            $this->countDocuments($this->indexFor(new Tattoo()));

            return $this->ok('elasticsearch', 'Reachable and authenticated.');
        });
    }

    /**
     * Verified artist and studio accounts absent from the artists index.
     *
     * This is the check the rest exist to support. A studio signed up, nothing
     * indexed it, and it stayed invisible with no error anywhere.
     */
    private function checkUnindexedAccounts(): array
    {
        return $this->attempt('unindexed_accounts', function () {
            $cutoff = Carbon::now()->subMinutes((int) config('health.index_grace_minutes'));

            $expected = User::query()
                ->whereIn('type_id', [UserTypes::ARTIST_TYPE_ID, UserTypes::STUDIO_TYPE_ID])
                ->whereNotNull('email_verified_at')
                ->where('email_verified_at', '<=', $cutoff)
                ->orderByDesc('id')
                ->limit((int) config('health.sample_size'))
                ->pluck('id')
                ->all();

            if (empty($expected)) {
                return $this->ok('unindexed_accounts', 'No verified accounts to check.');
            }

            $missing = array_values(array_diff($expected, $this->documentIdsPresent(
                $this->indexFor(new Artist()),
                $expected
            )));

            if (empty($missing)) {
                return $this->ok(
                    'unindexed_accounts',
                    'All verified artist and studio accounts are indexed.',
                    ['checked' => count($expected)]
                );
            }

            return $this->fail(
                'unindexed_accounts',
                HealthStatus::CRITICAL,
                count($missing) . ' verified account(s) missing from the artists index. '
                . 'They will not appear in search. Usually a queue worker that is not running.',
                ['missing_ids' => array_slice($missing, 0, 10), 'checked' => count($expected)]
            );
        });
    }

    /**
     * Document counts against the database, using the same predicate that
     * decides what gets indexed. Comparing against a plain row count would
     * report drift forever.
     */
    private function checkIndexDrift(): array
    {
        return $this->attempt('index_drift', function () {
            $expectations = [
                'tattoos' => [
                    'index' => $this->indexFor(new Tattoo()),
                    'database' => Tattoo::query()->count(),
                    'optional' => false,
                ],
                'artists' => [
                    'index' => $this->indexFor(new Artist()),
                    'database' => User::query()
                        ->whereIn('type_id', [UserTypes::ARTIST_TYPE_ID, UserTypes::STUDIO_TYPE_ID])
                        ->whereNotNull('email_verified_at')
                        ->count(),
                    'optional' => false,
                ],
                // Studio browsing reads the artists index, filtered by type, so
                // a studios index is not currently required. Marked optional
                // until it is decided whether studios get one of their own.
                'studios' => [
                    'index' => $this->indexFor(new Studio()),
                    'database' => Studio::query()->count(),
                    'optional' => true,
                ],
            ];

            $statuses = [];
            $detail = [];

            foreach ($expectations as $label => $expectation) {
                if (! $this->elastic->indexExists($expectation['index'])) {
                    if ($expectation['optional']) {
                        $detail[$label] = 'no index, not required';
                        continue;
                    }

                    $statuses[] = HealthStatus::CRITICAL;
                    $detail[$label] = 'index missing';
                    continue;
                }

                $indexed = $this->countDocuments($expectation['index']);
                $database = $expectation['database'];
                $difference = abs($indexed - $database);

                $percent = $database > 0 ? ($difference / $database) * 100 : ($indexed > 0 ? 100 : 0);
                $status = $this->driftStatus($difference, $percent);

                $statuses[] = $status;
                $detail[$label] = [
                    'indexed' => $indexed,
                    'database' => $database,
                    'difference' => $difference,
                ];
            }

            $worst = HealthStatus::worst($statuses);

            if ($worst === HealthStatus::OK) {
                return $this->ok('index_drift', 'Document counts match the database.', $detail);
            }

            return $this->fail(
                'index_drift',
                $worst,
                'Index counts have drifted from the database. Stale documents from deleted rows '
                . 'are the usual cause, and elastic:reset for the affected model clears them.',
                $detail
            );
        });
    }

    /**
     * The newest posts carry their images in the index.
     *
     * A tattoo used to be indexed before its images were attached, which left
     * the post in search with an empty gallery until something reindexed it.
     */
    private function checkRecentPostIndexed(): array
    {
        return $this->attempt('recent_post_indexed', function () {
            $cutoff = Carbon::now()->subMinutes((int) config('health.index_grace_minutes'));

            $recent = Tattoo::query()
                ->where('created_at', '>=', Carbon::now()->subDay())
                ->where('created_at', '<=', $cutoff)
                ->orderByDesc('id')
                ->limit(10)
                ->pluck('id')
                ->all();

            if (empty($recent)) {
                return $this->ok('recent_post_indexed', 'No recent posts to check.');
            }

            $documents = $this->documents($this->indexFor(new Tattoo()), $recent);

            $missing = array_values(array_diff($recent, array_keys($documents)));
            $withoutImages = [];

            foreach ($documents as $id => $source) {
                if (empty($source['images'])) {
                    $withoutImages[] = $id;
                }
            }

            if (empty($missing) && empty($withoutImages)) {
                return $this->ok(
                    'recent_post_indexed',
                    'Recent posts are indexed with their images.',
                    ['checked' => count($recent)]
                );
            }

            return $this->fail(
                'recent_post_indexed',
                HealthStatus::WARN,
                'Recent posts are missing from the index or indexed without images.',
                [
                    'missing_ids' => array_slice($missing, 0, 10),
                    'without_images' => array_slice($withoutImages, 0, 10),
                ]
            );
        });
    }

    /**
     * Documents whose database row is gone. These keep deleted accounts and
     * posts visible in search.
     */
    private function checkOrphanedDocuments(): array
    {
        return $this->attempt('orphaned_documents', function () {
            $sample = (int) config('health.sample_size');

            $targets = [
                'tattoos' => [$this->indexFor(new Tattoo()), Tattoo::query()],
                'studios' => [$this->indexFor(new Studio()), Studio::query()],
                'artists' => [
                    $this->indexFor(new Artist()),
                    Artist::withoutGlobalScope(ArtistScope::class),
                ],
            ];

            $orphans = [];

            foreach ($targets as $label => [$index, $query]) {
                if (! $this->elastic->indexExists($index)) {
                    continue;
                }

                $ids = $this->recentDocumentIds($index, $sample);

                if (empty($ids)) {
                    continue;
                }

                $present = $query->whereIn('id', $ids)->pluck('id')->all();
                $missing = array_values(array_diff($ids, $present));

                if (! empty($missing)) {
                    $orphans[$label] = array_slice($missing, 0, 10);
                }
            }

            if (empty($orphans)) {
                return $this->ok('orphaned_documents', 'No orphaned documents in the sample.');
            }

            return $this->fail(
                'orphaned_documents',
                HealthStatus::WARN,
                'Documents remain for rows that no longer exist, so deleted records are still '
                . 'visible in search.',
                $orphans
            );
        });
    }

    /**
     * A query that must always return something. Counts can look correct while
     * a broken mapping or analyzer makes the index unsearchable.
     */
    private function checkCanonicalSearch(): array
    {
        return $this->attempt('canonical_search', function () {
            $term = (string) config('health.canonical_search_term');

            $response = Elastic::search([
                'index' => $this->indexFor(new Tattoo()),
                'body' => [
                    'size' => 0,
                    'query' => [
                        'multi_match' => [
                            'query' => $term,
                            'fields' => ['title', 'description', 'tags.name', 'styles.name'],
                        ],
                    ],
                ],
            ])->asArray();

            $hits = (int) data_get($response, 'hits.total.value', 0);

            if ($hits > 0) {
                return $this->ok('canonical_search', 'Search returns results.', ['hits' => $hits]);
            }

            return $this->fail(
                'canonical_search',
                HealthStatus::CRITICAL,
                'The canonical search returned nothing. The index may be present but unsearchable, '
                . 'which a document count will not reveal.',
                ['term' => $term]
            );
        });
    }

    /**
     * Studio accounts in the artists index carry is_claimed. Without it the
     * studio card falls back to its unclaimed layout and the studio looks like
     * it never joined.
     */
    private function checkStudioDocumentsCarryClaimFlag(): array
    {
        return $this->attempt('studio_claim_flag', function () {
            $missing = $this->countDocuments($this->indexFor(new Artist()), [
                'bool' => [
                    'must' => [['term' => ['type' => UserTypes::STUDIO]]],
                    'must_not' => [['exists' => ['field' => 'is_claimed']]],
                ],
            ]);

            if ($missing === 0) {
                return $this->ok('studio_claim_flag', 'Studio documents carry is_claimed.');
            }

            return $this->fail(
                'studio_claim_flag',
                HealthStatus::WARN,
                $missing . ' studio document(s) missing is_claimed. Those studios render as if they '
                . 'have not joined. Reindexing the affected accounts restores the field.',
                ['count' => $missing]
            );
        });
    }

    private function checkQueueDepth(): array
    {
        return $this->attempt('queue_depth', function () {
            $statuses = [];
            $detail = [];

            foreach ((array) config('health.queue.names') as $name) {
                $depth = Queue::size($name);
                $detail[$name] = $depth;

                if ($depth >= (int) config('health.queue.critical_depth')) {
                    $statuses[] = HealthStatus::CRITICAL;
                } elseif ($depth >= (int) config('health.queue.warn_depth')) {
                    $statuses[] = HealthStatus::WARN;
                } else {
                    $statuses[] = HealthStatus::OK;
                }
            }

            $worst = HealthStatus::worst($statuses);

            if ($worst === HealthStatus::OK) {
                return $this->ok('queue_depth', 'Queues are draining.', $detail);
            }

            return $this->fail(
                'queue_depth',
                $worst,
                'Queued work is backing up. Indexing runs through these queues, so new signups and '
                . 'posts stop reaching search while they are stalled.',
                $detail
            );
        });
    }

    private function checkFailedJobs(): array
    {
        return $this->attempt('failed_jobs', function () {
            $failed = DB::table('failed_jobs')
                ->where('failed_at', '>=', Carbon::now()->subHour())
                ->count();

            if ($failed >= (int) config('health.queue.failed_critical')) {
                $status = HealthStatus::CRITICAL;
            } elseif ($failed >= (int) config('health.queue.failed_warn')) {
                $status = HealthStatus::WARN;
            } else {
                return $this->ok('failed_jobs', 'No jobs failed in the last hour.');
            }

            return $this->fail(
                'failed_jobs',
                $status,
                $failed . ' job(s) failed in the last hour.',
                ['count' => $failed]
            );
        });
    }

    /**
     * A claimed studio with no owner cannot be claimed again, because the
     * Google lookup skips anything already marked claimed.
     */
    private function checkClaimedStudiosHaveOwners(): array
    {
        return $this->attempt('claimed_studios_have_owners', function () {
            // Demo studios are ownerless by design and would keep this check
            // permanently warning, which is how an ops channel gets muted.
            $orphaned = Studio::query()
                ->where('is_claimed', true)
                ->whereNull('owner_id')
                ->where(function ($query) {
                    $query->where('is_demo', false)->orWhereNull('is_demo');
                })
                ->count();

            if ($orphaned === 0) {
                return $this->ok('claimed_studios_have_owners', 'Every claimed studio has an owner.');
            }

            return $this->fail(
                'claimed_studios_have_owners',
                HealthStatus::WARN,
                $orphaned . ' studio(s) are marked claimed with no owner. They cannot be claimed by '
                . 'anyone and will not resurface through the Google listing.',
                ['count' => $orphaned]
            );
        });
    }

    private function driftStatus(int $difference, float $percent): string
    {
        if ($difference < (int) config('health.drift.minimum_documents')) {
            return HealthStatus::OK;
        }

        if ($percent >= (float) config('health.drift.critical_percent')) {
            return HealthStatus::CRITICAL;
        }

        if ($percent >= (float) config('health.drift.warn_percent')) {
            return HealthStatus::WARN;
        }

        return HealthStatus::OK;
    }

    private function indexFor($model): string
    {
        return $model->getIndexConfigurator()->getName();
    }

    /**
     * All Elasticsearch reads go through the Elastic client rather than
     * ElasticService::post(). That method builds its own URL from
     * elastic.client.base_url, which carries the scheme from the host env var
     * and sends no API key, so it cannot reach a managed cluster.
     */
    private function countDocuments(string $index, array $query = []): int
    {
        $params = ['index' => $index];

        if ($query !== []) {
            $params['body'] = ['query' => $query];
        }

        return (int) data_get(Elastic::count($params)->asArray(), 'count', 0);
    }

    /**
     * Which of the given ids have a document, without pulling their contents.
     */
    private function documentIdsPresent(string $index, array $ids): array
    {
        $response = Elastic::search([
            'index' => $index,
            'body' => [
                'size' => count($ids),
                '_source' => false,
                'query' => ['ids' => ['values' => array_map('strval', $ids)]],
            ],
        ])->asArray();

        return array_map(
            static fn ($hit) => (int) $hit['_id'],
            data_get($response, 'hits.hits', [])
        );
    }

    /**
     * Documents for the given ids, keyed by id.
     */
    private function documents(string $index, array $ids): array
    {
        $response = Elastic::search([
            'index' => $index,
            'body' => [
                'size' => count($ids),
                'query' => ['ids' => ['values' => array_map('strval', $ids)]],
            ],
        ])->asArray();

        $documents = [];

        foreach (data_get($response, 'hits.hits', []) as $hit) {
            $documents[(int) $hit['_id']] = $hit['_source'] ?? [];
        }

        return $documents;
    }

    private function recentDocumentIds(string $index, int $size): array
    {
        $response = Elastic::search([
            'index' => $index,
            'body' => [
                'size' => $size,
                '_source' => false,
                'sort' => [['_doc' => 'asc']],
            ],
        ])->asArray();

        return array_map(
            static fn ($hit) => (int) $hit['_id'],
            data_get($response, 'hits.hits', [])
        );
    }

    /**
     * Run a check so a thrown exception is reported as that check failing
     * rather than taking the whole run down.
     */
    private function attempt(string $name, callable $check, ?string $meaning = null): array
    {
        try {
            return $check();
        } catch (Throwable $e) {
            return $this->fail(
                $name,
                HealthStatus::CRITICAL,
                trim(($meaning ? $meaning . ' ' : '') . 'Check failed: ' . $e->getMessage())
            );
        }
    }

    private function ok(string $name, string $message, array $detail = []): array
    {
        return [
            'name' => $name,
            'status' => HealthStatus::OK,
            'message' => $message,
            'detail' => $detail,
        ];
    }

    private function fail(string $name, string $status, string $message, array $detail = []): array
    {
        return [
            'name' => $name,
            'status' => $status,
            'message' => $message,
            'detail' => $detail,
        ];
    }

    private function summarise(array $checks): array
    {
        return [
            'status' => HealthStatus::worst(array_column($checks, 'status')),
            'checked_at' => Carbon::now()->toIso8601String(),
            'checks' => $checks,
        ];
    }
}
