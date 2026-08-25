<?php


namespace App\Services;


use App\Exceptions\ElasticException;
use App\Exceptions\ItemNotFoundException;
use App\Models\Artist;
use App\Models\User;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use App\Util\JSON;
use App\Util\StringToModel;
use Larelastic\Elastic\Facades\Elastic;
use Larelastic\Elastic\Payloads\RawPayload;

class ElasticService
{
    protected $client;
    private $snapshot_repo;

    protected $elastic_index;
    protected $elastic_index_write;

    /**
     * ElasticService constructor.
     */
    public function __construct()
    {
        $this->client = new Client();
        $isPwProtected = config('elastic.client.password');
        $this->url = !empty($isPwProtected) ?
            config('elastic.client.auth_string') :
            config('elastic.client.base_url');
        $this->snapshot_repo = config('elastic.snapshot_repo');
        $this->elastic_index = config('elastic.client.index');
        $this->elastic_index_write = config('elastic.client.index') . "_write";
    }

    public function count($query)
    {
        try {
            $params = [
                'index' => $this->elastic_index,
                'body' =>  $query
            ];

            $response = Elastic::count($params)->asArray();

            if (isset($response['count'])) {
                return $response['count'];
            } else {
                throw new ElasticException("No results returned for query.");
            }

        } catch (Exception $e) {
            throw new ElasticException("Unable to search using provided query: " . $e->getMessage());
        }
    }

    //search index with valid syntax
    public function search($query)
    {
        try {
            $params = [
                'index' => $this->elastic_index,
                'body' =>  $query
            ];

            $response = Elastic::search($params)->asArray();

            if (isset($response['hits']['hits']) && $response['hits']['total']['value'] > 0) {
                return collect($response['hits']['hits']);
            } else {
                throw new ElasticException("No results returned for query.");
            }

        } catch (Exception $e) {
            throw new ElasticException("Unable to search using provided query: " . $e->getMessage());
        }
    }

    public function getById($id)
    {
        if(!numericValue($id)) {
            $field = 'slug';
        } else {
            $field = 'id';
        }

        try {
            $params = [
                'index' => $this->elastic_index,
                'body'  => [
                    'query' => [
                        'match' => [
                            $field => $id
                        ]
                    ]
                ]
            ];

            $response = Elastic::search($params)->asArray();

            if(isset($response['hits']['hits']) && $response['hits']['total']['value'] > 0){
                return collect($response['hits']['hits'])->first();
            } else {
                throw new ItemNotFoundException("Id $id not found in product index");
            }

        } catch (Exception $e) {
            throw new ItemNotFoundException("Id $id not found in product index");
        }
    }

