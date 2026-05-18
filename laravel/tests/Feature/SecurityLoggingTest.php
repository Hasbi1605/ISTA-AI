<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SecurityLoggingTest extends TestCase
{
    public function test_global_exception_report_does_not_log_raw_exception_message(): void
    {
        Log::spy();

        report(new RuntimeException('Bearer leaked-token private prompt body'));

        Log::shouldHaveReceived('error')->with('Global Exception Caught', Mockery::on(function (array $context) {
            return ($context['exception_class'] ?? null) === RuntimeException::class
                && isset($context['file'], $context['line'], $context['message_hash'])
                && ! isset($context['message']);
        }));
    }
}
