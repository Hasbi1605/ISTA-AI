<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    |
    | Toggle eksperimental / fitur yang sedang dirilis bertahap. Flag dibaca
    | dari environment agar bisa diaktifkan per-deploy tanpa mengubah kode.
    |
    */

    // Tab Prompy Studio pada shell ISTA AI. FEATURE_PRESENTATION hanya dibaca
    // sebagai fallback legacy dari rollout lama.
    'prompy' => (bool) env('FEATURE_PROMPY', env('FEATURE_PRESENTATION', true)),

];
