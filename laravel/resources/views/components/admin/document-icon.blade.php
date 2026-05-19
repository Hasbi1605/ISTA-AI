@props([
    'type' => 'file',
    'label' => 'File',
])

@php
    $type = in_array($type, ['pdf', 'csv', 'xlsx', 'docx', 'txt', 'image', 'file'], true) ? $type : 'file';
@endphp

<span {{ $attributes->merge(['class' => 'admin-documents-file-icon admin-documents-file-icon--' . $type]) }} aria-label="{{ $label }}" role="img">
    @if ($type === 'pdf')
        <svg fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
            <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4zm2 6a1 1 0 011-1h6a1 1 0 110 2H7a1 1 0 01-1-1zm1 3a1 1 0 100 2h6a1 1 0 100-2H7z" clip-rule="evenodd" />
        </svg>
    @elseif ($type === 'image')
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 5h16a1 1 0 011 1v12a1 1 0 01-1 1H4a1 1 0 01-1-1V6a1 1 0 011-1zm4 4h.01M21 15l-5-5-7 7-3-3-3 3" />
        </svg>
    @else
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
            @if ($type === 'txt')
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 3h7l5 5v13a1 1 0 01-1 1H7a1 1 0 01-1-1V4a1 1 0 011-1zm7 1v4h4M8 13h8M8 17h6" />
            @else
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 3h7l5 5v13a1 1 0 01-1 1H7a1 1 0 01-1-1V4a1 1 0 011-1zm7 1v4h4M8 13h8M8 17h8" />
            @endif
        </svg>
    @endif
</span>
