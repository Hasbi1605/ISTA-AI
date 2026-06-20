<?php

namespace App\Livewire\Presentations;

use Livewire\Component;

/**
 * Placeholder shell untuk mode Presentasi (epic #218, child #219).
 *
 * Komponen ini hanya menyiapkan mount point + empty state agar tab Presentasi
 * konsisten dengan Chat/Memo. Form konfigurasi, pemilih dokumen, status
 * generate, dan Prompy Studio dikerjakan di child #223. Tidak ada logic
 * generate PPTX/PDF di sini.
 */
class PresentationWorkspace extends Component
{
    public function render()
    {
        return view('livewire.presentations.presentation-workspace');
    }
}
