<?php


namespace App\Http\Controllers;


use App\Jobs\ElasticRebuildJob;
use App\Enums\QueueNames;
use App\Http\Requests\ElasticQueryTranslateRequest;
use App\Http\Requests\MigrateElasticAliasRequest;
use App\Services\ElasticService;
use App\Util\StringToModel;
use Exception;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use App\Util\JSON;

class ElasticController
{
    /**
     * Models these endpoints will act on. Anything else is rejected before it
     * reaches a queue or an index, so a typo comes back as a clear 422 rather
     * than a class-not-found further down.
     */
    private const SEARCHABLE_MODELS = ['Artist', 'Studio', 'Tattoo'];

    /**
     * Most documents a single orphan scan will read out of an index.
     */
    private const ORPHAN_SCAN_LIMIT = 10000;

    /**
     * Share of an index a single orphan sweep may delete before it has to be
     * forced. A sweep this large means the check disagrees with how the index
     * is built, which is a mapping problem and not a cleanup job.
     */
    private const ORPHAN_DELETE_LIMIT = 0.2;

    /**
     * @var ElasticService
     */
    private $elasticService;
    /**
     * @var string
     */
    private $elastic_index;

    /**
     * ElasticController constructor.
     */
    public function __construct(ElasticService $elasticService)
    {
        $this->elasticService = $elasticService;
        $this->elastic_index = config('elastic.client.index');
    }

