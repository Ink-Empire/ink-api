<?php


namespace App\Jobs;

use App\Enums\QueueNames;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Log;


class ElasticMigrateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $elasticService;
    protected $aliasName;
    protected $model;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($aliasName, $model)
    {
        $this->aliasName = $aliasName;
        $this->model = $model;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        \Log::info("JOB LOG migrating to alias " . $this->aliasName);

        $model = $this->model;
        $modelString = "App\\\Catalog\\\Models\\" . '\\' .  $model;

        \Artisan::queue('elastic:migrate ' . $modelString . $this->aliasName)
            ->onQueue(QueueNames::ELASTIC_REINDEX);

    }

    /**
     * The job failed to process.
     *
     * @param $exception
     * @return void
     */
    public function failed($exception)
    {
        Log::error("JOB LOG job failed migrate elastic");
    }
}
