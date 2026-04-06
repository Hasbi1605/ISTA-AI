<?php

namespace App\Livewire\Documents;

use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class DocumentIndex extends Component
{
    public function render()
    {
        return view('livewire.documents.document-index');
    }
}
