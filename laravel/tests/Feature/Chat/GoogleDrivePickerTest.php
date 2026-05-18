<?php

namespace Tests\Feature\Chat;

use App\Livewire\Chat\GoogleDrivePicker;
use App\Models\User;
use App\Services\CloudStorage\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class GoogleDrivePickerTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_google_drive_picker_lists_files_from_configured_root_folder(): void
    {
        $user = User::factory()->create();

        $googleDriveService = Mockery::mock(GoogleDriveService::class);
        $googleDriveService->shouldReceive('isConfigured')->andReturn(true);
        $googleDriveService->shouldReceive('rootFolderId')->andReturn('root-folder-id');
        $googleDriveService->shouldReceive('defaultUploadFolderName')->andReturn('ISTA AI');
        $googleDriveService->shouldReceive('sharedDriveId')->andReturn(null);
        $googleDriveService->shouldReceive('listFiles')
            ->once()
            ->with('root-folder-id', '', null, 20)
            ->andReturn([
                'items' => [
                    [
                        'id' => 'folder-1',
                        'name' => 'Subfolder',
                        'mime_type' => 'application/vnd.google-apps.folder',
                        'web_view_link' => null,
                        'modified_time' => '2026-05-04T01:00:00Z',
                        'size_bytes' => null,
                        'parents' => ['root-folder-id'],
                        'is_folder' => true,
                        'is_google_workspace_file' => false,
                        'is_processable' => false,
                    ],
                    [
                        'id' => 'file-1',
                        'name' => 'arsip.pdf',
                        'mime_type' => 'application/pdf',
                        'web_view_link' => 'https://drive.google.com/file/d/file-1/view',
                        'modified_time' => '2026-05-04T01:00:00Z',
                        'size_bytes' => 2048,
                        'parents' => ['root-folder-id'],
                        'is_folder' => false,
                        'is_google_workspace_file' => false,
                        'is_processable' => true,
                    ],
                ],
                'next_page_token' => 'page-2',
                'folder_id' => 'root-folder-id',
                'folder_name' => 'root-folder-id',
            ]);

        $this->app->instance(GoogleDriveService::class, $googleDriveService);

        Livewire::actingAs($user)
            ->test(GoogleDrivePicker::class)
            ->call('open')
            ->assertSet('isOpen', true)
            ->assertSee('Pilih file untuk chat', false)
            ->assertSee('ISTA AI', false)
            ->assertSee('Subfolder', false)
            ->assertSee('arsip.pdf', false)
            ->assertSee('Buka', false)
            ->assertSee('Pakai', false)
            ->assertSet('nextPageToken', 'page-2');
    }

    public function test_picker_renders_click_actions_with_valid_livewire_quoting(): void
    {
        $user = User::factory()->create();

        $googleDriveService = Mockery::mock(GoogleDriveService::class);
        $googleDriveService->shouldReceive('isConfigured')->andReturn(true);
        $googleDriveService->shouldReceive('rootFolderId')->andReturn('root-folder-id');
        $googleDriveService->shouldReceive('listFiles')
            ->once()
            ->with('root-folder-id', '', null, 20)
            ->andReturn([
                'items' => [
                    [
                        'id' => 'folder-1',
                        'name' => 'Subfolder',
                        'mime_type' => 'application/vnd.google-apps.folder',
                        'web_view_link' => null,
                        'modified_time' => '2026-05-04T01:00:00Z',
                        'size_bytes' => null,
                        'parents' => ['root-folder-id'],
                        'is_folder' => true,
                        'is_google_workspace_file' => false,
                        'is_processable' => false,
                    ],
                    [
                        'id' => 'file-1',
                        'name' => 'arsip.pdf',
                        'mime_type' => 'application/pdf',
                        'web_view_link' => 'https://drive.google.com/file/d/file-1/view',
                        'modified_time' => '2026-05-04T01:00:00Z',
                        'size_bytes' => 2048,
                        'parents' => ['root-folder-id'],
                        'is_folder' => false,
                        'is_google_workspace_file' => false,
                        'is_processable' => true,
                    ],
                ],
                'next_page_token' => null,
                'folder_id' => 'root-folder-id',
                'folder_name' => 'root-folder-id',
            ]);

        $this->app->instance(GoogleDriveService::class, $googleDriveService);

        $html = Livewire::actingAs($user)
            ->test(GoogleDrivePicker::class)
            ->call('open')
            ->html();

        $this->assertStringContainsString('wire:click="goToFolder(\'folder-1\', \'Subfolder\')"', $html);
        $this->assertStringContainsString('wire:click="processFile(\'file-1\')"', $html);
        $this->assertStringContainsString('wire:target="processFile(\'file-1\')"', $html);
        $this->assertStringNotContainsString('wire:target="processFile"', $html);
    }

    public function test_picker_renders_google_drive_shortcut_as_unsupported(): void
    {
        $user = User::factory()->create();

        $googleDriveService = Mockery::mock(GoogleDriveService::class);
        $googleDriveService->shouldReceive('isConfigured')->andReturn(true);
        $googleDriveService->shouldReceive('rootFolderId')->andReturn('root-folder-id');
        $googleDriveService->shouldReceive('listFiles')
            ->once()
            ->with('root-folder-id', '', null, 20)
            ->andReturn([
                'items' => [
                    [
                        'id' => 'shortcut-1',
                        'name' => 'Target shortcut',
                        'mime_type' => 'application/vnd.google-apps.shortcut',
                        'shortcut_target_id' => 'target-file-1',
                        'shortcut_target_mime_type' => 'application/pdf',
                        'web_view_link' => 'https://drive.google.com/file/d/shortcut-1/view',
                        'modified_time' => '2026-05-04T01:00:00Z',
                        'size_bytes' => null,
                        'parents' => ['root-folder-id'],
                        'is_folder' => false,
                        'is_google_workspace_file' => false,
                        'is_shortcut' => true,
                        'is_processable' => false,
                        'unsupported_reason' => 'Shortcut Google Drive belum didukung. Buka file target langsung lalu pilih file PDF, DOCX, XLSX, atau CSV.',
                    ],
                ],
                'next_page_token' => null,
                'folder_id' => 'root-folder-id',
                'folder_name' => 'root-folder-id',
            ]);

        $this->app->instance(GoogleDriveService::class, $googleDriveService);

        Livewire::actingAs($user)
            ->test(GoogleDrivePicker::class)
            ->call('open')
            ->assertSee('Shortcut', false)
            ->assertSee('Shortcut belum didukung', false)
            ->assertDontSee('Pakai untuk chat', false);
    }
}
