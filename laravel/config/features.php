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

    // Tab Prompy Studio pada shell ISTA AI. Nama env tetap dipertahankan untuk
    // kompatibilitas rollout lama; set FEATURE_PRESENTATION=false untuk rollback.
    'presentation' => (bool) env('FEATURE_PRESENTATION', true),

];
