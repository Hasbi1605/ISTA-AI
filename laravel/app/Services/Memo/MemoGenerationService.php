<?php

namespace App\Services\Memo;

use App\Models\Memo;
use App\Models\MemoVersion;
use App\Models\User;
use App\Services\OnlyOffice\DocxValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class MemoGenerationService
{
    protected string $baseUrl;

    protected ?string $token;

    protected int $connectTimeout;

    protected int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.ai_document_service.url', 'http://127.0.0.1:8001'), '/');
        $this->token = config('services.ai_document_service.token');
        $this->connectTimeout = max(1, (int) config('services.ai_document_service.connect_timeout', 10));
        $this->timeout = max(1, (int) config('services.ai_document_service.timeout', 120));
    }

    /**
     * @param  array<int, int>  $sourceDocumentIds
     */
    public function generate(User $user, string $memoType, string $title, string $context, array $sourceDocumentIds = [], array $configuration = []): Memo
    {
        $configuration = $this->normalizeConfiguration($configuration);
        $draft = $this->requestDraft($memoType, $title, $context, $configuration);
        $configuration = $this->applyResolvedPageSize($configuration, $draft['page_size']);
        $path = null;

        try {
            return DB::transaction(function () use ($user, $memoType, $title, $sourceDocumentIds, $configuration, $draft, &$path) {
                $memo = Memo::create([
                    'user_id' => $user->id,
                    'title' => $title,
                    'memo_type' => $memoType,
                    'status' => Memo::STATUS_GENERATED,
                    'source_document_ids' => array_values(array_unique(array_map('intval', $sourceDocumentIds))),
                    'configuration' => $configuration,
                    'searchable_text' => $draft['searchable_text'],
                ]);

                $path = $this->storeDraft($memo, $draft['content'], 1);
                $version = $this->createVersion($memo, 1, $path, $configuration, $draft['searchable_text']);

                $this->activateVersion($memo, $version);

                return $memo;
            });
        } catch (Throwable $e) {
            $this->deleteStoredDraft($path);

            throw $e;
        }
    }

    public function generateRevision(Memo $memo, string $context, array $configuration = [], ?string $revisionInstruction = null): MemoVersion
    {
        if ($revisionInstruction !== null && trim($revisionInstruction) !== '') {
            $configuration['revision_instruction'] = $revisionInstruction;
        }

        $configuration = $this->normalizeConfiguration($configuration);
        $title = (string) ($configuration['subject'] ?? $memo->title);
        $draft = $this->requestDraft($memo->memo_type, $title, $context, $configuration);
        $configuration = $this->applyResolvedPageSize($configuration, $draft['page_size']);
        $path = null;

        try {
            return DB::transaction(function () use ($memo, $draft, $configuration, &$path) {
                $lockedMemo = Memo::lockForUpdate()->findOrFail($memo->id);
                $versionNumber = ((int) $lockedMemo->versions()->max('version_number')) + 1;
                $path = $this->storeDraft($lockedMemo, $draft['content'], $versionNumber);

                $version = $this->createVersion(
                    $lockedMemo,
                    $versionNumber,
                    $path,
                    $configuration,
                    $draft['searchable_text'],
                    $configuration['revision_instruction'] ?? null,
                );

                $this->activateVersion($lockedMemo, $version);

                return $version;
            });
        } catch (Throwable $e) {
            $this->deleteStoredDraft($path);

            throw $e;
        }
    }

    public function generateRevisionFromBody(Memo $memo, string $body, array $configuration = [], ?string $revisionInstruction = null): MemoVersion
    {
        if ($revisionInstruction !== null && trim($revisionInstruction) !== '') {
            $configuration['revision_instruction'] = $revisionInstruction;
        }

        $storedConfiguration = $this->normalizeConfiguration($configuration);
        $title = (string) ($storedConfiguration['subject'] ?? $memo->title);
        $requestConfiguration = array_merge($storedConfiguration, [
            'body_override' => trim($body),
        ]);
        $draft = $this->requestDraft($memo->memo_type, $title, $body, $requestConfiguration);
        $storedConfiguration = $this->applyResolvedPageSize($storedConfiguration, $draft['page_size']);
        $path = null;

        try {
            return DB::transaction(function () use ($memo, $draft, $storedConfiguration, &$path) {
                $lockedMemo = Memo::lockForUpdate()->findOrFail($memo->id);
                $versionNumber = ((int) $lockedMemo->versions()->max('version_number')) + 1;
                $path = $this->storeDraft($lockedMemo, $draft['content'], $versionNumber);

                $version = $this->createVersion(
                    $lockedMemo,
                    $versionNumber,
                    $path,
                    $storedConfiguration,
                    $draft['searchable_text'],
                    $storedConfiguration['revision_instruction'] ?? null,
                );

                $this->activateVersion($lockedMemo, $version);

                return $version;
            });
        } catch (Throwable $e) {
            $this->deleteStoredDraft($path);

            throw $e;
        }
    }

    public function activateVersion(Memo $memo, MemoVersion $version, bool $touch = true): Memo
    {
        if ((int) $version->memo_id !== (int) $memo->id) {
            throw new RuntimeException('Versi memo tidak sesuai.');
        }

        $configuration = $version->configuration ?? [];

        $update = fn () => $memo->forceFill([
            'title' => (string) ($configuration['subject'] ?? $memo->title),
            'file_path' => $version->file_path,
            'current_version_id' => $version->id,
            'status' => $version->status ?: Memo::STATUS_GENERATED,
            'configuration' => $configuration,
            'searchable_text' => $version->searchable_text,
        ])->save();

        if ($touch) {
            $update();
        } else {
            Memo::withoutTimestamps($update);
        }

        return $memo->refresh();
    }

    /**
     * @param  array<string, string>  $configuration
     * @return array{content: string, searchable_text: string, page_size: string|null}
     */
    protected function requestDraft(string $memoType, string $title, string $context, array $configuration): array
    {
        $response = Http::withToken($this->token ?: '')
            ->accept('application/vnd.openxmlformats-officedocument.wordprocessingml.document')
            ->connectTimeout($this->connectTimeout)
            ->timeout($this->timeout)
            ->asJson()
            ->post($this->baseUrl.'/api/memos/generate-body', [
                'memo_type' => $memoType,
                'title' => $title,
                'context' => $context,
                'configuration' => $configuration,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException($response->body() ?: 'Gagal membuat draft memo.');
        }

        return [
            'content' => $response->body(),
            'searchable_text' => $this->normalizeSearchableText(
                $response->header('X-Memo-Searchable-Text-B64') ?: $response->header('X-Memo-Searchable-Text'),
                $title,
                $context,
            ),
            'page_size' => $this->normalizeResolvedPageSize($response->header('X-Memo-Page-Size')),
        ];
    }

    /**
     * @param  array<string, string>  $configuration
     * @return array<string, string>
     */
    protected function applyResolvedPageSize(array $configuration, ?string $resolvedPageSize): array
    {
        if (in_array($resolvedPageSize, ['letter', 'folio'], true)) {
            $configuration['page_size'] = $resolvedPageSize;
        }

        return $configuration;
    }

    protected function normalizeResolvedPageSize(?string $pageSize): ?string
    {
        $normalized = strtolower(trim((string) $pageSize));

        return in_array($normalized, ['letter', 'folio'], true) ? $normalized : null;
    }

    protected function storeDraft(Memo $memo, string $content, int $versionNumber): string
    {
        app(DocxValidator::class)->assertValidBytes($content, 'Draft memo');

        $path = 'memos/'.$memo->user_id.'/'.$memo->id.'-v'.$versionNumber.'-'.Str::uuid().'.docx';

        if (! Storage::disk('local')->put($path, $content)) {
            throw new RuntimeException('Gagal menyimpan file DOCX memo.');
        }

        return $path;
    }

    protected function deleteStoredDraft(?string $path): void
    {
        if ($path) {
            Storage::disk('local')->delete($path);
        }
    }

    /**
     * @param  array<string, string>  $configuration
     */
    protected function createVersion(
        Memo $memo,
        int $versionNumber,
        string $path,
        array $configuration,
        string $searchableText,
        ?string $revisionInstruction = null,
    ): MemoVersion {
        return $memo->versions()->create([
            'version_number' => $versionNumber,
            'label' => 'Versi '.$versionNumber,
            'file_path' => $path,
            'status' => Memo::STATUS_GENERATED,
            'configuration' => $configuration,
            'searchable_text' => $searchableText,
            'revision_instruction' => $revisionInstruction,
        ]);
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, string>
     */
    protected function normalizeConfiguration(array $configuration): array
    {
        $allowedKeys = [
            'number',
            'recipient',
            'sender',
            'subject',
            'date',
            'basis',
            'content',
            'closing',
            'signatory',
            'carbon_copy',
            'page_size',
            'page_size_mode',
            'additional_instruction',
            'revision_instruction',
        ];

        $normalized = [];

        foreach ($allowedKeys as $key) {
            $hasKey = array_key_exists($key, $configuration);
            $value = trim((string) ($configuration[$key] ?? ''));

            if ($value !== '' || ($key === 'signatory' && $hasKey)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    protected function normalizeSearchableText(?string $headerText, string $title, string $context): string
    {
        $encodedText = trim((string) $headerText);
        $decodedText = $encodedText !== '' ? base64_decode(strtr($encodedText, '-_', '+/'), true) : false;
        $text = is_string($decodedText) ? trim($decodedText) : $encodedText;

        return $text !== '' ? $text : trim($title."\n".$context);
    }
}
