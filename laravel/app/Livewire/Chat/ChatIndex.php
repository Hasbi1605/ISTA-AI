<?php

namespace App\Livewire\Chat;

use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ChatIndex extends Component
{
    public function render()
    {
        return view('livewire.chat.chat-index');
    }
}
