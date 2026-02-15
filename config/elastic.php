<?php

return [
    'client' => [
        'hosts' => [
            env('ELASTICSEARCH_SCHEME', 'http') . '://' . str_replace(['http://', 'https://'], '', env('ELASTICSEARCH_HOST', 'localhost')) . ':' . env('ELASTICSEARCH_PORT', 9200)
        ],
        'host' => str_replace(['http://', 'https://'], '', env('ELASTICSEARCH_HOST', 'localhost')),
        'port' => env('ELASTICSEARCH_PORT', 9200),
        'scheme' => env('ELASTICSEARCH_SCHEME', 'http'),
        'auth_string' => env('ELASTICSEARCH_USERNAME') && env('ELASTICSEARCH_PASSWORD')
            ? env('ELASTICSEARCH_SCHEME', 'https') . '://' . env('ELASTICSEARCH_USERNAME') . ":" . env('ELASTICSEARCH_PASSWORD') . "@" . str_replace(['http://', 'https://'], '', env('ELASTICSEARCH_HOST', 'localhost')) . ":" . env('ELASTICSEARCH_PORT', '443')
            : null,
        'base_url' => env('ELASTICSEARCH_HOST') . ":" . env('ELASTICSEARCH_PORT', 443),
        'index' => env('ELASTICSEARCH_INDEX', 'tattoos'),
        'artists_index' => env('ELASTICSEARCH_ARTISTS_INDEX', 'artists'),
        'tattoos_index' => env('ELASTICSEARCH_TATTOOS_INDEX', 'tattoos'),
        'username' => env('ELASTICSEARCH_USERNAME'),
        'password' => env('ELASTICSEARCH_PASSWORD'),
        'api_key' => env('ELASTICSEARCH_API_KEY'),
        'timeout_in_seconds' => env('ELASTICSEARCH_TIMEOUT', 30),
        'connect_timeout_in_seconds' => env('ELASTICSEARCH_CONNECT_TIMEOUT', 10),
        'retries' => 2,
    ],
    'update_mapping' => env('ELASTIC_UPDATE_MAPPING', false),
    'indexer' => env('ELASTIC_INDEXER', 'single'),
    'document_refresh' => env('ELASTIC_DOCUMENT_REFRESH'),
    'snapshot_repo' => env('ELASTIC_SNAP_REPO', 'cs-automated-enc')
];