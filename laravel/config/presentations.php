<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Presentation Queue
    |--------------------------------------------------------------------------
    |
    | Production runs Horizon on Redis. Presentation generation explicitly uses
    | that queue connection in production so a bad default QUEUE_CONNECTION
    | cannot leave PPT jobs stranded in the database jobs table.
    |
    */
    'queue' => [
        'connection' => env(
            'PRESENTATION_QUEUE_CONNECTION',
            env('APP_ENV') === 'production' ? 'redis' : null
        ),
        'name' => env('PRESENTATION_QUEUE', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Deploy Recovery
    |--------------------------------------------------------------------------
    |
    | The production deploy workflow can re-dispatch a small number of stale
    | render jobs after Horizon has restarted. This helps recover jobs created
    | while the queue worker was unavailable or pointed at the wrong backend.
    |
    */
    'deploy_recovery' => [
        'minutes' => (int) env('PRESENTATION_DEPLOY_RECOVERY_MINUTES', 1),
        'limit' => (int) env('PRESENTATION_DEPLOY_RECOVERY_LIMIT', 25),
    ],
];
