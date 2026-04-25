<?php

namespace App\Services;

use App\Contracts\AIRuntimeInterface;
use App\Services\Runtime\LaravelAIGateway;
use App\Services\Runtime\PythonLegacyAdapter;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class AIRuntimeResolver
{
    protected ?AIRuntimeInterface $primaryRuntime = null;
    protected ?AIRuntimeInterface $secondaryRuntime = null;
    protected string $currentCapability;
    protected bool $shadowMode = false;

    public function __construct(
        protected string $capability,
        protected bool $enableShadow = false
    ) {
        $this->currentCapability = $capability;
        $this->shadowMode = $enableShadow && config('ai_runtime.shadow.enabled', false);
    }

    public function getRuntime(): AIRuntimeInterface
    {
        if ($this->primaryRuntime !== null) {
            return $this->primaryRuntime;
        }

        $runtimeType = config("ai_runtime.{$this->capability}", 'laravel');

        if ($runtimeType === 'shadow' && !$this->shadowMode) {
            $runtimeType = 'laravel';
        }

        $runtime = $this->resolveRuntime($runtimeType);

        $this->primaryRuntime = $runtime;

        if ($this->shadowMode && $runtimeType !== 'shadow') {
            $secondaryType = $runtimeType === 'laravel' ? 'python' : 'laravel';
            $this->secondaryRuntime = $this->resolveRuntime($secondaryType);
        }

        return $this->primaryRuntime;
    }

    protected function resolveRuntime(string $type): AIRuntimeInterface
    {
        return match ($type) {
            'python' => new PythonLegacyAdapter(),
            'laravel' => new LaravelAIGateway(),
            default => throw new InvalidArgumentException("Unknown AI runtime type: {$type}"),
        };
    }

    public function getSecondaryRuntime(): ?AIRuntimeInterface
    {
        if ($this->secondaryRuntime !== null) {
            return $this->secondaryRuntime;
        }

        if (!$this->shadowMode) {
            return null;
        }

        $runtimeType = config("ai_runtime.{$this->capability}", 'python');
        $secondaryType = $runtimeType === 'python' ? 'laravel' : 'python';

        $this->secondaryRuntime = $this->resolveRuntime($secondaryType);

        return $this->secondaryRuntime;
    }

    public function isShadowMode(): bool
    {
        return $this->shadowMode;
    }

    public static function for(string $capability): self
    {
        $shadowEnabled = config('ai_runtime.shadow.enabled', false);

        return new self($capability, $shadowEnabled);
    }

    public function executeWithShadow(\Closure $primaryClosure, \Closure $secondaryClosure): array
    {
        if (!$this->shadowMode) {
            $result = $primaryClosure($this->getRuntime());

            return [
                'primary' => $result,
                'secondary' => null,
                'parity' => null,
            ];
        }

        $startTime = microtime(true);
        $primaryResult = $primaryClosure($this->getRuntime());
        $primaryLatency = microtime(true) - $startTime;

        $secondaryResult = null;
        $secondaryLatency = 0;
        $secondaryError = null;

        try {
            $startTime = microtime(true);
            $secondaryResult = $secondaryClosure($this->getSecondaryRuntime());
            $secondaryLatency = microtime(true) - $startTime;
        } catch (\Throwable $e) {
            $secondaryError = $e->getMessage();
            Log::warning("AIRuntimeResolver: Shadow mode secondary runtime failed", [
                'capability' => $this->currentCapability,
                'error' => $e->getMessage(),
            ]);
        }

        $parity = $this->buildParityMetadata(
            $primaryLatency,
            $secondaryLatency,
            $primaryResult,
            $secondaryResult,
            $secondaryError
        );

        if (config('ai_runtime.shadow.log_parity', true)) {
            Log::info("AIRuntimeResolver: Parity check", [
                'capability' => $this->currentCapability,
                'parity' => $parity,
            ]);
        }

        return [
            'primary' => $primaryResult,
            'secondary' => $secondaryResult,
            'parity' => $parity,
        ];
    }

    protected function buildParityMetadata(
        float $primaryLatency,
        float $secondaryLatency,
        $primaryResult,
        $secondaryResult,
        ?string $secondaryError
    ): array {
        $primarySource = config("ai_runtime.{$this->currentCapability}", 'python');
        $secondarySource = $primarySource === 'python' ? 'laravel' : 'python';

        return [
            'capability' => $this->currentCapability,
            'primary' => [
                'source' => $primarySource,
                'latency_ms' => round($primaryLatency * 1000, 2),
                'status' => 'success',
            ],
            'secondary' => [
                'source' => $secondarySource,
                'latency_ms' => $secondaryError ? null : round($secondaryLatency * 1000, 2),
                'status' => $secondaryError ? 'error' : 'success',
                'error' => $secondaryError,
            ],
            'drift_summary' => $this->calculateDriftSummary($primaryResult, $secondaryResult, $secondaryError),
        ];
    }

    protected function calculateDriftSummary($primaryResult, $secondaryResult, ?string $secondaryError): array
    {
        if ($secondaryError !== null) {
            return [
                'has_drift' => true,
                'type' => 'error',
                'description' => 'Secondary runtime failed with error',
            ];
        }

        if ($primaryResult === $secondaryResult) {
            return [
                'has_drift' => false,
                'type' => 'none',
                'description' => 'Results are identical',
            ];
        }

        return [
            'has_drift' => true,
            'type' => 'content',
            'description' => 'Results differ between runtimes',
        ];
    }
}