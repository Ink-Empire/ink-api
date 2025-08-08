<?php

namespace App\Services;

use Aws\Credentials\CredentialProvider;
use Aws\Signature\SignatureV4;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Log;
use Elasticsearch\ClientBuilder;

class AwsElasticsearchService
{
    private $client;
    private $index;
    private $awsCredentials;
    private $region;
    private $endpoint;

    public function __construct()
    {
        $this->endpoint = config('elastic.client.hosts.0.host', 'localhost');
        $this->region = config('services.aws.region', 'us-east-1');
        $this->index = config('elastic.client.index', 'tattoos');
        
        // Initialize AWS credentials
        $provider = CredentialProvider::defaultProvider();
        $this->awsCredentials = $provider()->wait();
        
        // Create Elasticsearch client with custom handler for AWS signing
        $this->client = ClientBuilder::create()
            ->setHosts(['https://' . $this->endpoint])
            ->setHandler($this->createAwsHandler())
            ->setConnectionParams([
                'timeout' => config('elastic.client.timeout_in_seconds', 30),
                'connect_timeout' => config('elastic.client.connect_timeout_in_seconds', 10)
            ])
            ->build();
    }

    private function createAwsHandler()
    {
        return function (array $request) {
            $signer = new SignatureV4('es', $this->region);
            $guzzleClient = new GuzzleClient();
            
            // Create PSR-7 request
            $psrRequest = new Request(
                $request['http_method'],
                $request['uri'],
                $request['headers'] ?? [],
                $request['body'] ?? ''
            );
            
            // Sign the request
            $signedRequest = $signer->signRequest($psrRequest, $this->awsCredentials);
            
            // Execute with Guzzle
            try {
                $response = $guzzleClient->send($signedRequest, [
                    'timeout' => config('elastic.client.timeout_in_seconds', 30),
                    'connect_timeout' => config('elastic.client.connect_timeout_in_seconds', 10)
                ]);
                
                return [
                    'status' => $response->getStatusCode(),
                    'reason' => $response->getReasonPhrase(),
                    'body' => (string) $response->getBody(),
                    'headers' => $response->getHeaders(),
                ];
            } catch (\Exception $e) {
                Log::error('AWS OpenSearch request failed: ' . $e->getMessage());
                throw $e;
            }
        };
    }

    public function testConnection()
    {
        try {
            return $this->client->cluster()->health();
        } catch (\Exception $e) {
            Log::error('AWS Elasticsearch connection failed: ' . $e->getMessage());
            throw $e;
        }
    }

    public function createIndex($indexName = null)
    {
        $index = $indexName ?? $this->index;

        $params = [
            'index' => $index,
            'body' => [
                'settings' => [
                    'number_of_shards' => 1,
                    'number_of_replicas' => 0
                ],
                'mappings' => [
                    'properties' => [
                        'name' => ['type' => 'text'],
                        'description' => ['type' => 'text'],
                        'created_at' => ['type' => 'date'],
                        'updated_at' => ['type' => 'date']
                    ]
                ]
            ]
        ];

        try {
            return $this->client->indices()->create($params);
        } catch (\Exception $e) {
            Log::error('Failed to create index: ' . $e->getMessage());
            throw $e;
        }
    }

    public function search($params)
    {
        return $this->client->search($params);
    }

    public function index($params)
    {
        return $this->client->index($params);
    }

    public function delete($params)
    {
        return $this->client->delete($params);
    }

    public function getClient()
    {
        return $this->client;
    }
}