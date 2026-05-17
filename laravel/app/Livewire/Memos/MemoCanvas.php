<?php

namespace App\Livewire\Memos;

use App\Models\Memo;
use App\Services\Memo\MemoGenerationService;
use App\Services\OnlyOffice\JwtSigner;
use App\Services\OnlyOffice\MemoDocumentKey;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class MemoCanvas extends Component
{
    public ?Memo $memo = null;

    public string $memoType = 'memo_internal';

    public string $title = '';

    public string $context = '';

    public bool $isGenerating = false;

    public function mount(?Memo $memo = null): void
    {
        if ($memo?->exists) {
            abort_unless(Auth::user()?->can('view', $memo), 403);
            $this->memo = $memo;
            $this->memoType = $memo->memo_type;
            $this->title = $memo->title;
        }
    }

    public function generate(MemoGenerationService $generationService): void
    {
        $data = $this->validate([
            'memoType' => ['required', 'in:'.implode(',', array_keys(Memo::TYPES))],
            'title' => ['required', 'string', 'max:160'],
            'context' => ['required', 'string', 'max:12000'],
        ]);

        $this->enforceRateLimit('generate', 5, 60, 'Terlalu banyak generate memo. Coba lagi sebentar.');

        $this->isGenerating = true;

        try {
            $memo = $generationService->generate(
                Auth::user(),
                $data['memoType'],
                $data['title'],
                $data['context'],
            );

            $this->redirectRoute('memos.edit', $memo, navigate: true);
        } finally {
            $this->isGenerating = false;
        }
    }

    public function editorConfig(): ?array
    {
        if ($this->memo === null || ! $this->memo->file_path) {
            return null;
        }

        $signer = app(JwtSigner::class);
        $laravelInternalUrl = rtrim((string) config('services.onlyoffice.laravel_internal_url', config('app.url')), '/');
        $ttlMinutes = max(1, (int) config('services.onlyoffice.signed_url_ttl_minutes', 30));
        $ooToken = app(MemoDocumentKey::class)->generateFileToken($this->memo, null, $ttlMinutes);
        $documentPath = URL::temporarySignedRoute('memos.file.signed', now()->addMinutes($ttlMinutes), [
            'memo' => $this->memo,
            'oo_token' => $ooToken,
        ], false);
        $callbackPath = route('onlyoffice.callback', $this->memo, false);

        $config = [
            'document' => [
                'fileType' => 'docx',
                'key' => app(MemoDocumentKey::class)->forEditor($this->memo),
                'title' => $this->memo->title.'.docx',
                'url' => $laravelInternalUrl.$documentPath,
            ],
            'documentType' => 'word',
            'editorConfig' => [
                'callbackUrl' => $laravelInternalUrl.$callbackPath,
                'customization' => [
                    'forcesave' => true,
                ],
                'mode' => 'edit',
                'lang' => 'id',
                'user' => [
                    'id' => (string) Auth::id(),
                    'name' => (string) Auth::user()?->name,
                ],
            ],
            'exp' => now()->addMinutes($ttlMinutes)->getTimestamp(),
        ];

        $config['token'] = $signer->sign($config);

        return $config;
    }

    public function render()
    {
        return view('livewire.memos.memo-canvas', [
            'memoTypes' => Memo::TYPES,
            'editorConfig' => $this->editorConfig(),
            'onlyOfficeApiUrl' => rtrim((string) config('services.onlyoffice.public_url'), '/').'/web-apps/apps/api/documents/api.js',
        ]);
    }

    protected function enforceRateLimit(string $action, int $maxAttempts, int $decaySeconds = 60, ?string $message = null): void
    {
        $key = $this->rateLimitKey($action);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw ValidationException::withMessages([
                'rate_limit' => $message ?? 'Terlalu banyak permintaan. Silakan coba lagi sebentar.',
            ]);
        }

        RateLimiter::hit($key, $decaySeconds);
    }

    protected function rateLimitKey(string $action): string
    {
        $userId = Auth::id();
        $ip = request()?->ip() ?? 'unknown';
        $userPart = $userId ? 'user-'.$userId : 'guest';

        return implode(':', [static::class, $action, $userPart, $ip]);
    }
}
