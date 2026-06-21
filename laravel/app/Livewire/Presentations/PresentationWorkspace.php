<?php

namespace App\Livewire\Presentations;

use Livewire\Component;

/**
 * Workspace tab Prompy Studio.
 *
 * Nama class dipertahankan agar route/tab lama `presentation` tetap kompatibel,
 * tetapi generator PPTX internal sudah dilepas.
 */
class PresentationWorkspace extends Component
{
    public function render()
    {
        return view('livewire.presentations.presentation-workspace');
    }
}
