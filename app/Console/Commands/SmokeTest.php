<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SmokeTest extends Command
{
    protected $signature = 'smoke:test {--base-url=http://localhost:8000 : Base URL to test}';
    protected $description = 'Run smoke tests against API endpoints to verify user stories and response contracts';

    protected string $baseUrl;
    protected ?string $appToken;
    protected int $passed = 0;
    protected int $failed = 0;

    public function handle(): int
    {
        $this->baseUrl = $this->option('base-url');
        $this->appToken = env('API_APP_TOKEN') ?: config('app.api_app_token');

        $this->info("Running smoke tests against: {$this->baseUrl}");

        // ── 8. Tattoo Search ──
        $this->section('Tattoo Search (Stories 8.1-8.3)');

        $tattooId = $this->testSearchEndpoint(
            'POST', '/api/tattoos', [], 'Tattoo search returns results', 'response.0.id'
        );

        $this->testEndpointWithStructure(
            'POST', '/api/tattoos', [],
            '8.1 Search response has required fields',
            'response.0', ['id', 'title', 'primary_image', 'artist_id']
        );

        $this->testEndpointWithStructure(
            'POST', '/api/tattoos', [],
            '8.1 Search results include uploader fields',
            'response.0', ['uploaded_by_user_id', 'approval_status', 'is_visible']
        );

        // 8.2 Filter by style
        $styleId = $this->testSearchEndpoint(
            'GET', '/api/styles', null, 'Styles index returns data', '0.id'
        );

        if ($styleId) {
            $this->testEndpoint(
                'POST', '/api/tattoos', ['styles' => [$styleId]],
                '8.2 Tattoo search with style filter'
            );
        }

        // ── 9. Artist Search ──
        $this->section('Artist Search (Stories 9.1-9.3)');

        $artistId = $this->testSearchEndpoint(
            'POST', '/api/artists', [], 'Artist search returns results', 'response.0.id'
        );

        $this->testEndpointWithStructure(
            'POST', '/api/artists', [],
            '9.1 Artist search has required fields',
            'response.0', ['id', 'name', 'slug', 'location']
        );

        if ($styleId) {
            $this->testEndpoint(
                'POST', '/api/artists', ['styles' => [$styleId]],
                '9.2 Artist search with style filter'
            );
        }

        $this->testEndpoint(
            'POST', '/api/artists', ['books_open' => true],
            '9.3 Artist search with books_open filter'
        );

        // 9.4 Client fallback on empty artist results
        $this->testEndpoint(
            'POST', '/api/artists', ['query' => 'zzzznonexistent99999'],
            '9.4 Artist search with no results returns 200'
        );

        // ── 6. Tattoo Detail Page ──
        $this->section('Tattoo Detail (Stories 6.1-6.5)');

        if ($tattooId) {
            $this->testEndpointWithStructure(
                'GET', "/api/tattoos/{$tattooId}", null,
                '6.1 Tattoo detail has core fields',
                'tattoo', ['id', 'title', 'description', 'primary_image', 'styles', 'tags']
            );

            $this->testEndpointWithStructure(
                'GET', "/api/tattoos/{$tattooId}", null,
                '6.1 Tattoo detail has artist info',
                'tattoo', ['artist_id', 'studio']
            );

            $this->testEndpointWithStructure(
                'GET', "/api/tattoos/{$tattooId}", null,
                '6.4 Tattoo detail has uploader attribution',
                'tattoo', ['uploaded_by_user_id', 'approval_status']
            );
        } else {
            $this->line("<fg=yellow>SKIP</> Tattoo detail tests - no tattoo ID from search");
        }

        // ── 10. Artist Profile ──
        $this->section('Artist Profile (Stories 10.1-10.4)');

        if ($artistId) {
            $this->testEndpointWithStructure(
                'GET', "/api/artists/{$artistId}?db=true", null,
                '10.1 Artist profile has core fields',
                'artist', ['id', 'name', 'slug', 'about', 'location']
            );

            $this->testEndpointWithStructure(
                'GET', "/api/artists/{$artistId}?db=true", null,
                '10.1 Artist profile has settings',
                'artist', ['settings']
            );
        } else {
            $this->line("<fg=yellow>SKIP</> Artist profile tests - no artist ID from search");
        }

        // ── 12. Tags ──
        $this->section('Tags (Stories 12.1-12.3)');

        $this->testEndpointWithStructure(
            'GET', '/api/tags', null,
            '12.3 Tags index returns data',
            null, ['success', 'data']
        );

        $this->testEndpointWithStructure(
            'GET', '/api/tags/featured', null,
            '12.3 Featured tags returns data',
            null, ['success', 'data']
        );

        $this->testEndpoint('GET', '/api/tags/search?q=test', null, 'Tags search');

        // ── Reference Data ──
        $this->section('Reference Data');

        $this->testEndpoint('GET', '/api/styles', null, 'Styles index');
        $this->testEndpoint('GET', '/api/countries', null, 'Countries index');

        // ── Results ──
        $this->newLine();
        $total = $this->passed + $this->failed;
        if ($this->failed > 0) {
            $this->error("Results: {$this->passed}/{$total} passed, {$this->failed} failed");
        } else {
            $this->info("Results: {$this->passed}/{$total} passed");
        }

        return $this->failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    protected function section(string $title): void
    {
        $this->newLine();
        $this->line("<fg=cyan;options=bold>{$title}</>");
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
                $this->line("  <fg=green>PASS</> {$name} ({$duration}ms)");
                $this->passed++;
                return $response;
            } else {
                $this->line("  <fg=red>FAIL</> {$name} - HTTP {$response->status()} ({$duration}ms)");
                $this->outputError($response);
                $this->failed++;
                return null;
            }
        } catch (\Exception $e) {
            $this->line("  <fg=red>FAIL</> {$name} - " . $e->getMessage());
            $this->failed++;
            return null;
        }
    }

    protected function testEndpointWithStructure(
        string $method,
        string $path,
        ?array $data,
        string $name,
        ?string $dataPath,
        array $requiredKeys
    ): ?\Illuminate\Http\Client\Response {
        $response = $this->testEndpoint($method, $path, $data, $name);

        if (!$response) {
            return null;
        }

        $json = $response->json();
        $target = $dataPath ? data_get($json, $dataPath) : $json;

        if (!is_array($target)) {
            $this->line("    <fg=yellow>WARN</> Could not find '{$dataPath}' in response");
            return $response;
        }

        $missing = array_diff($requiredKeys, array_keys($target));
        if (!empty($missing)) {
            $this->line("    <fg=red>FAIL</> Missing keys: " . implode(', ', $missing));
            $this->failed++;
            $this->passed--; // Undo the pass from testEndpoint
        }

        return $response;
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
            $this->line("    Error: {$json['message']}");
        } elseif ($json && isset($json['error'])) {
            $this->line("    Error: {$json['error']}");
        } else {
            $this->line("    Response: " . substr($body, 0, 500));
        }
    }
}
