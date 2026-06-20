<?php

namespace Tests\Feature\Console;

use App\Models\Presentation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ResolveStalePresentationsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public function test_command_marks_stale_pending_presentation_as_error(): void
    {
        $user = User::factory()->create();
        $presentation = Presentation::create([
            'user_id' => $user->id,
            'title' => 'Render stale pending',
            'status' => Presentation::STATUS_PENDING,
            'visual_template' => 'resmi_klasik',
        ]);
        $presentation->forceFill(['updated_at' => now()->subMinutes(11)])->save();

        $this->artisan('presentations:resolve-stale-renders', ['--minutes' => 10])
            ->expectsOutput('Resolved 1 stale presentation render(s) older than 10 minute(s).')
            ->assertExitCode(0);

        $presentation->refresh();
        $this->assertSame(Presentation::STATUS_ERROR, $presentation->status);
        $this->assertSame('Render presentasi tidak selesai dalam batas waktu. Silakan kirim ulang.', $presentation->error_message);
    }

    public function test_command_marks_stale_processing_presentation_without_active_claim_as_error(): void
    {
        $user = User::factory()->create();
        $presentation = Presentation::create([
            'user_id' => $user->id,
            'title' => 'Render stale processing',
            'status' => Presentation::STATUS_PROCESSING,
            'visual_template' => 'modern_minimal',
        ]);
        $presentation->forceFill(['updated_at' => now()->subMinutes(20)])->save();

        $this->artisan('presentations:resolve-stale-renders', ['--minutes' => 10])
            ->expectsOutput('Resolved 1 stale presentation render(s) older than 10 minute(s).')
            ->assertExitCode(0);

        $this->assertSame(Presentation::STATUS_ERROR, $presentation->refresh()->status);
    }

    public function test_command_skips_processing_presentation_with_active_claim(): void
    {
        $user = User::factory()->create();
        $presentation = Presentation::create([
            'user_id' => $user->id,
            'title' => 'Render active processing',
            'status' => Presentation::STATUS_PROCESSING,
            'visual_template' => 'executive_brief',
        ]);
        $presentation->forceFill(['updated_at' => now()->subMinutes(20)])->save();
        Cache::put('presentation_generate_claim:'.$presentation->id, 'active-claim', 600);

        $this->artisan('presentations:resolve-stale-renders', ['--minutes' => 10])
            ->expectsOutput('Resolved 0 stale presentation render(s) older than 10 minute(s).')
            ->expectsOutput('Skipped 1 stale presentation render(s) with active claim.')
            ->assertExitCode(0);

        $this->assertSame(Presentation::STATUS_PROCESSING, $presentation->refresh()->status);
    }

    public function test_command_skips_recent_and_ready_presentations(): void
    {
        $user = User::factory()->create();
        $recent = Presentation::create([
            'user_id' => $user->id,
            'title' => 'Render recent',
            'status' => Presentation::STATUS_PENDING,
            'visual_template' => 'data_tabel',
        ]);
        $ready = Presentation::create([
            'user_id' => $user->id,
            'title' => 'Render ready',
            'status' => Presentation::STATUS_READY,
            'visual_template' => 'resmi_klasik',
        ]);
        $ready->forceFill(['updated_at' => now()->subMinutes(30)])->save();

        $this->artisan('presentations:resolve-stale-renders', ['--minutes' => 10])
            ->expectsOutput('No stale presentation renders found.')
            ->assertExitCode(0);

        $this->assertSame(Presentation::STATUS_PENDING, $recent->refresh()->status);
        $this->assertSame(Presentation::STATUS_READY, $ready->refresh()->status);
    }

    public function test_stale_presentation_resolver_is_registered_in_schedule(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('presentations:resolve-stale-renders')
            ->assertExitCode(0);
    }
}
