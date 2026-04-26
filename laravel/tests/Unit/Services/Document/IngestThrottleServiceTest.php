<?php

namespace Tests\Unit\Services\Document;

use Tests\TestCase;
use App\Services\Document\IngestThrottleService;

class IngestThrottleServiceTest extends TestCase
{
    public function test_create_batches_respects_batch_size(): void
    {
        $service = new IngestThrottleService(10, 10000, 0.5, 3, 1.0);
        
        $chunks = range(1, 25);
        $tokens = array_fill(0, 24, 100);
        
        $batches = $service->createBatches($chunks, $tokens);
        
        $this->assertGreaterThan(2, count($batches));
        
        foreach ($batches as $batch) {
            $this->assertLessThanOrEqual(11, count($batch['chunks']));
        }
    }

    public function test_create_batches_respects_token_limit(): void
    {
        $service = new IngestThrottleService(100, 100, 0.5, 3, 1.0);
        
        $chunks = range(1, 10);
        $tokens = [60, 60, 60, 60, 60, 60, 60, 60, 60, 60];
        
        $batches = $service->createBatches($chunks, $tokens);
        
        foreach ($batches as $batch) {
            $this->assertLessThanOrEqual(110, $batch['tokens']);
        }
    }

    public function test_is_rate_limit_error_detects_429(): void
    {
        $service = new IngestThrottleService();
        
        $error = new \Exception('Error 429: Rate limit exceeded');
        
        $this->assertTrue($service->isRateLimitError($error));
    }

    public function test_is_rate_limit_error_detects_resource_exhausted(): void
    {
        $service = new IngestThrottleService();
        
        $error = new \Exception('Service unavailable: resource_exhausted');
        
        $this->assertTrue($service->isRateLimitError($error));
    }

    public function test_is_rate_limit_error_false_for_other_errors(): void
    {
        $service = new IngestThrottleService();
        
        $error = new \Exception('500 Internal server error');
        
        $this->assertFalse($service->isRateLimitError($error));
    }

    public function test_is_token_limit_error_detects_413(): void
    {
        $service = new IngestThrottleService();
        
        $error = new \Exception('Error 413: Request body too large');
        
        $this->assertTrue($service->isTokenLimitError($error));
    }

    public function test_get_retry_delay_uses_exponential_backoff(): void
    {
        $service = new IngestThrottleService(100, 40000, 0.8, 3, 1.0);
        
        $this->assertEquals(1.0, $service->getRetryDelay(1));
        $this->assertEquals(2.0, $service->getRetryDelay(2));
        $this->assertEquals(4.0, $service->getRetryDelay(3));
    }

    public function test_should_retry_respects_attempt_limit(): void
    {
        $service = new IngestThrottleService(100, 40000, 0.8, 3, 1.0);
        
        $error = new \Exception('429 Rate limit');
        
        $this->assertTrue($service->shouldRetry($error, 1));
        $this->assertTrue($service->shouldRetry($error, 2));
        $this->assertFalse($service->shouldRetry($error, 3));
    }

    public function test_should_retry_returns_false_for_non_rate_limit_errors(): void
    {
        $service = new IngestThrottleService(100, 40000, 0.8, 3, 1.0);
        
        $error = new \Exception('500 Internal server error');
        
        $this->assertFalse($service->shouldRetry($error, 1));
    }
}