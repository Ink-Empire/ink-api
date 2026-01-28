<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SmokeTest extends Command
{
    protected $signature = 'smoke:test {--base-url=http://localhost:8000 : Base URL to test}';
    protected $description = 'Run smoke tests against API endpoints to verify they return valid responses';

    protected string $baseUrl;
    protected ?string $appToken;
    protected int $passed = 0;
    protected int $failed = 0;

    public function handle(): int
    {
        $this->baseUrl = $this->option('base-url');
        $this->appToken = env('API_APP_TOKEN') ?: config('app.api_app_token');

        $this->info("Running smoke tests against: {$this->baseUrl}");
        $this->newLine();

        // Test search endpoints and capture IDs for single record tests
        $tattooId = 2; //todo let's NOT hardcode this once we're good and going without demo data
        $artistId = 1;

        // Test single record endpoints
        if ($tattooId) {
            $this->testEndpoint('GET', "/api/tattoos/{$tattooId}", null, 'Single tattoo');
        } else {
            $this->line("<fg=yellow>SKIP</> Single tattoo - no tattoo ID from search");
        }

        if ($artistId) {
            $this->testEndpoint('GET', "/api/artists/{$artistId}", null, 'Single artist');
        } else {
            $this->line("<fg=yellow>SKIP</> Single artist - no artist ID from search");
        }

        // Test other endpoints
        $this->testEndpoint('GET', '/api/tags', null, 'Tags index');
        $this->testEndpoint('GET', '/api/tags/featured', null, 'Tags featured');
        $this->testEndpoint('GET', '/api/tags/search?q=test', null, 'Tags search');
        $this->testEndpoint('GET', '/api/styles', null, 'Styles index');
        $this->testEndpoint('GET', '/api/countries', null, 'Countries index');

        $this->newLine();
        $this->info("Results: {$this->passed} passed, {$this->failed} failed");

        return $this->failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    protected function testEndpoint(string $method, string $path, ?array $data, string $name): ?\Illuminate\Http\Client\Response
    {
        $url = $this->baseUrl . $path;
        $startTime = microtime(true);

        try {
            $request = Http::timeout(10)->withHeaders(['Accept' => 'application/json']);

            if ($this->appToken) {
                $request = $request->withHeaders(['X-App-Token' => $this->appToken]);
            }

            $response = $method === 'GET'
                ? $request->get($url)
                : $request->post($url, $data ?? []);

            $duration = round((microtime(true) - $startTime) * 1000);

            if ($response->successful()) {
                $this->line("<fg=green>PASS</> {$name} ({$duration}ms)");
                $this->passed++;
                return $response;
            } else {
                $this->line("<fg=red>FAIL</> {$name} - HTTP {$response->status()} ({$duration}ms)");
                $this->outputError($response);
                $this->failed++;
                return null;
            }
        } catch (\Exception $e) {
            $this->line("<fg=red>FAIL</> {$name} - " . $e->getMessage());
            $this->failed++;
            return null;
        }
    }

    protected function testSearchEndpoint(string $method, string $path, ?array $data, string $name, string $idPath): mixed
    {
        $response = $this->testEndpoint($method, $path, $data, $name);

        if ($response) {
            return data_get($response->json(), $idPath);
        }

        return null;
    }

    protected function outputError(\Illuminate\Http\Client\Response $response): void
    {
        $body = $response->body();
        $json = json_decode($body, true);

        if ($json && isset($json['message'])) {
            $this->line("  Error: {$json['message']}");
        } elseif ($json && isset($json['error'])) {
            $this->line("  Error: {$json['error']}");
        } else {
            $this->line("  Response: " . substr($body, 0, 500));
        }
    }
}
