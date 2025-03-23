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
        $hosts = [
            config('elastic.client.hosts')[0] . ':' . config('elastic.client.port', 9200),
        ];
        
        $this->client = ClientBuilder::create()
            ->setHosts($hosts)
            ->build();
            
        $this->index = config('elastic.client.index', 'default');
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
    
    public function deleteIndex($indexName = null)
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
            return $this->client->bulk($params);
        } catch (\Exception $e) {
            Log::error('Failed to bulk index documents in Elasticsearch: ' . $e->getMessage());
            throw $e;
        }
    }
}