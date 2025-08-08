<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Elasticsearch\ClientBuilder;
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
        
        $this->info('Configuration:');
        $this->line('Hosts: ' . json_encode($hosts));
        $this->line('Username: ' . ($username ? 'Set' : 'Not set'));
        $this->line('Password: ' . ($password ? 'Set' : 'Not set'));
        $this->line('Timeout: ' . $timeout . 's');
        $this->line('Connect Timeout: ' . $connectTimeout . 's');
        $this->newLine();
        
        try {
            // Create client with debug logging
            $clientBuilder = ClientBuilder::create()
                ->setHosts($hosts)
                ->setConnectionParams([
                    'timeout' => $timeout,
                    'connect_timeout' => $connectTimeout,
                ])
                ->setSSLVerification(false)
                ->setLogger(\Illuminate\Support\Facades\Log::getLogger());
                
            $client = $clientBuilder->build();
            
            $this->info('Testing cluster health...');
            $startTime = microtime(true);
            
            $response = $client->cluster()->health([
                'timeout' => $timeout . 's'
            ]);
            
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