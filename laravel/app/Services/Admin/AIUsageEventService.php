<?php

namespace App\Services\Admin;

use App\Models\AIUsageEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Records AI feature usage events for the admin monitoring dashboard.
 *
 * Logging is best-effort: any failure inside the service is captured and
 * never propagated, so AI features keep running even when the events table
 * is unavailable.
 *
 * Privacy guarantees:
 *   - Prompt, answer, and document body content must never be passed in.
 *   - {@see sanitizeMetadata()} drops disallowed keys, truncates strings,
 *     and removes nested values that look like raw text.
 */
class AIUsageEventService
{
    public const MAX_METADATA_VALUE_LENGTH = 256;

    public const MAX_METADATA_KEYS = 30;

    public const MAX_LIST_ITEMS = 20;

    /**
     * Keys allowed in event metadata. Anything outside this list is dropped
     * to keep prompts, document content, and user PII out of the log table.
     */
    public const ALLOWED_METADATA_KEYS = [
        'conversation_id',
        'message_id',
        'document_id',
        'document_ids',
        'document_count',
        'document_extension',
        'document_extensions',
        'generated_prompt_id',
        'memo_id',
        'memo_type',
        'memo_version',
        'platform',
        'prompt_type',
        'page_size',
        'history_message_count',
        'web_search_mode',
        'force_web_search',
        'source_policy',
        'allow_auto_realtime_web',
        'feature_origin',
        'origin',
        'channel',
        'response_length',
        'reason',
        'outcome',
        'sources_count',
        'has_sources',
        'knowledge_used',
        'knowledge_chunk_count',
        'knowledge_source_count',
        'knowledge_source_ids',
        'has_documents',
        'has_attachment',
        'has_reference_image',
        'reference_image_analyzed',
        'contains_internal_context',
        'attachment_extension',
        'duration_ms',
        'duration_label',
        'mime_type',
        'size_bytes',
        'file_size_bytes',
        'target_format',
        'provider',
        'export_format',
        'page_count',
        'job_class',
        'job_attempts',
        'model_label',
        'model_name',
        'model_provider',
        'embedding_provider',
        'subject_label',
        'subject_kind',
    ];

    /**
     * Banned key fragments (case-insensitive) that signal raw user content.
     */
    private const FORBIDDEN_KEY_FRAGMENTS = [
        'prompt',
        'answer',
        'reply',
        'response_body',
        'message_content',
        'content_body',
        'transcript',
        'document_content',
        'document_body',
        'document_text',
        'extracted_text',
        'context_text',
        'raw_text',
        'preview',
        'body_text',
        'searchable_text',
    ];

    private const FORBIDDEN_KEY_EXCEPTIONS = [
        'generated_prompt_id',
        'prompt_type',
    ];

