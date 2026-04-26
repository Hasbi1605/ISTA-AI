<?php

namespace Tests\Unit\Config;

use Tests\TestCase;

/**
 * Smoke tests untuk parity config Laravel ↔ Python (issue #99).
 *
 * Memastikan kunci-kunci config yang menjadi single source of truth perilaku
 * AI runtime tidak hilang atau di-rename tanpa update operator docs.
 */
class AiConfigParityTest extends TestCase
{
    public function test_system_prompt_default_is_set(): void
    {
        $prompt = config('ai.prompts.system.default');

        $this->assertIsString($prompt);
        $this->assertNotEmpty(trim($prompt));
        $this->assertStringContainsString('ISTA AI', $prompt);
    }

    public function test_web_search_prompts_are_set(): void
    {
        $context = config('ai.prompts.web_search.context');
        $assertive = config('ai.prompts.web_search.assertive_instruction');

        $this->assertIsString($context);
        $this->assertStringContainsString('{current_date}', $context);
        $this->assertStringContainsString('{results}', $context);

        $this->assertIsString($assertive);
        $this->assertNotEmpty(trim($assertive));
    }

    public function test_summarization_prompts_have_template_variables(): void
    {
        $partial = config('ai.prompts.summarization.partial');
        $final = config('ai.prompts.summarization.final');

        $this->assertStringContainsString('{part_number}', $partial);
        $this->assertStringContainsString('{total_parts}', $partial);
        $this->assertStringContainsString('{batch}', $partial);
        $this->assertStringContainsString('{combined_summaries}', $final);
    }

    public function test_fallback_messages_are_set(): void
    {
        $notFound = config('ai.prompts.fallback.document_not_found');
        $error = config('ai.prompts.fallback.document_error');

        $this->assertIsString($notFound);
        $this->assertNotEmpty(trim($notFound));
        $this->assertIsString($error);
        $this->assertNotEmpty(trim($error));
    }

    public function test_reasoning_cascade_is_disabled_by_default(): void
    {
        $this->assertFalse(config('ai.reasoning_cascade.enabled'));
        $this->assertIsArray(config('ai.reasoning_cascade.nodes'));
        $this->assertEmpty(config('ai.reasoning_cascade.nodes'));
    }

    public function test_gmail_mailer_profile_is_configured(): void
    {
        $gmail = config('mail.mailers.gmail');

        $this->assertIsArray($gmail);
        $this->assertSame('smtp', $gmail['transport']);
        $this->assertSame('smtp.gmail.com', $gmail['host']);
        $this->assertSame(587, (int) $gmail['port']);
        $this->assertSame('tls', $gmail['encryption']);
    }

    public function test_rag_batching_keys_exist(): void
    {
        $this->assertNotNull(config('ai.rag.batching.batch_size'));
        $this->assertNotNull(config('ai.rag.batching.max_tokens_per_batch'));
        $this->assertNotNull(config('ai.rag.batching.delay_seconds'));
        $this->assertNotNull(config('ai.rag.batching.retry_attempts'));
        $this->assertNotNull(config('ai.rag.batching.retry_delay_base'));
    }

    public function test_ocr_keys_exist(): void
    {
        $this->assertTrue((bool) config('ai.ocr.enabled'));
        $this->assertSame('tesseract', config('ai.ocr.tesseract_path'));
        $this->assertTrue((bool) config('ai.ocr.fallback_to_tesseract'));
    }

    public function test_vision_cascade_has_nodes(): void
    {
        $nodes = config('ai.vision_cascade.nodes');

        $this->assertIsArray($nodes);
        $this->assertNotEmpty($nodes);
        foreach ($nodes as $node) {
            $this->assertArrayHasKey('label', $node);
            $this->assertArrayHasKey('provider', $node);
            $this->assertArrayHasKey('model', $node);
            $this->assertArrayHasKey('base_url', $node);
        }
    }

    public function test_chat_cascade_nodes_all_have_base_url(): void
    {
        $nodes = config('ai.cascade.nodes');

        $this->assertIsArray($nodes);
        $this->assertNotEmpty($nodes);
        foreach ($nodes as $node) {
            $this->assertArrayHasKey(
                'base_url',
                $node,
                "Cascade node '{$node['label']}' must have base_url to avoid defaulting to OpenAI URL"
            );
            $this->assertNotEmpty($node['base_url']);
        }
    }

    public function test_summarization_single_template_has_document_placeholder(): void
    {
        $single = config('ai.prompts.summarization.single');

        $this->assertIsString($single);
        $this->assertStringContainsString('{document}', $single);
    }
}
