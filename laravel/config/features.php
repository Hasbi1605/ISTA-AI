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

    // Tab Presentasi (PPTX/PDF generator) pada shell ISTA AI. Disembunyikan
    // secara default sampai pipeline generate stabil (epic #218).
    'presentation' => (bool) env('FEATURE_PRESENTATION', false),

];