    public function translateQuery(ElasticQueryTranslateRequest $request)
    {
        try {
            if ($request->get('query')) {
                $params = $request->get('query');

                $query = $this->elasticService->translateQuery($params);

                return response()->json($query->getQuery());
            }
        } catch (Exception $e) {
            Log::error("Unable to translate params into elastic query", [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);
            return response("Unable to translate params into elastic query: " .$e->getMessage(), 500);
        }
    }

    /**
     * @param $id
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response
     */
    public function getById($id)
    {
        try {
            $response = $this->elasticService->getById($id);
            return response($response['_source'] ?? "", 200);
        } catch (Exception $e) {
            \Log::error("Unable to get elastic document by id $id",
                [
                    'error' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile(),
                ]
            );
            return response("Unable to get elastic document by id $id", 404);
        }
    }

    /**
     * @param MigrateElasticAliasRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function migrateAlias(MigrateElasticAliasRequest $request)
    {
        $alias = $request->get('alias');

        try {
            $this->elasticService->migrateAlias($alias);
            return response()->json(['message' => 'Alias migration queued']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error enqueuing migration for alias \'' . $alias . '\': ' . $e->getMessage()], 500);
        }
    }

    /**
     * This will BYPASS the rebuild queue and trigger an immediate rebuild. Cannot exceed count of 200.
     * @param Request $request
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\JsonResponse|\Illuminate\Http\Response
     */
    public function rebuildBypass(Request $request)
    {
        try {
            config(['scout.queue' => false]);

            $ids = $request->get('ids');
            $model = $request->get('model');

            if (count($ids) > 200) {
                return response("Count sent cannot exceed 200, please reduce the count and try again", 400);
            }

            $model = $this->resolveSearchableModel($model);
            $result = $this->elasticService->rebuild($ids, $model);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Error updating item(s). Message: ' . $e->getMessage()], 500);
        }

        if (!($result['status'] ?? false)) {
            return response()->json(['message' => 'Error updating item(s). Message: ' . ($result['message'] ?? 'unknown error')], 500);
        }

        return response()->json([
            'message' => $this->describeRebuild($result),
            'indexed' => $result['indexed'] ?? 0,
            'removed' => $result['removed'] ?? 0,
            'missing_ids' => $result['missing_ids'] ?? [],
        ]);
    }

    /**
     * Say what actually happened. A rebuild that matched nothing used to
     * report the same success message as one that reindexed every record.
     */
    private function describeRebuild(array $result): string
    {
        $requested = $result['requested'] ?? 0;
        $indexed = $result['indexed'] ?? 0;
        $removed = $result['removed'] ?? 0;
        $missing = count($result['missing_ids'] ?? []);

        if ($indexed === 0 && $requested > 0) {
            return "No records indexed. $missing of $requested ids were not found in the database"
                . ($removed > 0 ? " and $removed were removed from the index." : ".");
        }

        $message = "Rebuild completed. Indexed $indexed of $requested.";

        if ($missing > 0) {
            $message .= " $missing not found in the database"
                . ($removed > 0 ? ", $removed removed from the index." : ".");
        }

        return $message;
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function rebuild(Request $request)
    {
        try {
            $jobName = "";
            $wheres = [];
            $whereIns = [];

            $model = $request->get('model');

            // Validate before anything reaches the queue. A bad name used to
            // fail inside the worker, out of sight of whoever clicked rebuild.
            $this->resolveSearchableModel($model);

            if ($request->get('params')) {
                $jobName = "Rebuild By Query";
                $params = $request->get('params');
                foreach ($params as $param) {
                    if ($param['operator'] == "in") {
                        $whereIns[$param['field']] = $param['value'];
                    } else {
                        $wheres[] = [$param['field'], $param['operator'], $param['value']];
                    }
                }

                $ids = $this->getIdsFromQuery($wheres, $whereIns, $model); //returns as arrays of chunked 500

            } else if ($request->get('ids')) {
                $jobName = "Rebuild By Ids";
                $ids = $request->get('ids');

                $ids = collect($ids)->chunk(config('scout.chunk.searchable', 500));
            } else {
                return response()->json(['message' => 'Error updating item(s). No ids or params sent']);
            }

            if (!empty($ids) && count($ids) > 0) {
                Log::debug(sprintf("JOB LOG Sending %s to elastic", $jobName));

                foreach ($ids as $idGroup) {
                    ElasticRebuildJob::dispatch($model, $idGroup->toArray())
                        ->onQueue(QueueNames::ELASTIC_REBUILD)
                        ->onConnection('redis');
                }
            } else {
                return response()->json(['message' => 'No items updated -- no matching ids found']);
            }

        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Error updating item(s). Message: ' . $e->getMessage()], 500);
        }
        return response()->json(['message' => 'Rebuild queued']);
    }

    public function rebuildByElasticQuery(Request $request)
    {
        $jobName = "Rebuild By Elastic Query";

        try {
            if ($request->get('elastic_query')) {
                $query = $request->get('elastic_query');
                $count = $this->elasticService->count(JSON::objectToArray($query));

                $query['size'] = $count;
                $results = $this->elasticService->search(JSON::objectToArray($query));
                $ids = collect($results)->pluck('_id');

                if (!empty($ids) && count($ids) > 0) {
                    //if we have a LOT of results send them in chunks of 500
                    $ids = collect($ids)->chunk(config('scout.chunk.searchable', 500));

                    Log::debug(sprintf("COMMERCE JOB LOG Sending %s to elastic", $jobName));

                    foreach ($ids as $idGroup) {
                        ElasticRebuildJob::dispatch($idGroup->toArray())
                            ->onQueue(QueueNames::ELASTIC_REBUILD)
                            ->onConnection('redis');
                    }
                } else {
                    return response()->json(['message' => 'No items updated -- no matching ids found']);
                }
                return response()->json(['message' => 'Rebuild queued']);
            }
        } catch (Exception $e) {
            return response()->json(["message" => "Error updating item(s) via $jobName;. Message: " . $e->getMessage()], 500);
        }
    }

    public function reindex(Request $request)
    {
        $model = $request->get('model');

        try {
            $modelClass = get_class($this->resolveSearchableModel($model));

            Artisan::call('scout:import', [
                'model' => $modelClass,
            ]);

            return response()->json(['message' => 'Reindex completed for ' . class_basename($modelClass)]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error("Reindex failed for {$model}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'Error during reindex: ' . $e->getMessage()], 500);
        }
    }

    // Not currently routed. If ever exposed, it must sit behind the admin middleware.
    public function dropIndex($index)
    {
        try {
            $this->elasticService->deleteIndex([$index]);
            return response("Index deleted!", 200);
        } catch (Exception $e) {
            return response("Index not deleted " . $e->getMessage(), 500);
        }
    }

    public function findOrphans(Request $request)
    {
        try {
            $model = $request->get('model');
            $instance = $this->resolveSearchableModel($model);
            $indexName = $this->indexFor($instance);

            $countResponse = $this->elasticService->post("/{$indexName}/_count", [
                'query' => ['match_all' => (object)[]]
            ]);
            $totalEs = $countResponse['count'] ?? 0;

            $scanLimit = min($totalEs, self::ORPHAN_SCAN_LIMIT);

            $searchResponse = $this->elasticService->post("/{$indexName}/_search", [
                '_source' => false,
                'query' => ['match_all' => (object)[]],
                'size' => $scanLimit,
            ]);

            $esIds = collect($searchResponse['hits']['hits'] ?? [])->pluck('_id')->map(fn($id) => (int) $id)->toArray();

            $orphanIds = $this->orphanIds($instance, $esIds);
            $warnings = [];

            if ($totalEs > $scanLimit) {
                $warnings[] = "Only the first $scanLimit of $totalEs documents were checked. The rest were not scanned.";
            }

            if ($this->exceedsOrphanLimit(count($orphanIds), $totalEs)) {
                $warnings[] = sprintf(
                    '%d of %d documents in this index have no matching record. That is most of the index, so treat this as a mapping problem rather than a cleanup job.',
                    count($orphanIds),
                    $totalEs
                );
            }

            return response()->json([
                'es_total' => $totalEs,
                'scanned' => count($esIds),
                'db_total' => count($esIds) - count($orphanIds),
                'orphan_count' => count($orphanIds),
                'orphan_ids' => $orphanIds,
                'warnings' => $warnings,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error("Failed to find orphans", [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json(['message' => 'Error finding orphans: ' . $e->getMessage()], 500);
        }
    }

    public function deleteOrphans(Request $request)
    {
        try {
            $model = $request->get('model');
            $instance = $this->resolveSearchableModel($model);
            $indexName = $this->indexFor($instance);

            $ids = collect($request->get('ids', []))
                ->map(fn($id) => (int) $id)
                ->filter()
                ->unique()
                ->values();

            // The list comes from a findOrphans run that may be minutes old, so
            // check every id again before deleting anything.
            $orphanIds = collect($this->orphanIds($instance, $ids->all()));
            $skipped = $ids->diff($orphanIds)->values();

            if ($orphanIds->isEmpty()) {
                return response()->json([
                    'deleted' => 0,
                    'skipped' => $skipped->all(),
                    'message' => 'Nothing deleted. Every id sent still has a record in the database.',
                ]);
            }

            $countResponse = $this->elasticService->post("/{$indexName}/_count", [
                'query' => ['match_all' => (object)[]]
            ]);
            $totalEs = $countResponse['count'] ?? 0;

            if (!$request->boolean('force') && $this->exceedsOrphanLimit($orphanIds->count(), $totalEs)) {
                return response()->json([
                    'deleted' => 0,
                    'message' => sprintf(
                        'Refusing to delete %d of %d documents from the %s index in one sweep. Send force to override.',
                        $orphanIds->count(),
                        $totalEs,
                        $indexName
                    ),
                ], 409);
            }

            $response = $this->elasticService->post("/{$indexName}/_delete_by_query", [
                'query' => ['ids' => ['values' => $orphanIds->all()]]
            ]);

            Log::info("Deleted orphaned documents", [
                'index' => $indexName,
                'deleted' => $response['deleted'] ?? 0,
                'skipped' => $skipped->count(),
            ]);

            return response()->json([
                'deleted' => $response['deleted'] ?? 0,
                'skipped' => $skipped->all(),
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error("Failed to delete orphans", [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json(['message' => 'Error deleting orphans: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Ids present in the index with no matching database record.
     *
     * Uses searchableQuery() for the same reason rebuild() does. Artist is
     * scoped to type_id = 2 by default, but its index is built from artists
     * and studio accounts, so the scoped query calls every studio an orphan.
     */
    private function orphanIds(Model $instance, array $esIds): array
    {
        if (empty($esIds)) {
            return [];
        }

        $existingIds = collect();

        foreach (array_chunk($esIds, 1000) as $chunk) {
            $query = method_exists($instance, 'searchableQuery')
                ? $instance->searchableQuery()
                : get_class($instance)::query();

            $existingIds = $existingIds->merge($query->whereIn('id', $chunk)->pluck('id'));
        }

        return collect($esIds)->diff($existingIds)->values()->toArray();
    }

    /**
     * Resolve a model name from a request into an instance these endpoints
     * are allowed to touch.
     *
     * @throws InvalidArgumentException
     */
    private function resolveSearchableModel($model): Model
    {
        $name = ucfirst((string) $model);

        if (!in_array($name, self::SEARCHABLE_MODELS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown model "%s". Supported models are %s.',
                $model,
                implode(', ', self::SEARCHABLE_MODELS)
            ));
        }

        return StringToModel::convert($name);
    }

    /**
     * The index a model writes to.
     */
    private function indexFor(Model $instance): string
    {
        return method_exists($instance, 'searchableAs')
            ? $instance->searchableAs()
            : $this->elastic_index;
    }

    /**
     * True when a sweep would take out more of the index than the limit allows.
     */
    private function exceedsOrphanLimit(int $orphanCount, int $totalEs): bool
    {
        if ($orphanCount === 0 || $totalEs === 0) {
            return false;
        }

        return $orphanCount / $totalEs > self::ORPHAN_DELETE_LIMIT;
    }

    private function getIdsFromQuery($wheres, $whereIns, $model)
    {
        $instance = StringToModel::convert($model);
        ini_set('memory_limit', '2000M');
        $query = $instance::query();

        if (!empty($wheres)) {
            $query->where($wheres);
        }

        if (!empty($whereIns)) {
            foreach ($whereIns as $key => $value) {
                $query->whereIn($key, $value);
            }
        }

        try {
            $result = $query->pluck('id');
            $ids = $result->chunk(config('scout.chunk.searchable', 500));
            return $ids;
        } catch (Exception $e) {
            Log::error("Unable to process query for Rebuild by Query", [
                "error" => $e->getMessage(),
                "file" => $e->getFile(),
                "line" => $e->getLine()
            ]);
        }
        return [];
    }
}
