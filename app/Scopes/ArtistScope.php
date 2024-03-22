<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use \Illuminate\Database\Eloquent\Builder;

class ArtistScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @param  $builder
     * @param  $model
     * @return void
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where('type_id', '=', 2);
    }
}
