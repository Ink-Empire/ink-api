<?php

namespace App\Services;

use Elasticsearch\ClientBuilder;
use Illuminate\Support\Facades\Log;

class ElasticsearchService
{
    private $client;
    private $index;

    public function __construct()
    {
        $this->index = config('elastic.client.index', 'tattoos');
        
        // Check if we're using AWS OpenSearch (has AWS credentials and proper host)
        $host = config('elastic.client.host', 'localhost');
        $isAws = config('services.aws.key') && strpos($host, 'amazonaws.com') !== false;
        
        if ($isAws) {
            $this->initializeAwsClient();
        } else {
            $this->initializeStandardClient();
        }
    }
    
    private function initializeAwsClient()
    {
        $host = config('elastic.client.host');
        $region = config('services.aws.region', 'us-east-1');
        
        // Use AWS credentials
        $provider = \Aws\Credentials\CredentialProvider::defaultProvider();
        $credentials = $provider()->wait();
        
        $clientBuilder = ClientBuilder::create()
            ->setHosts(['https://' . $host])
            ->setHandler($this->createAwsHandler($credentials, $region));
            
        if ($timeout = config('elastic.client.timeout_in_seconds')) {
            $clientBuilder->setConnectionParams([
                'timeout' => $timeout,
                'connect_timeout' => config('elastic.client.connect_timeout_in_seconds', 10)
            ]);
        }
        
        $this->client = $clientBuilder->build();
    }
    
    private function initializeStandardClient()
    {
        $hosts = config('elastic.client.hosts');
        
        $clientBuilder = ClientBuilder::create()
            ->setHosts($hosts);
            
        if ($timeout = config('elastic.client.timeout_in_seconds')) {
            $clientBuilder->setConnectionParams([
                'timeout' => $timeout,
                'connect_timeout' => config('elastic.client.connect_timeout_in_seconds', 10)
            ]);
        }
        
        if (config('elastic.client.username') && config('elastic.client.password')) {
            $clientBuilder->setSSLVerification(false);
        }
        
        $this->client = $clientBuilder->build();
    }
    
    private function createAwsHandler($credentials, $region)
    {
        return function (array $request) use ($credentials, $region) {
            $signer = new \Aws\Signature\SignatureV4('es', $region);
            $guzzleClient = new \GuzzleHttp\Client();
            
            $psrRequest = new \GuzzleHttp\Psr7\Request(
                $request['http_method'],
                $request['uri'],
                $request['headers'] ?? [],
                $request['body'] ?? ''
            );
            
            $signedRequest = $signer->signRequest($psrRequest, $credentials);
            
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
            Log::error('Failed to create Elasticsearch index: ' . $e->getMessage());
            throw $e;
        }
    }

    public function deleteIndex($indexName = null): array
    {
        $index = $indexName ?? $this->index;

        $params = ['index' => $index];

        try {
            return $this->client->indices()->delete($params);
        } catch (\Exception $e) {
            Log::error('Failed to delete Elasticsearch index: ' . $e->getMessage());
            throw $e;
        }
    }

    public function indexExists($indexName = null)
    {
        $index = $indexName ?? $this->index;

        $params = ['index' => $index];

        try {
            return $this->client->indices()->exists($params);
        } catch (\Exception $e) {
            Log::error('Failed to check if Elasticsearch index exists: ' . $e->getMessage());
            throw $e;
        }
    }

    public function addDocument($id, $data, $indexName = null)
    {
        $index = $indexName ?? $this->index;

        $params = [
            'index' => $index,
            'id' => $id,
            'body' => $data
        ];

        try {
            return $this->client->index($params);
        } catch (\Exception $e) {
            Log::error('Failed to add document to Elasticsearch: ' . $e->getMessage());
            throw $e;
        }
    }

    public function updateDocument($id, $data, $indexName = null)
    {
        $index = $indexName ?? $this->index;

        $params = [
            'index' => $index,
            'id' => $id,
            'body' => [
                'doc' => $data
            ]
        ];

        try {
            return $this->client->update($params);
        } catch (\Exception $e) {
            Log::error('Failed to update document in Elasticsearch: ' . $e->getMessage());
            throw $e;
        }
    }

    public function deleteDocument($id, $indexName = null)
    {
        $index = $indexName ?? $this->index;

        $params = [
            'index' => $index,
            'id' => $id
        ];

        try {
            return $this->client->delete($params);
        } catch (\Exception $e) {
            Log::error('Failed to delete document from Elasticsearch: ' . $e->getMessage());
            throw $e;
        }
    }

    public function search($query, $indexName = null)
    {
        $index = $indexName ?? $this->index;

        $params = [
            'index' => $index,
            'body' => [
                'query' => $query
            ]
        ];

        try {
            return $this->client->search($params);
        } catch (\Exception $e) {
            Log::error('Failed to search Elasticsearch: ' . $e->getMessage());
            throw $e;
        }
    }

    public function bulkIndex($documents, $indexName = null)
    {
        $index = $indexName ?? $this->index;
        $params = ['body' => []];

        foreach ($documents as $id => $document) {
            $params['body'][] = [
                'index' => [
                    '_index' => $index,
                    '_id' => $id
                ]
            ];

            $params['body'][] = $document;
        }

        try {
            $response = $this->client->bulk($params);
            
            // Check for bulk indexing errors
            if (isset($response['errors']) && $response['errors']) {
                Log::error('Bulk indexing had errors', [
                    'items' => $response['items'] ?? [],
                    'errors' => $response['errors']
                ]);
                
                // Log specific errors for each item
                foreach ($response['items'] as $item) {
                    if (isset($item['index']['error'])) {
                        Log::error('Document indexing failed', [
                            'id' => $item['index']['_id'] ?? 'unknown',
                            'error' => $item['index']['error']
                        ]);
                    }
                }
            }
            
            return $response;
        } catch (\Exception $e) {
            Log::error('Failed to bulk index documents in Elasticsearch: ' . $e->getMessage());
            throw $e;
        }
    }
    
    public function getClient()
    {
        return $this->client;
    }
}
