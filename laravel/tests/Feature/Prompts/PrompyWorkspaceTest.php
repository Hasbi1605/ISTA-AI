<?php

namespace Tests\Feature\Prompts;

use App\Livewire\Prompts\PrompyWorkspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class PrompyWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_now_renders_prompy_studio_only(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(PrompyWorkspace::class)
            ->assertSee('Prompy Studio')
            ->assertSee('Ide / permintaan')
            ->assertSee('Buat Prompt')
            ->assertSee('Prompt')
            ->assertSee('Prompt Baru')
            ->assertSee('x-data="prompyWorkspace"', false)
            ->assertDontSee('Buat PPT ISTA')
            ->assertDontSee('Konfigurasi Presentasi')
            ->assertDontSee('Unduh PPTX');
    }

    public function test_ppt_generation_routes_are_removed(): void
    {
        $this->assertFalse(Route::has('presentations.download'));
        $this->assertFalse(Route::has('presentations.export.pdf'));
        $this->assertFalse(Route::has('presentations.force-save'));
        $this->assertFalse(Route::has('presentations.file.signed'));
        $this->assertFalse(Route::has('presentations.onlyoffice.callback'));
    }

    public function test_removed_ppt_generation_tables_are_dropped(): void
    {
        $this->assertFalse(Schema::hasTable('presentations'));
        $this->assertFalse(Schema::hasTable('presentation_versions'));
    }
}
