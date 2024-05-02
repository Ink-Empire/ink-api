<?php

namespace App\Console\Commands;

use App\Console\ElasticService;
use Larelastic\Elastic\Console\ElasticIndexDropCommand;
use Larelastic\Elastic\Facades\Elastic;
use Larelastic\Elastic\Traits\RequiresModelArgument;

class DeleteIndexIfExistsCommand extends ElasticIndexDropCommand
{
    use RequiresModelArgument;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $name = 'elastic:delete-index';

    /**
     * @var string
     */
    protected $description = 'Drop an Elasticsearch index if it exists';

    public function indexExists(): bool
    {
        $model = $this->getModel();

        $index = $model->getIndexConfigurator()->getName();

        return Elastic::indices()->exists(['index' => $index]);
    }


    public function handle()
    {
        if ($this->indexExists()) {

            $configurator = $this->getModel()->getIndexConfigurator();
            $indexName = $this->resolveIndexName($configurator);

            $payload = (new RawPayload())
                ->set('index', $indexName)
                ->get();

            Elastic::indices()
                ->delete($payload);

            $this->info(sprintf(
                'The index %s was deleted!',
                $indexName
            ));
            
        }
    }
}
