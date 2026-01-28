<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SmokeTest extends Command
{
    protected $signature = 'smoke:test {--base-url=http://localhost:8000 : Base URL to test}';
    protected $description = 'Run smoke tests against API endpoints to verify they return valid responses';

    protected array $endpoints = [
        ['POST', '/api/tattoos', [], 'Tattoo search'],
        ['POST', '/api/artists', [], 'Artist search'],
        ['GET', '/api/tags', null, 'Tags index'],
        ['GET', '/api/tags/featured', null, 'Tags featured'],
        ['GET', '/api/tags/search?q=test', null, 'Tags search'],
        ['GET', '/api/styles', null, 'Styles index'],
        ['GET', '/api/countries', null, 'Countries index'],
        ['POST', '/api/elastic', ['model' => 'tattoo'], 'Elastic search'],
    ];

    public function handle(): int
    {
        $baseUrl = $this->option('base-url');
        $appToken = env('API_APP_TOKEN') ?: config('app.api_app_token');

        $this->info("Running smoke tests against: {$baseUrl}");
        $this->newLine();

        $passed = 0;
        $failed = 0;

        foreach ($this->endpoints as [$method, $path, $data, $name]) {
            $url = $baseUrl . $path;
            $startTime = microtime(true);

            try {
                $request = Http::timeout(10)
                    ->withHeaders(['Accept' => 'application/json']);

                if ($appToken) {
                    $request = $request->withHeaders(['X-App-Token' => $appToken]);
                }

                $response = $method === 'GET'
                    ? $request->get($url)
                    : $request->post($url, $data ?? []);

                $duration = round((microtime(true) - $startTime) * 1000);

                if ($response->successful()) {
                    $this->line("<fg=green>PASS</> {$name} ({$duration}ms)");
                    $passed++;
                } else {
                    $this->line("<fg=red>FAIL</> {$name} - HTTP {$response->status()} ({$duration}ms)");
                    if ($this->output->isVerbose()) {
                        $this->line("  Response: " . substr($response->body(), 0, 200));
                    }
                    $failed++;
                }
            } catch (\Exception $e) {
                $this->line("<fg=red>FAIL</> {$name} - " . $e->getMessage());
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Results: {$passed} passed, {$failed} failed");

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
