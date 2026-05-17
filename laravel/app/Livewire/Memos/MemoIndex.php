<?php

namespace App\Livewire\Memos;

use App\Models\Memo;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class MemoIndex extends Component
{
    private const HISTORY_LOAD_LIMIT = 100;

    public function render()
    {
        return view('livewire.memos.memo-index', [
            'memos' => Memo::query()
                ->where('user_id', Auth::id())
                ->orderBy('updated_at', 'desc')
                ->orderBy('id', 'desc')
                ->limit(self::HISTORY_LOAD_LIMIT)
                ->get(),
        ]);
    }
}