    /**
     * Record that an AI feature has started running for a user.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function started(
        string $feature,
        ?int $userId,
        array $metadata = [],
        ?string $requestId = null,
        ?Model $subject = null,
    ): ?AIUsageEvent {
        return $this->record(
            feature: $feature,
            action: AIUsageEvent::ACTION_STARTED,
            status: AIUsageEvent::STATUS_PENDING,
            userId: $userId,
            metadata: $metadata,
            requestId: $requestId,
            subject: $subject,
        );
    }

    /**
     * Record that an AI feature has completed successfully.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function completed(
        string $feature,
        ?int $userId,
        array $metadata = [],
        ?string $requestId = null,
        ?int $latencyMs = null,
        ?Model $subject = null,
    ): ?AIUsageEvent {
        return $this->record(
            feature: $feature,
            action: AIUsageEvent::ACTION_COMPLETED,
            status: AIUsageEvent::STATUS_SUCCESS,
            userId: $userId,
            metadata: $metadata,
            requestId: $requestId,
            latencyMs: $latencyMs,
            subject: $subject,
        );
    }

    /**
     * Record that an AI feature has failed.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function failed(
        string $feature,
        ?int $userId,
        array $metadata = [],
        ?string $requestId = null,
        ?int $latencyMs = null,
        ?string $errorCode = null,
        ?Model $subject = null,
    ): ?AIUsageEvent {
        return $this->record(
            feature: $feature,
            action: AIUsageEvent::ACTION_FAILED,
            status: AIUsageEvent::STATUS_ERROR,
            userId: $userId,
            metadata: $metadata,
            requestId: $requestId,
            latencyMs: $latencyMs,
            errorCode: $errorCode,
            subject: $subject,
        );
    }

    /**
     * Generate a request id used to correlate started/completed/failed
     * events for the same logical operation.
     */
    public function newRequestId(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Compute latency in milliseconds from a microtime(true) start time.
     */
    public function latencyMsSince(float $startMicrotime): int
    {
        return max(0, (int) round((microtime(true) - $startMicrotime) * 1000));
    }

    /**
     * Convert the Python stream model marker into safe event metadata.
     *
     * @return array<string, string>
     */
    public function modelMetadata(?string $modelLabel): array
    {
        $label = trim((string) $modelLabel);

        if ($label === '') {
            return [];
        }

        $metadata = [
            'model_label' => $label,
        ];

        $provider = $this->inferModelProvider($label);
        if ($provider !== null) {
            $metadata['model_provider'] = $provider;
        }

        $modelName = $this->inferModelName($label);
        if ($modelName !== null) {
            $metadata['model_name'] = $modelName;
        }

        return $metadata;
    }

    /**
     * Convert the document embedding provider returned by the Python ingest
     * service into model metadata used by the admin usage table.
     *
     * @return array<string, string>
     */
    public function embeddingModelMetadata(?string $embeddingProvider): array
    {
        $label = trim((string) $embeddingProvider);

        if ($label === '') {
            return [];
        }

        return [
            'model_label' => $label,
            'model_name' => $label,
            'model_provider' => 'embedding',
            'embedding_provider' => $label,
        ];
    }

    /**
     * Sanitize event metadata so prompts, answers, and document content
     * cannot leak into the database.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function sanitizeMetadata(array $metadata): array
    {
        $sanitized = [];

        foreach ($metadata as $key => $value) {
            if (count($sanitized) >= self::MAX_METADATA_KEYS) {
                break;
            }

            if (! is_string($key)) {
                continue;
            }

            $normalizedKey = strtolower($key);

            if (! in_array($normalizedKey, self::ALLOWED_METADATA_KEYS, true)) {
                continue;
            }

            if ($this->keyLooksForbidden($normalizedKey)) {
                continue;
            }

            $clean = $this->sanitizeValue($value);

            if ($clean === null && $value !== null) {
                continue;
            }

            $sanitized[$normalizedKey] = $clean;
        }

        return $sanitized;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function record(
        string $feature,
        string $action,
        string $status,
        ?int $userId,
        array $metadata,
        ?string $requestId,
        ?int $latencyMs = null,
        ?string $errorCode = null,
        ?Model $subject = null,
    ): ?AIUsageEvent {
        try {
            $sanitizedMetadata = $this->sanitizeMetadata($metadata);

            return AIUsageEvent::create([
                'user_id' => $userId,
                'feature' => $this->limitString($feature, 64) ?? 'unknown',
                'action' => $this->limitString($action, 64) ?? AIUsageEvent::ACTION_STARTED,
                'status' => $this->limitString($status, 32) ?? AIUsageEvent::STATUS_PENDING,
                'request_id' => $requestId !== null ? $this->limitString($requestId, 64) : null,
                'subject_id' => $subject?->getKey() !== null ? (int) $subject->getKey() : null,
                'subject_type' => $subject !== null ? $this->limitString($subject->getMorphClass(), 191) : null,
                'latency_ms' => $latencyMs !== null ? max(0, (int) $latencyMs) : null,
                'error_code' => $errorCode !== null ? $this->limitString($errorCode, 64) : null,
                'metadata' => $sanitizedMetadata !== [] ? $sanitizedMetadata : null,
            ]);
        } catch (Throwable $e) {
            // Best-effort: never break the calling flow because of logging.
            Log::warning('AIUsageEventService: failed to record event', [
                'feature' => $feature,
                'action' => $action,
                'status' => $status,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function inferModelProvider(string $label): ?string
    {
        $lower = strtolower($label);

        return match (true) {
            str_contains($lower, 'bedrock') => 'bedrock',
            str_contains($lower, 'groq') => 'groq',
            str_contains($lower, 'mistral') => 'github_models',
            str_contains($lower, 'gpt') => 'github_models',
            str_contains($lower, 'glm') => 'bedrock',
            str_contains($lower, 'nova') => 'bedrock',
            str_contains($label, ':') => trim(strtolower(strtok($label, ':'))) ?: null,
            default => null,
        };
    }

    private function inferModelName(string $label): ?string
    {
        $lower = strtolower($label);

        return match (true) {
            str_contains($lower, 'gpt-4.1 mini') => 'openai/gpt-4.1-mini',
            str_contains($lower, 'gpt-4.1 nano') => 'openai/gpt-4.1-nano',
            str_contains($lower, 'gpt-4.1') => 'openai/gpt-4.1',
            str_contains($lower, 'gpt-4o') => 'openai/gpt-4o',
            str_contains($lower, 'llama 3.3') => 'groq/llama-3.3-70b-versatile',
            str_contains($lower, 'mistral medium') => 'mistral-ai/mistral-medium-2505',
            str_contains($lower, 'mistral small') => 'mistral-ai/mistral-small-2503',
            str_contains($lower, 'gpt-oss 120b') => 'openai.gpt-oss-120b-1:0',
            str_contains($lower, 'glm 4.7 flash') => 'zai.glm-4.7-flash',
            str_contains($lower, 'glm 4.7') => 'zai.glm-4.7',
            str_contains($lower, 'nova micro') => 'amazon.nova-micro-v1:0',
            str_contains($label, ':') => trim(substr($label, strpos($label, ':') + 1)) ?: null,
            default => null,
        };
    }

    private function sanitizeValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 2) {
            return null;
        }

        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? $value : null;
        }

        if (is_string($value)) {
            return $this->limitString($value, self::MAX_METADATA_VALUE_LENGTH);
        }

        if (is_array($value)) {
            $isList = array_is_list($value);
            $clean = [];
            $count = 0;

            foreach ($value as $itemKey => $itemValue) {
                if ($count >= self::MAX_LIST_ITEMS) {
                    break;
                }

                if ($isList) {
                    $sanitizedItem = $this->sanitizeValue($itemValue, $depth + 1);

                    if ($sanitizedItem === null && $itemValue !== null) {
                        continue;
                    }

                    $clean[] = $sanitizedItem;
                    $count++;

                    continue;
                }

                if (! is_string($itemKey)) {
                    continue;
                }

                $normalizedItemKey = strtolower($itemKey);

                if ($this->keyLooksForbidden($normalizedItemKey)) {
                    continue;
                }

                $sanitizedItem = $this->sanitizeValue($itemValue, $depth + 1);

                if ($sanitizedItem === null && $itemValue !== null) {
                    continue;
                }

                $clean[$normalizedItemKey] = $sanitizedItem;
                $count++;
            }

            return $clean;
        }

        return null;
    }

    private function keyLooksForbidden(string $normalizedKey): bool
    {
        if (in_array($normalizedKey, self::FORBIDDEN_KEY_EXCEPTIONS, true)) {
            return false;
        }

        foreach (self::FORBIDDEN_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalizedKey, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function limitString(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if (mb_strlen($trimmed) <= $max) {
            return $trimmed;
        }

        return mb_substr($trimmed, 0, $max);
    }
}
