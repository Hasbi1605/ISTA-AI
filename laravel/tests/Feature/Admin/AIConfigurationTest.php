<?php

namespace Tests\Feature\Admin;

use App\Models\AIConfigAudit;
use App\Models\AIModelConfig;
use App\Models\AIPromptProfile;
use App\Models\AIUsageEvent;
use App\Models\User;
use App\Services\AI\AIConfigurationManagementService;
use App\Services\AI\AIConfigurationResolver;
use App\Services\AIService;
use App\Services\Memo\MemoGenerationService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AIConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolver_returns_empty_payload_when_db_runtime_is_disabled(): void
    {
        config()->set('services.ai_config.db_enabled', false);
        $actor = User::factory()->create();

        $this->createActivePrompt($actor);
        $this->createActiveModel($actor);

        $payload = app(AIConfigurationResolver::class)->runtimePayload(AIPromptProfile::FEATURE_CHAT);

        $this->assertSame([], $payload);
    }

    public function test_resolver_builds_safe_runtime_payload_from_active_config(): void
    {
        $this->enableRuntimeCatalog();
        $actor = User::factory()->create();

        $prompt = $this->createActivePrompt($actor, systemPrompt: 'Jawab sebagai asisten internal yang ringkas.');
        $model = $this->createActiveModel($actor);

        $payload = app(AIConfigurationResolver::class)->runtimePayload(AIPromptProfile::FEATURE_CHAT);

        $this->assertSame($prompt->id, $payload['prompt_profile_id']);
        $this->assertSame($model->id, $payload['model_config_id']);
        $this->assertStringContainsString('Guardrail minimum ISTA AI', $payload['system_prompt']);
        $this->assertStringContainsString('Jawab sebagai asisten internal yang ringkas.', $payload['system_prompt']);
        $this->assertSame('litellm', $payload['chat_models'][0]['provider']);
        $this->assertSame('openai/gpt-4.1-mini', $payload['chat_models'][0]['model_name']);
        $this->assertSame('GITHUB_TOKEN', $payload['chat_models'][0]['api_key_env']);
        $this->assertStringNotContainsString('secret-value', json_encode($payload));
    }

    public function test_default_catalog_exposes_existing_runtime_route_and_prompt_library(): void
    {
        $resolver = app(AIConfigurationResolver::class);

        $catalog = $resolver->modelCatalog();
        $catalogKeys = array_column($catalog, 'catalog_key');

        $this->assertGreaterThanOrEqual(17, count($catalog));
        $this->assertContains('litellm|openai/gpt-4.1|GITHUB_TOKEN', $catalogKeys);
        $this->assertContains('litellm|openai/gpt-4.1|GITHUB_TOKEN_2', $catalogKeys);
        $this->assertContains('bedrock_converse|amazon.nova-micro-v1:0|AWS_BEARER_TOKEN_BEDROCK', $catalogKeys);
        $this->assertArrayHasKey('memo_generation.body', $resolver->promptLibrary());
        $this->assertArrayHasKey('knowledge_internal.answer', $resolver->promptLibrary());
    }

    public function test_model_draft_can_store_ordered_multi_model_route(): void
    {
        $this->enableRuntimeCatalog();
        $actor = User::factory()->create();
        $service = app(AIConfigurationManagementService::class);
        $route = [
            'litellm|openai/gpt-4.1|GITHUB_TOKEN_2',
            'litellm|openai/gpt-4.1|GITHUB_TOKEN',
            'bedrock_converse|amazon.nova-micro-v1:0|AWS_BEARER_TOKEN_BEDROCK',
        ];

        $config = $service->createModelDraft($actor, [
            'feature' => AIPromptProfile::FEATURE_CHAT,
            'name' => 'Route test',
            'model_route' => $route,
            'temperature' => 0.2,
            'max_tokens' => 1024,
            'timeout_seconds' => 60,
            'retrieval_top_k' => 4,
        ]);
        $service->activateModel($actor, $config);

        $payload = app(AIConfigurationResolver::class)->runtimePayload(AIPromptProfile::FEATURE_CHAT);

        $this->assertSame($route, $config->metadata['model_route']);
        $this->assertSame('GITHUB_TOKEN_2', $payload['chat_models'][0]['api_key_env']);
        $this->assertSame('GITHUB_TOKEN', $payload['chat_models'][1]['api_key_env']);
        $this->assertSame('bedrock_converse', $payload['chat_models'][2]['provider']);
        $this->assertSame(4, $payload['retrieval']['rag_top_k']);
        $this->assertStringNotContainsString('secret-value', json_encode($payload));
    }

    public function test_management_service_versions_activation_and_rollback_with_audit(): void
    {
        $this->enableRuntimeCatalog();
        $actor = User::factory()->create();
        $service = app(AIConfigurationManagementService::class);

        $first = $service->createPromptDraft($actor, [
            'feature' => AIPromptProfile::FEATURE_CHAT,
            'name' => 'Prompt pertama',
            'system_prompt' => str_repeat('Prompt pertama aman. ', 3),
        ]);
        $service->activatePrompt($actor, $first);

        $second = $service->createPromptDraft($actor, [
            'feature' => AIPromptProfile::FEATURE_CHAT,
            'name' => 'Prompt kedua',
            'system_prompt' => str_repeat('Prompt kedua aman. ', 3),
        ]);
        $service->activatePrompt($actor, $second);

        $this->assertSame(AIPromptProfile::STATUS_ARCHIVED, $first->refresh()->status);
        $this->assertSame(AIPromptProfile::STATUS_ACTIVE, $second->refresh()->status);

        $service->activatePrompt($actor, $first->refresh(), 'Rollback ke versi pertama');

        $this->assertSame(AIPromptProfile::STATUS_ACTIVE, $first->refresh()->status);
        $this->assertSame(AIPromptProfile::STATUS_ARCHIVED, $second->refresh()->status);
        $this->assertDatabaseHas('ai_config_audits', [
            'action' => AIConfigAudit::ACTION_ROLLED_BACK,
            'reason' => 'Rollback ke versi pertama',
        ]);
    }

    public function test_ai_service_sends_runtime_config_to_chat_service_without_raw_secret(): void
    {
        $this->enableRuntimeCatalog();
        config()->set('services.ai_service.url', 'http://python-ai:8001');
        config()->set('services.ai_service.token', 'internal-token');
        config()->set('services.ai_service.retries', 1);
        $actor = User::factory()->create();
        $this->createActivePrompt($actor);
        $this->createActiveModel($actor);

        $history = [];
        $handlerStack = HandlerStack::create(new MockHandler([
            new Response(200, [], 'ok'),
        ]));
        $handlerStack->push(Middleware::history($history));

        $service = new AIService;
        $reflection = new \ReflectionClass($service);
        $clientProp = $reflection->getProperty('client');
        $clientProp->setAccessible(true);
        $clientProp->setValue($service, new Client(['handler' => $handlerStack]));

        $chunks = iterator_to_array($service->sendChat([['role' => 'user', 'content' => 'halo']]));

        $this->assertSame(['ok'], $chunks);
        $payload = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertArrayHasKey('runtime_config', $payload);
        $this->assertSame('db', app(AIConfigurationResolver::class)->usageMetadataForFeature(AIPromptProfile::FEATURE_CHAT)['ai_config_runtime']);
        $this->assertSame('openai/gpt-4.1-mini', $payload['runtime_config']['chat_models'][0]['model_name']);
        $this->assertStringNotContainsString('secret-value', json_encode($payload));
    }

    public function test_memo_generation_sends_runtime_config_and_records_config_metadata(): void
    {
        $this->enableRuntimeCatalog();
        Storage::fake('local');
        Http::fake([
            '*/api/memos/generate-body' => Http::response($this->validMemoDocxBytes(), 200, [
                'X-Memo-Searchable-Text-B64' => base64_encode('Memo valid'),
                'X-Memo-Page-Size' => 'letter',
            ]),
        ]);

        $actor = User::factory()->create(['email_verified_at' => now()]);
        $prompt = $this->createActivePrompt($actor, AIPromptProfile::FEATURE_MEMO_GENERATION);
        $model = $this->createActiveModel($actor, AIPromptProfile::FEATURE_MEMO_GENERATION);

        app(MemoGenerationService::class)->generate(
            $actor,
            'memo_internal',
            'Memo Runtime Config',
            'Buat memo singkat untuk uji runtime config.',
            [],
            $this->memoConfiguration(),
        );

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/memos/generate-body')
            && $request['runtime_config']['prompt_profile_id'] === $prompt->id
            && $request['runtime_config']['model_config_id'] === $model->id
            && $request['runtime_config']['chat_models'][0]['api_key_env'] === 'GITHUB_TOKEN'
            && ! str_contains(json_encode($request->data()), 'secret-value'));

        $event = AIUsageEvent::query()
            ->where('feature', AIUsageEvent::FEATURE_MEMO_GENERATION)
            ->where('action', AIUsageEvent::ACTION_COMPLETED)
            ->firstOrFail();

        $this->assertSame($prompt->id, $event->metadata['prompt_profile_id']);
        $this->assertSame($model->id, $event->metadata['model_config_id']);
        $this->assertSame('db', $event->metadata['ai_config_runtime']);
    }

    private function enableRuntimeCatalog(): void
    {
        config()->set('services.ai_config.db_enabled', true);
        config()->set('services.ai_config.model_catalog', [
            [
                'label' => 'GPT-4.1 Mini',
                'provider' => 'litellm',
                'model_name' => 'openai/gpt-4.1-mini',
                'api_key_env' => 'GITHUB_TOKEN',
                'base_url' => 'https://models.github.ai/inference',
            ],
        ]);
    }

    private function createActivePrompt(
        User $actor,
        string $feature = AIPromptProfile::FEATURE_CHAT,
        string $systemPrompt = 'Jawab sebagai ISTA AI dengan ringkas dan aman.',
    ): AIPromptProfile {
        return AIPromptProfile::create([
            'feature' => $feature,
            'name' => 'Prompt aktif',
            'system_prompt' => $systemPrompt,
            'status' => AIPromptProfile::STATUS_ACTIVE,
            'version' => 1,
            'created_by' => $actor->id,
            'activated_by' => $actor->id,
            'activated_at' => now(),
        ]);
    }

    private function createActiveModel(User $actor, string $feature = AIPromptProfile::FEATURE_CHAT): AIModelConfig
    {
        return AIModelConfig::create([
            'feature' => $feature,
            'name' => 'Model aktif',
            'provider' => 'litellm',
            'model_name' => 'openai/gpt-4.1-mini',
            'fallback_model_name' => null,
            'temperature' => 0.2,
            'max_tokens' => 1024,
            'timeout_seconds' => 60,
            'retrieval_top_k' => 3,
            'status' => AIModelConfig::STATUS_ACTIVE,
            'version' => 1,
            'created_by' => $actor->id,
            'activated_by' => $actor->id,
            'activated_at' => now(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function memoConfiguration(): array
    {
        return [
            'number' => 'EVAL-08/IST/YK/05/2026',
            'recipient' => 'Kepala Unit Layanan',
            'sender' => 'Kepala Istana Kepresidenan Yogyakarta',
            'subject' => 'Memo Runtime Config',
            'date' => '19 Mei 2026',
            'content' => 'Isi memo test.',
            'signatory' => 'Deni Mulyana',
            'page_size' => 'letter',
        ];
    }
}
