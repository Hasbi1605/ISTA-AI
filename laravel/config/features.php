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

    // Tab Presentasi (PPTX/PDF generator) pada shell ISTA AI. Aktif default
    // setelah epic #218 stabil; set FEATURE_PRESENTATION=false untuk rollback.
    'presentation' => (bool) env('FEATURE_PRESENTATION', true),

];
