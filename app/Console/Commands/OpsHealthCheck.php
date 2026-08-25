<?php

namespace App\Console\Commands;

use App\Enums\HealthStatus;
use App\Services\HealthCheckService;
use App\Services\SlackService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class OpsHealthCheck extends Command
{
    protected $signature = 'ops:health-check
        {--no-alert : Run the checks and print them without posting to Slack}';

    protected $description = 'Run production health checks and alert the ops channel when one changes state';

    public function __construct(
        protected HealthCheckService $health,
        protected SlackService $slack
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $result = $this->health->deep();

        $this->render($result);

        if ($this->option('no-alert') || ! config('health.alerts.enabled')) {
            return $result['status'] === HealthStatus::CRITICAL ? self::FAILURE : self::SUCCESS;
        }

        foreach ($result['checks'] as $check) {
            $this->alertOnChange($check);
        }

        return $result['status'] === HealthStatus::CRITICAL ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Alert when a check changes state, and again on the repeat interval while
     * it is still failing. Posting every failing check every hour is how an ops
     * channel becomes noise that nobody reads.
     */
    private function alertOnChange(array $check): void
    {
        $key = 'health:state:' . $check['name'];
        $previous = Cache::get($key);
        $status = $check['status'];

        $wasFailing = $previous && HealthStatus::isFailing($previous['status']);
        $isFailing = HealthStatus::isFailing($status);

        $shouldAlert = false;

        if ($isFailing && ! $wasFailing) {
            $shouldAlert = true;
        } elseif ($isFailing && $previous['status'] !== $status) {
            $shouldAlert = true;
        } elseif ($isFailing && $this->repeatDue($previous)) {
            $shouldAlert = true;
        } elseif (! $isFailing && $wasFailing) {
            $this->postRecovery($check);
            $this->remember($key, $status, true);

            return;
        }

        if ($shouldAlert) {
            $this->postFailure($check);
        }

        $this->remember($key, $status, $shouldAlert);
    }

    private function repeatDue(?array $previous): bool
    {
        if (! $previous || empty($previous['notified_at'])) {
            return true;
        }

        return Carbon::parse($previous['notified_at'])
            ->addHours((int) config('health.alerts.repeat_after_hours'))
            ->isPast();
    }

    private function remember(string $key, string $status, bool $notified): void
    {
        $previous = Cache::get($key);

        Cache::put($key, [
            'status' => $status,
            'notified_at' => $notified
                ? Carbon::now()->toIso8601String()
                : ($previous['notified_at'] ?? null),
        ], Carbon::now()->addHours((int) config('health.alerts.state_ttl_hours')));
    }

    private function postFailure(array $check): void
    {
        $label = $check['status'] === HealthStatus::CRITICAL ? 'CRITICAL' : 'WARNING';

        $body = "*Check:* {$check['name']}\n"
            . "*Status:* {$label}\n"
            . "*What it means:* {$check['message']}";

        if (! empty($check['detail'])) {
            $body .= "\n*Detail:* `" . json_encode($check['detail']) . '`';
        }

        $this->slack->notifyOps('Health check failing', $body);
    }

    private function postRecovery(array $check): void
    {
        $this->slack->notifyOps(
            'Health check recovered',
            "*Check:* {$check['name']}\n*Status:* OK\n{$check['message']}"
        );
    }

    private function render(array $result): void
    {
        $this->line('Overall: ' . strtoupper($result['status']));

        $rows = [];

        foreach ($result['checks'] as $check) {
            $rows[] = [
                $check['name'],
                strtoupper($check['status']),
                $check['message'],
            ];
        }

        $this->table(['Check', 'Status', 'Message'], $rows);
    }
}
