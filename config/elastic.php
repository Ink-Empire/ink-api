<?php

return [

    'client' => [
        'hosts' => [
            env('ELASTICSEARCH_HOST', 'null'),
        ],
        'auth_string' => 'https://' . env('ELASTICSEARCH_USERNAME') . ":" . env('ELASTICSEARCH_PASSWORD') . "@" . str_replace('https://', '', env('ELASTICSEARCH_HOST', 'localhost')) . ":" . env('ELASTICSEARCH_PORT', '9200'),
        'base_url' => env('ELASTICSEARCH_HOST') . ":" . env('ELASTICSEARCH_PORT', 9200),
        'index' => env('ELASTICSEARCH_INDEX', 'tattoos'),
        'username' => env('ELASTICSEARCH_USERNAME'),
        'password' => env('ELASTICSEARCH_PASSWORD'),
        'timeout_in_seconds' => env('ELASTICSEARCH_TIMEOUT', 200),
        'connect_timeout_in_seconds' => env('ELASTICSEARCH_CONNECT_TIMEOUT', 200),
    ],
    'update_mapping' => env('ELASTIC_UPDATE_MAPPING', false),
    'indexer' => env('ELASTIC_INDEXER', 'single'),
    //TODO i cant seem to find a use for this
    'document_refresh' => env('ELASTIC_DOCUMENT_REFRESH'),
    'snapshot_repo' => env('ELASTIC_SNAP_REPO', 'cs-automated-enc')
];
