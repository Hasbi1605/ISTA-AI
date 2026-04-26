<?php

namespace Tests\Feature\AI;

use App\Services\AI\EmbeddingCascadeService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\AiManager;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Mockery;
use Tests\TestCase;

class EmbeddingCascadeTest extends TestCase
{
    public function test_it_uses_primary_node_when_successful(): void
    {
        $mockProvider = Mockery::mock(\Laravel\Ai\Contracts\Providers\TextProvider::class);
        $mockProvider->shouldReceive('embeddings')->once()->andReturn(
            new EmbeddingsResponse(
                embeddings: [[0.1, 0.2]],
                tokens: 1,
                meta: new Meta(provider: 'test', model: 'text-embedding-3-large')
            )
        );

        $mockAiManager = Mockery::mock(AiManager::class);
        $mockAiManager->shouldReceive('textProvider')->with('openai', Mockery::type('array'))->andReturn($mockProvider);
        $this->app->instance(AiManager::class, $mockAiManager);

        Config::set('ai.embedding_cascade.nodes', [
            [
                'label' => 'Primary',
                'provider' => 'openai',
                'model' => 'text-embedding-3-large',
                'dimensions' => 3072,
                'api_key' => 'token-1',
            ]
        ]);

        $service = new EmbeddingCascadeService();
        $response = $service->embed(['hello']);

        $this->assertEquals('text-embedding-3-large', $response->meta->model);
        $this->assertEquals([[0.1, 0.2]], $response->embeddings);
    }

    public function test_it_falls_back_to_second_node_when_first_fails(): void
    {
        $mockAiManager = Mockery::mock(AiManager::class);
        
        $failedProvider = Mockery::mock(\Laravel\Ai\Contracts\Providers\TextProvider::class);
        $failedProvider->shouldReceive('embeddings')->once()->andThrow(new \Exception('Rate limit'));

        $successProvider = Mockery::mock(\Laravel\Ai\Contracts\Providers\TextProvider::class);
        $successProvider->shouldReceive('embeddings')->once()->andReturn(
            new EmbeddingsResponse(
                embeddings: [[0.3, 0.4]],
                tokens: 1,
                meta: new Meta(provider: 'test', model: 'text-embedding-3-large-backup')
            )
        );

        $mockAiManager->shouldReceive('textProvider')
            ->with('openai', Mockery::on(fn($args) => $args['api_key'] === 'token-1'))
            ->andReturn($failedProvider);

        $mockAiManager->shouldReceive('textProvider')
            ->with('openai', Mockery::on(fn($args) => $args['api_key'] === 'token-2'))
            ->andReturn($successProvider);

        $this->app->instance(AiManager::class, $mockAiManager);

        Config::set('ai.embedding_cascade.nodes', [
            [
                'label' => 'Primary',
                'provider' => 'openai',
                'model' => 'text-embedding-3-large',
                'dimensions' => 3072,
                'api_key' => 'token-1',
            ],
            [
                'label' => 'Backup',
                'provider' => 'openai',
                'model' => 'text-embedding-3-large',
                'dimensions' => 3072,
                'api_key' => 'token-2',
            ]
        ]);

        $service = new EmbeddingCascadeService();
        $response = $service->embed(['hello']);

        $this->assertEquals('text-embedding-3-large-backup', $response->meta->model);
        $this->assertEquals([[0.3, 0.4]], $response->embeddings);
    }

    public function test_it_throws_exception_if_all_nodes_fail(): void
    {
        $mockAiManager = Mockery::mock(AiManager::class);
        $failedProvider = Mockery::mock(\Laravel\Ai\Contracts\Providers\TextProvider::class);
        $failedProvider->shouldReceive('embeddings')->andThrow(new \Exception('Failed'));

        $mockAiManager->shouldReceive('textProvider')->andReturn($failedProvider);
        $this->app->instance(AiManager::class, $mockAiManager);

        Config::set('ai.embedding_cascade.nodes', [
            [
                'label' => 'Node 1',
                'provider' => 'openai',
                'model' => 'text-embedding-3-large',
                'dimensions' => 3072,
                'api_key' => 'token-1',
            ]
        ]);

        $service = new EmbeddingCascadeService();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Embedding cascade failed for all nodes');

        $service->embed(['hello']);
    }
}
