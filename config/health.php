<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Grace periods
    |--------------------------------------------------------------------------
    |
    | Indexing runs through the queue, so a freshly verified account or a new
    | post is legitimately absent from Elasticsearch for a short while. Records
    | younger than this are not counted against the freshness checks.
    |
    */

    'index_grace_minutes' => env('HEALTH_INDEX_GRACE_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Drift tolerance
    |--------------------------------------------------------------------------
    |
    | Counts move while indexing is in flight, so exact equality flaps. Drift is
    | measured against the database count and has to clear both the percentage
    | and the absolute floor before it is reported.
    |
    */

    'drift' => [
        'warn_percent' => env('HEALTH_DRIFT_WARN_PERCENT', 2),
        'critical_percent' => env('HEALTH_DRIFT_CRITICAL_PERCENT', 10),
        'minimum_documents' => env('HEALTH_DRIFT_MIN_DOCS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Indexing is queued, so a backed up or failing queue means new signups and
    | posts stop reaching search without anything else going wrong.
    |
    */

    'queue' => [
        'names' => ['default', 'elastic-rebuild'],
        'warn_depth' => env('HEALTH_QUEUE_WARN_DEPTH', 500),
        'critical_depth' => env('HEALTH_QUEUE_CRITICAL_DEPTH', 5000),
        'failed_warn' => env('HEALTH_FAILED_JOBS_WARN', 1),
        'failed_critical' => env('HEALTH_FAILED_JOBS_CRITICAL', 25),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sampling
    |--------------------------------------------------------------------------
    |
    | The orphan and missing document checks are bounded so the hourly run stays
    | cheap on a large index.
    |
    */

    'sample_size' => env('HEALTH_SAMPLE_SIZE', 200),

    /*
    |--------------------------------------------------------------------------
    | Canonical search
    |--------------------------------------------------------------------------
    |
    | A query that must always return something. Catches an index that exists
    | and counts correctly but has a broken mapping or analyzer.
    |
    */

    'canonical_search_term' => env('HEALTH_CANONICAL_SEARCH', 'tattoo'),

    /*
    |--------------------------------------------------------------------------
    | Alerting
    |--------------------------------------------------------------------------
    |
    | Alerts fire when a check changes state, and again on this interval while
    | it is still failing, so a long running problem is not forgotten after its
    | first message.
    |
    */

    'alerts' => [
        'enabled' => env('HEALTH_ALERTS_ENABLED', true),
        'repeat_after_hours' => env('HEALTH_ALERT_REPEAT_HOURS', 6),
        'state_ttl_hours' => env('HEALTH_ALERT_STATE_TTL_HOURS', 72),
    ],

];
