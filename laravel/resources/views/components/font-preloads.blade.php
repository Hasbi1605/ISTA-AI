@php
    $assetRoot = public_path('build/assets');
    $interPreload = is_dir($assetRoot)
        ? collect(glob($assetRoot.'/inter-latin-400-normal*.woff2') ?: [])->first()
        : null;
    $frauncesPreload = is_dir($assetRoot)
        ? collect(glob($assetRoot.'/fraunces-latin-wght-normal*.woff2') ?: [])->first()
        : null;
@endphp
@if ($interPreload)
    <link rel="preload" href="{{ asset('build/assets/'.basename($interPreload)) }}" as="font" type="font/woff2" crossorigin>
@endif
@if ($frauncesPreload)
    <link rel="preload" href="{{ asset('build/assets/'.basename($frauncesPreload)) }}" as="font" type="font/woff2" crossorigin>
@endif
