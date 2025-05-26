<?php


namespace App\Jobs;

use App\Services\ElasticService;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Log;


class ElasticRebuildJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $ids;
    public $model;

    public function __construct($model, array $ids = [])
    {
        $this->ids = $ids;
        $this->model = $model;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(ElasticService $elasticService): void
    {
        \Log::info("JOB LOG rebuilding elastic ids", [
            'ids' => (array) $this->ids,
            'model' => $this->model,
        ]);

        if (!empty($this->ids)) {
            $elasticService->rebuild($this->ids, $this->model);
        }
    }

    /**
     * The job failed to process.
     *
     * @param $exception
     * @return void
     */
    public function failed($exception)
    {
        Log::error("JOB LOG job failed to rebuild elastic ids", [
            'ids' => (array) $this->ids,
            'model' => $this->model,
        ]);
    }


    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return array
     */
    public function backoff()
    {
        return [10, 20, 45];
    }
}
