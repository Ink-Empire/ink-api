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
use Larelastic\Elastic\Facades\Elastic;

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

            $response = Elastic::count($params);

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

            $response = Elastic::search($params);

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

            $response = Elastic::search($params);

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
     * @param $ids
     * @return array
     */
    public function rebuild($ids, $model): array
    {
        set_time_limit(1500);
        try {
            $count = count((array)$ids);

            Log::debug("rebuilding $count products");
            
            // Handle both string class names and Model instances
            if (is_string($model)) {
                $modelClass = $model;
            } else {
                $modelClass = get_class($model);
            }
            
            $results = $modelClass::whereIn('id', $ids)->get();

            if ($results) {
                $results->searchable();
            }

            $toRemove = collect($ids)->diff($results->pluck('id')); //remove any ids not found in db

            if (count($toRemove) > 0) {
                foreach ($toRemove as $remove) {
                    try {
                        Elastic::delete(
                            [
                                'index' => $this->elastic_index,
                                'id' => $remove
                            ]
                        );
                    } catch (Exception $e) {
                        if ($e->getCode() != 404) { //if it wasn't in the index and we tried to delete it, no biggie, no need to log
                            Log::error(
                                "Failed to delete inactive item $remove.",
                                [
                                    'error' => $e->getMessage(),
                                ]
                            );
                        }
                    }
                }
            }
        } catch (\Exception $e) {
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
        ];
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
        return Elastic::indices()->exists($params);
    }

    public function deleteIndex($indexName, array $indices = [])
    {
        foreach ($indices as $index) { //if we are restoring we need to remove any duplicates between snap and existing
            if (strpos($index, $indexName) !== false ||
                strpos($index, 'kibana') !== false) {
                $params = ['index' => $index];
                if (Elastic::indices()->exists($params)) {
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
            $targetType = $sourceModel->searchableAs();
            $mappings = array_merge_recursive(
                $sourceIndexConfigurator->getDefaultMapping(),
                $sourceIndexConfigurator->getMappings()
            );
            $payload = (new RawPayload())
                ->set('index', $targetIndex)
                ->set('type', $targetType)
                ->set('include_type_name', 'true')
                ->set('body.' . $targetType, $mappings)
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

        return Elastic::indices()->existsAlias($payload);
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
}