    public function getByIds($ids, $indexName)
    {
        try {
            $query = [
                "ids" => [
                    "values" => $ids
                ]
            ];

            $params = [
                "query" => $query,
                "size" => count($ids)
            ];

            $response = $this->post("/$indexName/_search", $params);

            return $response['hits']['hits'] ?? [];

        } catch (Exception $e) {
            Log::error("Unable to get data for ". implode($ids), [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return [];
        }
    }

    public function post($path, $params = null)
    {
        if ($params != null) {
            $body = JSON::objectToString($params);
        } else {
            $body = null;
        }

        $response = $this->client->post(
            $this->url . $path,
            [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => $body
            ]
        );

        return JSON::stringToArray($response->getBody()->getContents());
    }

    /**
     * Rebuild the given ids for a model.
     *
     * @param  array|\Illuminate\Support\Collection $ids
     * @param  string|Model $model Short name ("Artist"), class name or instance
     * @return array
     */
    public function rebuild($ids, $model): array
    {
        set_time_limit(1500);
        try {
            $ids = collect($ids)->values();
            $count = $ids->count();

            Log::debug("rebuilding $count records");

            $instance = $this->resolveModel($model);
            $modelClass = get_class($instance);

            // The index membership rule lives in searchableQuery(), not in the
            // model's default query. Artist carries a global scope pinning it to
            // type_id = 2, while its index is built from artists AND studio
            // accounts, so a plain whereIn() silently misses every studio.
            $query = method_exists($instance, 'searchableQuery')
                ? $instance->searchableQuery()
                : $modelClass::query();

            $results = $query->whereIn('id', $ids)->get();

            if ($results->isNotEmpty()) {
                $results->searchable();
            }

            $missing = $ids->diff($results->pluck('id'))->values();
            $removed = $this->removeFromIndex($missing, $this->indexFor($instance));

            // Throwable, not Exception. An unresolvable model raises \Error,
            // which used to escape this catch and reach the browser as a 500.
        } catch (\Throwable $e) {
            Log::error(
                'Failed to index.',
                [
                    'error' => $e->getMessage(),
                ]
            );
            return [
                'status' => false,
                'message' => $e->getMessage()
            ];
        }

        return [
            'status' => true,
            'requested' => $count,
            'indexed' => $results->count(),
            'removed' => $removed,
            'missing_ids' => $missing->all(),
        ];
    }

    /**
     * Accept a short name ("Artist"), a class name or an instance.
     * Short names arrive from the admin panel and artisan commands.
     */
    private function resolveModel($model): Model
    {
        if ($model instanceof Model) {
            return $model;
        }

        if (is_string($model) && class_exists($model)) {
            return new $model;
        }

        return StringToModel::convert($model);
    }

    /**
     * The index a model actually writes to. Falls back to the configured
     * default only for models that are not searchable.
     */
    private function indexFor(Model $instance): string
    {
        return method_exists($instance, 'searchableAs')
            ? $instance->searchableAs()
            : $this->elastic_index;
    }

    /**
     * Drop ids that no longer exist in the database from their own index.
     */
    private function removeFromIndex($ids, string $index): int
    {
        $removed = 0;

        foreach ($ids as $id) {
            try {
                Elastic::delete([
                    'index' => $index,
                    'id' => $id
                ]);
                $removed++;
            } catch (Exception $e) {
                //if it wasn't in the index and we tried to delete it, no biggie, no need to log
                if ($e->getCode() != 404) {
                    Log::error(
                        "Failed to delete inactive item $id from $index.",
                        [
                            'error' => $e->getMessage(),
                        ]
                    );
                }
            }
        }

        return $removed;
    }

    protected function getMaxAlias(string $index)
    {
        $currentAliasResponse = $this->client->get($this->url . '/_aliases?pretty=true');
        $response = JSON::objectToArray($currentAliasResponse->getBody()->getContents());

        //aliases should increment by v1, v2, v3 etc. get the most recent we've used.
        $maxAlias = collect($response)->keys()->filter(function ($item) use ($index) {
            if (str_contains($item, $index . "_v")) {
                return $item;
            };
        });

        $maxAlias = $maxAlias->sortDesc()->first();

        if (!$maxAlias) {
            return $index . "_v2";
        } else {
            return preg_replace_callback("|(\d+)|", function ($matches) {
                return $matches[0] + 1;
            }, $maxAlias);
        }
    }

    public function indexExists($index): bool
    {
        $params = ['index' => $index];
        return Elastic::indices()->exists($params)->asBool();
    }

    public function deleteIndex($indexName, array $indices = [])
    {
        foreach ($indices as $index) { //if we are restoring we need to remove any duplicates between snap and existing
            if (strpos($index, $indexName) !== false ||
                strpos($index, 'kibana') !== false) {
                $params = ['index' => $index];
                if (Elastic::indices()->exists($params)->asBool()) {
                    Log::info("deleting the " . $index . " index!");
                    Elastic::indices()->delete($params);
                }
            }
        }
    }

    public function translateQuery($params)
    {
        $query = $this->buildElasticQuery($params);

        return $query;
    }

    private function buildElasticQuery(Model $model, $params)
    {
        $query = $model::search(); //TODO build in flexibility for future models

        foreach ($params as $operator => $args) {
            switch ($operator) {
                case 'where':
                case 'whereNot':
                    foreach ($this->adjustArrayDepth($args) as $array) {
                        foreach ($array as $key => $value) {
                            $query->{$operator}($this->isValidElasticField($key), $value);
                        }
                    }
                    break;
                case 'orWhere':
                    foreach ($this->adjustArrayDepth($args) as $array) {
                        foreach ($array as $key => $value) {
                            $clauses[] = [$this->isValidElasticField($key), '=', $value];
                        }
                    }
                    $query->orWhere($clauses);
                    break;
                case 'whereExists':
                case 'whereNotExists':
                    $operator = $operator == 'whereNotExists' ? 'whereNot' : 'where';
                    $query->{$operator}($this->isValidElasticField($args), 'exists', "");
                    break;
                case 'whereText':
                case 'whereTextOrdered':
                    $bool = $operator == 'whereTextOrdered';
                    foreach ($this->adjustArrayDepth($args) as $array) {
                        foreach ($array as $key => $value) {
                            $query->whereText($this->isValidElasticField($key), $value, $bool);
                        }
                    }
                    break;
                case 'whereBetween':
                    $field = $args['field'];
                    $values = $args['values'];
                    $query->where($this->isValidElasticField($field), "between", $values);
                    break;
                case 'whereRange':
                    $operator = $args['operator'];
                    unset($args['operator']);
                    $query->where($this->isValidElasticField(key($args)), $operator, $args[key($args)]);
                    break;
                case 'wherePrefixAll':
                case 'wherePrefixAny':
                    $bool = $operator == 'wherePrefixAll';
                    $anyOrAll = $operator == 'wherePrefixAll' ? 'all_of' : 'any_of';
                    foreach ($args as $key => $value) {
                        $query->wherePrefix($this->isValidElasticField($key), $value, $anyOrAll, $bool);
                    }
                    break;
                case 'select':
                    $query->_source = $args;
                    break;
                case 'size':
                    $query->take = $args;
                    break;
                case 'sort':
                    foreach ($this->adjustArrayDepth($args) as $array) {
                        foreach ($array as $key => $value) {
                            $query->sort($this->isValidElasticField($key), $value);
                        }
                    }
                    break;
                default:
                    break;
            }
        }
        return $query;
    }

    //allow some flexibility for user to send an array or not
    private function adjustArrayDepth($value)
    {
        return isset($value[0]) ? $value : [$value];
    }

    //verify elastic field sent exists in index
    private function isValidElasticField($value)
    {
        if(!in_array($value,ValidElasticFields::VALID_FIELDS)){
            throw new \InvalidArgumentException('"' . $value . '" is not a valid Elastic field!');
        }

        return $value;
    }

    /**
     * @throws Exception
     */
    protected function updateTargetIndexMapping($max_alias, Model $sourceModel)
    {
        try {
            $sourceIndexConfigurator = $sourceModel->getIndexConfigurator();
            $targetIndex = $max_alias;
            $mappings = array_merge_recursive(
                $sourceIndexConfigurator->getDefaultMapping(),
                $sourceIndexConfigurator->getMappings()
            );
            $payload = (new RawPayload())
                ->set('index', $targetIndex)
                ->set('body', $mappings)
                ->get();
            Elastic::indices()->putMapping($payload);
        } catch (\Exception $e) {
            Log::error("Unable to update target index mapping for $max_alias",
                [
                    'error' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile()
                ]);
            throw $e;
        }
        return true;
    }

    /**
     * Create an alias for the target index.
     *
     * @param string $name
     * @return bool
     * @throws Exception
     */
    protected function createAliasForTargetIndex($newIndex, $currentIndex, array $aliases)
    {
        try {
            foreach ($aliases as $alias) {

                if ($this->isAliasExists($alias)) {
                    Log::info("deleting alias $alias");
                    $this->deleteAlias($alias, $currentIndex);
                }

                $payload = (new RawPayload())
                    ->set('index', $newIndex)
                    ->set('name', $alias)
                    ->get();

                Log::info("adding alias $alias to $newIndex");

                try {
                    Elastic::indices()->putAlias($payload);

                } catch (Exception $e) {
                    Log::error("Unable to put alias $alias on target index $newIndex",
                        [
                            'error' => $e->getMessage(),
                            'line' => $e->getLine(),
                            'file' => $e->getFile()
                        ]);

                    //try to replace the deleted alias if we werent able to set it on the new index
                    foreach ($aliases as $replace_alias) { //ES doesnt care if you "reassign" so do both to be sure

                        Log::info("replacing alias $replace_alias to $currentIndex");

                        $payload = (new RawPayload())
                            ->set('index', $currentIndex)
                            ->set('name', $replace_alias)
                            ->get();
                        Elastic::indices()->putAlias($payload);
                    }
                    throw $e;
                }

            }
            Log::info("The read/write alias for the $newIndex index was created.");
        } catch (\Exception $e) {
            Log::error("Unable to create alias for target index $newIndex",
                [
                    'error' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile()
                ]);
            throw $e;
        }
        return true;
    }

    public function getCurrentIndex($index)
    {
        $currentAliasResponse = $this->client->get($this->url . '/_alias/' . $index);
        $response = JSON::stringToArray($currentAliasResponse->getBody()->getContents());

        $currentIndex = collect($response)->keys()->first() ?? $index;

        return $currentIndex;
    }

    /**
     * @throws Exception
     */
    public function dropCurrentIndex($index)
    {
        try {
            $params = [
                'index' => $index
            ];
            Elastic::indices()->delete($params);
        } catch (\Exception $e) {
            Log::error("Unable to drop index $index",
                [
                    'error' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile()
                ]);
            throw $e;
        }
    }

    /**
     * @throws GuzzleException
     */
    public function _reindex($sourceIndex, $destIndex)
    {
        try {
            $params = [
                'source' => [
                    'index' => $sourceIndex,
                ],
                'dest' => [
                    'index' => $destIndex
                ]
            ];
            $response = $this->client->post(
                $this->url . '/_reindex?wait_for_completion=false', //avoid timeouts
                [
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => json_encode($params)
                ]
            );

            sleep(300);//we have set this as a task, 5 min should be enough to finish it
        } catch (\Exception $e) {
            Log::error("Unable to run _reindex to $destIndex",
                [
                    'error' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile()
                ]);
            throw $e;
        }
        return true;
    }

    /**
     * Check if an alias exists.
     *
     * @param string $name
     * @return bool
     */
    protected function isAliasExists($name)
    {
        $payload = (new RawPayload())
            ->set('name', $name)
            ->get();

        return Elastic::indices()->existsAlias($payload)->asBool();
    }

    /**
     * Delete an alias.
     *
     * @param string $name
     * @return bool
     * @throws Exception
     */
    protected function deleteAlias($name, $index)
    {
        try {
            $deletePayload = (new RawPayload())
                ->set('index', $index)
                ->set('name', $name)
                ->get();
            Elastic::indices()->deleteAlias($deletePayload);
        } catch (\Exception $e) {
            Log::error("Unable to delete alias $name",
                [
                    'error' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile()
                ]);
            throw $e;
        }
        return true;
    }

    /**
     * Delete documents by their IDs from an index, in chunks.
     *
     * @param array $ids Document IDs to delete
     * @param string $index Index name (e.g., 'tattoos', 'artists')
     * @param int $chunkSize Number of IDs per bulk request
     * @return array Response with total deleted count
     */
    public function deleteByIds(array $ids, string $index, int $chunkSize = 200): array
    {
        if (empty($ids)) {
            return ['status' => true, 'deleted' => 0];
        }

        Log::info("deleteByIds: Sending " . count($ids) . " documents to _delete_by_query", [
            'index' => $index,
            'ids' => $ids,
        ]);

        $totalDeleted = 0;

        foreach (array_chunk($ids, $chunkSize) as $chunk) {
            try {
                $params = [
                    'query' => [
                        'ids' => [
                            'values' => array_map('strval', $chunk),
                        ],
                    ],
                ];

                $response = $this->post("/{$index}/_delete_by_query", $params);
                $totalDeleted += $response['deleted'] ?? 0;
            } catch (Exception $e) {
                Log::error("Failed to delete chunk of IDs from ES", [
                    'index' => $index,
                    'chunk_size' => count($chunk),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info("Deleted documents by IDs", [
            'index' => $index,
            'requested' => count($ids),
            'deleted' => $totalDeleted,
        ]);

        return ['status' => true, 'deleted' => $totalDeleted];
    }

    /**
     * Delete all documents matching a field/value query.
     *
     * @param string $field The field to match on (e.g., 'artist_id')
     * @param mixed $value The value to match
     * @param string|null $index Optional index name, defaults to configured index
     * @return array Response with deleted count
     * @throws ElasticException
     */
    public function deleteByQuery(string $field, $value, ?string $index = null): array
    {
        $targetIndex = $index ?? $this->elastic_index;

        try {
            $params = [
                'query' => [
                    'term' => [
                        $field => $value
                    ]
                ]
            ];

            $response = $this->post("/{$targetIndex}/_delete_by_query", $params);

            Log::info("Deleted documents by query", [
                'index' => $targetIndex,
                'field' => $field,
                'value' => $value,
                'deleted' => $response['deleted'] ?? 0
            ]);

            return [
                'status' => true,
                'deleted' => $response['deleted'] ?? 0
            ];

        } catch (Exception $e) {
            Log::error("Unable to delete by query", [
                'index' => $targetIndex,
                'field' => $field,
                'value' => $value,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            throw new ElasticException("Unable to delete documents by query: " . $e->getMessage());
        }
    }
}
