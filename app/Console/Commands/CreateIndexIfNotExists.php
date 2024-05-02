<?php

namespace App\Console\Commands;

use Larelastic\Elastic\Console\ElasticIndexCreateCommand;
use Larelastic\Elastic\Facades\Elastic;

class CreateIndexIfNotExists extends ElasticIndexCreateCommand
{
    protected $name = 'elastic:create-index-ifnotexists';

    /**
     * {@inheritdoc}
     */
    protected $description = 'Create an Elasticsearch index';

    public function indexExists(): bool
    {
        $model = $this->getModel();

        $index = $model->getIndexConfigurator()->getName();

        return Elastic::indices()->exists(['index' => $index]);
    }

    public function handle()
    {
        if (!$this->indexExists()) {

            $this->createIndex();

            $this->createWriteAlias();
        }
    }
}
