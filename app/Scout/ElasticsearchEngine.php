<?php

namespace App\Scout;

use App\Services\ElasticsearchService;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;

class ElasticsearchEngine extends Engine
{
    protected $elasticsearch;

    public function __construct(ElasticsearchService $elasticsearch)
    {
        $this->elasticsearch = $elasticsearch;
    }

    /**
     * Update the given model in the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function update($models)
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
     * @param  \Illuminate\Database\Eloquent\Collection  $models
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
     * @param  \Laravel\Scout\Builder  $builder
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
     * @param  \Laravel\Scout\Builder  $builder
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
     * @param  \Laravel\Scout\Builder  $builder
     * @param  array  $options
     * @return mixed
     */
    protected function performSearch(Builder $builder, array $options = [])
    {
        $index = $builder->model->searchableAs();

        $query = [
            'bool' => [
                'must' => [
                    [
                        'query_string' => [
                            'query' => $builder->query ?: '*',
                        ],
                    ],
                ],
            ],
        ];

        if ($builder->callback) {
            $query = call_user_func(
                $builder->callback,
                $query,
                $builder,
                $options
            );
        }
        
        $searchParams = [
            'from' => $options['from'] ?? 0,
            'size' => $options['limit'] ?? 10,
        ];

        $result = $this->elasticsearch->search(
            array_merge(['query' => $query], $searchParams),
            $index
        );

        return [
            'total' => $result['hits']['total']['value'] ?? 0,
            'hits' => collect($result['hits']['hits'])->map(function ($hit) {
                return [
                    'id' => $hit['_id'],
                    'score' => $hit['_score'],
                    'document' => $hit['_source'] ?? [],
                ];
            })->all(),
        ];
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
     * Map the given results to instances of the given model.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function map(Builder $builder, $results, $model)
    {
        if (count($results['hits']) === 0) {
            return $model->newCollection();
        }

        $keys = collect($results['hits'])->pluck('id')->values()->all();

        $modelInstance = $model->newQuery()->whereIn(
            $model->getKeyName(), 
            $keys
        )->get()->keyBy($model->getKeyName());

        return collect($results['hits'])->map(function ($hit) use ($modelInstance) {
            return $modelInstance[$hit['id']] ?? null;
        })->filter()->values();
    }

    /**
     * Determine if the given model uses soft deletes.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     */
    protected function usesSoftDelete($model)
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model));
    }

    /**
     * Flush all of the model's records from the engine.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
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