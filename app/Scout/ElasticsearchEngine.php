<?php

namespace App\Scout;

use App\Services\ElasticsearchService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use Larelastic\Elastic\Payloads\IndexPayload;
use Larelastic\Elastic\Facades\Elastic;

class ElasticsearchEngine extends Engine
{
    protected $elasticsearch;

    public function __construct(ElasticsearchService $elasticsearch)
    {
        $this->elasticsearch = $elasticsearch;
    }

    /**
     * Create a search index.
     *
     * @param  string  $name
     * @param  array  $options
     * @return mixed
     */
    public function createIndex($name, array $options = [])
    {
        return $this->elasticsearch->createIndex($name);
    }

    /**
     * Delete a search index.
     *
     * @param  string  $name
     * @return mixed
     */
    public function deleteIndex($name)
    {
        return $this->elasticsearch->deleteIndex($name);
    }

    /**
     * Map the given results to instances of the given model via a lazy collection.
     *
     * @param Builder $builder
     * @param  mixed  $results
     * @param Model $model
     * @return \Illuminate\Support\LazyCollection
     */
    public function lazyMap(Builder $builder, $results, $model): \Illuminate\Support\LazyCollection
    {
        if (count($results['hits']) === 0) {
            return $model->newCollection()->lazy();
        }

        $keys = collect($results['hits'])->pluck('id')->values()->all();

        $modelQuery = $model->newQuery()
            ->whereIn($model->getKeyName(), $keys);

        return $modelQuery->cursor()->filter(function ($model) use ($keys) {
            return in_array($model->getKey(), $keys);
        });
    }

    /**
     * Update the given model in the index.
     *
     * @param  Collection  $models
     * @return void
     */
    public function update($models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $index = $models->first()->searchableAs();

        if (! $this->elasticsearch->indexExists($index)) {
            $this->elasticsearch->createIndex($index);
        }

        $documents = [];

        foreach ($models as $model) {
            if ($this->usesSoftDelete($model) && $model->trashed()) {
                $this->delete(collect([$model]));
                continue;
            }

            $documents[$model->getScoutKey()] = array_merge(
                $model->toSearchableArray(), $model->scoutMetadata()
            );
        }

        if (! empty($documents)) {
            $this->elasticsearch->bulkIndex($documents, $index);
        }
    }

    /**
     * Remove the given model from the index.
     *
     * @param  Collection  $models
     * @return void
     */
    public function delete($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        $index = $models->first()->searchableAs();

        foreach ($models as $model) {
            $this->elasticsearch->deleteDocument($model->getScoutKey(), $index);
        }
    }

    /**
     * Perform the given search on the engine.
     *
     * @param Builder $builder
     * @return mixed
     */
    public function search(Builder $builder)
    {
        return $this->performSearch($builder, [
            'limit' => $builder->limit,
        ]);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param Builder $builder
     * @param  int  $perPage
     * @param  int  $page
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        return $this->performSearch($builder, [
            'from' => ($page - 1) * $perPage,
            'size' => $perPage,
        ]);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param Builder $builder
     * @param  array  $options
     * @return mixed
     */
    protected function performSearch(Builder $builder, array $options = []): mixed
    {
        $params = json_decode(json_encode($builder->getQuery()),true);

        //debug log out the actual query being run on elastic
        ray(json_encode($builder->getQuery()))->purple();

        return $this->searchRaw($builder->model, $params);

//        $index = $builder->model->searchableAs();
//
//        $query = [
//            'bool' => [
//                'must' => [
//                    [
//                        'query_string' => [
//                            'query' => $builder->query ?: '*',
//                        ],
//                    ],
//                ],
//            ],
//        ];
//
//        if ($builder->callback) {
//            $query = call_user_func(
//                $builder->callback,
//                $query,
//                $builder,
//                $options
//            );
//        }
//
//        $searchParams = [
//            'from' => $options['from'] ?? 0,
//            'size' => $options['limit'] ?? 10,
//        ];
//
//        $result = $this->elasticsearch->search(
//            array_merge(['query' => $query], $searchParams),
//            $index
//        );
//
//        return [
//            'total' => $result['hits']['total']['value'] ?? 0,
//            'hits' => collect($result['hits']['hits'])->map(function ($hit) {
//                return [
//                    'id' => $hit['_id'],
//                    'score' => $hit['_score'],
//                    'document' => $hit['_source'] ?? [],
//                ];
//            })->all(),
//        ];
    }

    /**
     * Make a raw search.
     *
     * @param Model $model
     * @param array $query
     * @return mixed
     */
    public function searchRaw(Model $model, $query)
    {
        $payload = (new IndexPayload($model->getIndexConfigurator()))
            ->setIfNotEmpty('body', $query)
            ->get();

        ray($payload)->blue();

        return Elastic::search($payload)->asArray();
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param  mixed  $results
     * @return int
     */
    public function getTotalCount($results)
    {
        return $results['total'];
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param  mixed  $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        return collect($results['hits'])->pluck('id');
    }

    /**
     *
     * @param Builder $builder
     * @param  mixed  $results
     * @param Model $model
     * @return Collection
     */
    public function map(Builder $builder, $results, $model)
    {
        //get the source object off each result set
        $data['response'] = collect($results['hits']['hits'])->map(function ($item){
            return $item['_source'];
        });

        if (isset($results["aggregations"])) {
            $data['aggs'] = $results["aggregations"] ?? null;
        }

        return $data;
    }

    /**
     * Determine if the given model uses soft deletes.
     *
     * @param Model $model
     * @return bool
     */
    protected function usesSoftDelete($model)
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model));
    }

    /**
     * Flush all of the model's records from the engine.
     *
     * @param Model $model
     * @return void
     */
    public function flush($model)
    {
        $index = $model->searchableAs();

        if ($this->elasticsearch->indexExists($index)) {
            $this->elasticsearch->deleteIndex($index);
        }
    }
}
