<?php


namespace App\Http\Controllers;


use App\Jobs\ElasticRebuildJob;
use App\Enums\QueueNames;
use App\Http\Requests\ElasticQueryTranslateRequest;
use App\Http\Requests\MigrateElasticAliasRequest;
use App\Services\ElasticService;
use App\Util\StringToModel;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use App\Util\JSON;

class ElasticController
{
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
            } else {
                $model = StringToModel::convert($model);
                $this->elasticService->rebuild($ids, $model);
            }
        } catch (Exception $e) {
            return response()->json(['message' => 'Error updating item(s). Message: ' . $e->getMessage()], 500);
        }
        return response()->json(['message' => 'Rebuild completed']);
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

        } catch (Exception $e) {
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
        $flush = $request->get('flush', true); // Default to flushing to remove stale documents

        $class = 'App\\Models\\' . $model;

        try {
            // Flush the index first to remove documents that no longer exist in DB
            if ($flush) {
                Log::info("Flushing index for {$model} before reindex");
                Artisan::call('scout:flush', [
                    'model' => $class,
                ]);
            }

            // Import all documents from the database
            Artisan::call('scout:import', [
                'model' => $class,
            ]);

            $message = $flush
                ? "Reindex completed for {$model} (index flushed and rebuilt)"
                : "Reindex completed for {$model} (documents updated/added only)";

            return response()->json(['message' => $message]);
        } catch (\Exception $e) {
            Log::error("Reindex failed for {$model}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'Error during reindex: ' . $e->getMessage()], 500);
        }
    }

    //TODO wrap this route in a sanctum role-based restriction ASAP
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
            $instance = StringToModel::convert($model);
            $indexName = $instance->getIndexConfigurator()->getName();
            $modelClass = get_class($instance);

            $countResponse = $this->elasticService->post("/{$indexName}/_count", [
                'query' => ['match_all' => (object)[]]
            ]);
            $totalEs = $countResponse['count'] ?? 0;

            $searchResponse = $this->elasticService->post("/{$indexName}/_search", [
                '_source' => false,
                'query' => ['match_all' => (object)[]],
                'size' => $totalEs,
            ]);

            $esIds = collect($searchResponse['hits']['hits'] ?? [])->pluck('_id')->map(fn($id) => (int) $id)->toArray();

            $existingIds = collect();
            foreach (array_chunk($esIds, 1000) as $chunk) {
                $found = $modelClass::whereIn('id', $chunk)->pluck('id');
                $existingIds = $existingIds->merge($found);
            }

            $orphanIds = collect($esIds)->diff($existingIds)->values()->toArray();

            return response()->json([
                'es_total' => $totalEs,
                'db_total' => $existingIds->count(),
                'orphan_count' => count($orphanIds),
                'orphan_ids' => $orphanIds,
            ]);
        } catch (Exception $e) {
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
            $ids = $request->get('ids', []);
            $instance = StringToModel::convert($model);
            $indexName = $instance->getIndexConfigurator()->getName();

            $response = $this->elasticService->post("/{$indexName}/_delete_by_query", [
                'query' => ['ids' => ['values' => $ids]]
            ]);

            return response()->json([
                'deleted' => $response['deleted'] ?? 0,
            ]);
        } catch (Exception $e) {
            Log::error("Failed to delete orphans", [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json(['message' => 'Error deleting orphans: ' . $e->getMessage()], 500);
        }
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
