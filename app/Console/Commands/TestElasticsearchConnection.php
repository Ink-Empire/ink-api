<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ElasticsearchService;
use Exception;

class TestElasticsearchConnection extends Command
{
    protected $signature = 'elastic:test-connection';
    protected $description = 'Test Elasticsearch/OpenSearch connection';

    public function handle()
    {
        $this->info('Testing Elasticsearch/OpenSearch connection...');
        
        // Get configuration
        $hosts = config('elastic.client.hosts');
        $username = config('elastic.client.username');
        $password = config('elastic.client.password');
        $timeout = config('elastic.client.timeout_in_seconds', 30);
        $connectTimeout = config('elastic.client.connect_timeout_in_seconds', 10);
        $awsKey = config('services.aws.key');
        
        // Debug host parsing
        $host = config('elastic.client.host', 'localhost');
        $isAws = $awsKey && strpos($host, 'amazonaws.com') !== false;
        
        $this->info('Configuration:');
        $this->line('Raw Hosts: ' . json_encode($hosts));
        $this->line('Parsed Host: ' . $host);
        $this->line('AWS Key: ' . ($awsKey ? 'Set (' . substr($awsKey, 0, 8) . '...)' : 'Not set'));
        $this->line('Is AWS: ' . ($isAws ? 'Yes' : 'No'));
        $this->line('Username: ' . ($username ? 'Set' : 'Not set'));
        $this->line('Password: ' . ($password ? 'Set' : 'Not set'));
        $this->line('Timeout: ' . $timeout . 's');
        $this->line('Connect Timeout: ' . $connectTimeout . 's');
        $this->newLine();
        
        try {
            $this->info('Testing Elasticsearch connection (auto-detects AWS vs standard)...');
            $service = app(ElasticsearchService::class);
            
            $startTime = microtime(true);
            
            // Test cluster health
            $response = $service->getClient()->cluster()->health()->asArray();
            
            $endTime = microtime(true);
            $duration = round(($endTime - $startTime) * 1000, 2);
            
            $this->info("✅ Connection successful! ({$duration}ms)");
            $this->line('Response: ' . json_encode($response, JSON_PRETTY_PRINT));
            
        } catch (Exception $e) {
            $this->error('❌ Connection failed!');
            $this->error('Error: ' . $e->getMessage());
            $this->error('Type: ' . get_class($e));
            
            if (method_exists($e, 'getResponse')) {
                $this->error('Response: ' . $e->getResponse());
            }
        }
    }
}