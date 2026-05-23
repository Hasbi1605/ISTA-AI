<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\AdminDocuments;
use App\Livewire\Admin\AdminErrors;
use App\Livewire\Admin\AdminUsage;
use App\Livewire\Admin\AdminUsers;
use App\Models\AIUsageEvent;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\Memo;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class AdminMonitoringDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_overview_shows_kpi_cards_chart_and_recent_event_table(): void
    {
        $now = Carbon::parse('2026-05-18 12:00:00');
        Carbon::setTestNow($now);

        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'last_seen_at' => $now->copy()->subSeconds(15),
        ]);

        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'last_seen_at' => $now->copy()->subMinutes(7),
        ]);

        $this->makeEvent($user->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_COMPLETED, AIUsageEvent::STATUS_SUCCESS, $now->copy()->subHour(), 'req-1', 1200);
        $this->makeEvent($user->id, AIUsageEvent::FEATURE_DOCUMENT_RAG, AIUsageEvent::ACTION_FAILED, AIUsageEvent::STATUS_ERROR, $now->copy()->subMinutes(15), 'req-2', 700, 'rag_timeout');

        $response = $this->actingAs($admin)->get('/admin');
        $response->assertOk();
        $response->assertSee('Ringkasan Operasional', false);
        $response->assertSee('Aktivitas Terbaru', false);
        $response->assertSee('Insiden Terbaru', false);
        $response->assertSee('Detail lengkap ada di tab Usage.', false);
        $response->assertDontSee('Distribusi Fitur', false);
        $response->assertDontSee('Error Terbaru', false);
        $response->assertSee('chat', false);
        $response->assertSee('document_rag', false);
        $response->assertSee('rag_timeout', false);
        $response->assertSee('admin-kpi', false);
        $response->assertDontSee('Belum ada pembanding', false);
        $response->assertSee('Terakhir diperbarui:', false);
        $response->assertDontSee('↑ 18% dari kemarin', false);
        $response->assertDontSee('↓ 33% dari 7 hari lalu', false);

        Carbon::setTestNow();
    }

    public function test_admin_users_page_shows_presence_status_for_each_user(): void
    {
        $now = Carbon::parse('2026-05-18 12:00:00');
        Carbon::setTestNow($now);

        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'last_seen_at' => $now,
            'name' => 'Admin Aktif',
        ]);

        User::factory()->create([
            'role' => User::ROLE_USER,
            'name' => 'User Online',
            'last_seen_at' => $now->copy()->subSeconds(30),
        ]);

        User::factory()->create([
            'role' => User::ROLE_USER,
            'name' => 'User Idle',
            'last_seen_at' => $now->copy()->subMinutes(8),
        ]);

        User::factory()->create([
            'role' => User::ROLE_USER,
            'name' => 'User Offline',
            'last_seen_at' => $now->copy()->subDays(2),
        ]);

        $response = $this->actingAs($admin)->get('/admin/users');
        $response->assertOk();
        $response->assertSeeText('User & Presence');
        $response->assertDontSeeText('User dan Presence');
        $response->assertSee('wire:poll.30s', false);
        $response->assertSee('Ringkasan status user tanpa membuka isi percakapan', false);
        $response->assertDontSee('Pantau status online/idle/offline user dan ringkasan aktivitas mereka.', false);
        $response->assertSee('Menampilkan 15 user per halaman.', false);
        $response->assertSee('Total User', false);
        $response->assertSee('admin-users-kpi-card', false);
        $response->assertSee('admin-users-kpi-card__icon', false);
        $response->assertSee('admin-user-avatar', false);
        $response->assertSee('User Online', false);
        $response->assertSee('User Idle', false);
        $response->assertSee('User Offline', false);
        $response->assertSee('Online', false);
        $response->assertSee('Idle', false);
        $response->assertSee('Offline', false);
        $response->assertDontSee('Event Hari Ini', false);
        $response->assertDontSee('Event 7 Hari', false);

        Carbon::setTestNow();
    }

    public function test_admin_users_filter_by_status_returns_only_online_users(): void
    {
        $now = Carbon::parse('2026-05-18 12:00:00');
        Carbon::setTestNow($now);

        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'last_seen_at' => $now,
            'name' => 'Admin Aktif',
        ]);

        User::factory()->create([
            'role' => User::ROLE_USER,
            'name' => 'Online Person',
            'last_seen_at' => $now->copy()->subSeconds(30),
        ]);

        User::factory()->create([
            'role' => User::ROLE_USER,
            'name' => 'Idle Person',
            'last_seen_at' => $now->copy()->subMinutes(8),
        ]);

        $this->actingAs($admin);

        Livewire::test(AdminUsers::class)
            ->set('status', 'online')
            ->assertSee('Online Person')
            ->assertDontSee('Admin Aktif')
            ->assertDontSee('Idle Person');

        Carbon::setTestNow();
    }

    public function test_super_admin_can_delete_regular_user_from_users_page(): void
    {
        $superAdmin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ]);
        $regular = User::factory()->create([
            'role' => User::ROLE_USER,
            'email' => 'delete-me@example.test',
        ]);

        $this->actingAs($superAdmin);

        Livewire::test(AdminUsers::class)
            ->assertSee('delete-me@example.test', false)
            ->assertSee('admin-users-delete-button', false)
            ->call('deleteUser', $regular->id)
            ->assertSee('berhasil dihapus');

        $this->assertDatabaseMissing('users', ['id' => $regular->id]);
    }

    public function test_users_page_delete_action_refuses_admin_family_targets(): void
    {
        $superAdmin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ]);
        $adminTarget = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $this->actingAs($superAdmin);

        Livewire::test(AdminUsers::class)
            ->call('deleteUser', $adminTarget->id)
            ->assertStatus(404);
    }

    public function test_admin_users_pagination_keeps_full_controls_after_livewire_page_change(): void
    {
        $now = Carbon::parse('2026-05-18 12:00:00');
        Carbon::setTestNow($now);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        for ($index = 1; $index <= 31; $index++) {
            User::factory()->create([
                'role' => User::ROLE_USER,
                'name' => 'User Page '.$index,
                'email' => 'user-page-'.$index.'@example.test',
                'last_seen_at' => $now->copy()->subMinutes($index),
            ]);
        }

        $this->actingAs($admin);

        Livewire::test(AdminUsers::class)
            ->assertSee('user-page-1@example.test', false)
            ->assertDontSee('user-page-16@example.test', false)
            ->call('gotoPage', 2)
            ->assertSee('user-page-16@example.test', false)
            ->assertSee('admin-users-pagination-2-3-31-16-30', false)
            ->assertSee('admin-pagination-page-2-3-16-30', false)
            ->assertSee('gotoPage(3, \'page\')', false)
            ->assertDontSee('user-page-1@example.test', false);

        Carbon::setTestNow();
    }

    public function test_admin_usage_filter_by_feature(): void
    {
        $now = Carbon::parse('2026-05-18 12:00:00');
        Carbon::setTestNow($now);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $this->makeEvent($user->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_COMPLETED, AIUsageEvent::STATUS_SUCCESS, $now->copy()->subHour(), 'req-chat', null, null, [
            'model_label' => 'GPT-4.1 (Primary)',
            'model_name' => 'openai/gpt-4.1',
            'model_provider' => 'github_models',
        ]);
        $this->makeEvent($user->id, AIUsageEvent::FEATURE_DOCUMENT_RAG, AIUsageEvent::ACTION_COMPLETED, AIUsageEvent::STATUS_SUCCESS, $now->copy()->subMinutes(30), 'req-rag', null, null, [
            'model_label' => 'RAG Model',
            'model_name' => 'internal/rag',
            'model_provider' => 'internal',
        ]);

        $this->actingAs($admin);

        Livewire::test(AdminUsage::class)
            ->set('feature', AIUsageEvent::FEATURE_CHAT)
            ->assertSee('Usage Events')
            ->assertSee('admin-usage-kpi-card', false)
            ->assertSee('admin-usage-kpi-card__icon', false)
            ->assertSee('Distribusi Fitur')
            ->assertSee('Event Terbaru')
            ->assertSee('Model')
            ->assertSee('GPT-4.1 (Primary)', false)
            ->assertDontSee('Detail')
            ->assertDontSee('RAG Model', false);

        Carbon::setTestNow();
    }

    public function test_admin_usage_shows_document_embedding_provider_from_upload_subject(): void
    {
        $now = Carbon::parse('2026-05-18 12:00:00');
        Carbon::setTestNow($now);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $document = Document::create([
            'user_id' => $user->id,
            'filename' => 'referensi.pdf',
            'original_name' => 'referensi.pdf',
            'file_path' => 'docs/referensi.pdf',
            'status' => 'ready',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 1024,
            'embedding_provider' => 'text-embedding-3-small',
            'indexed_at' => $now,
        ]);

        $event = $this->makeEvent(
            $user->id,
            AIUsageEvent::FEATURE_DOCUMENT_UPLOAD,
            AIUsageEvent::ACTION_COMPLETED,
            AIUsageEvent::STATUS_SUCCESS,
            $now,
            'req-upload',
            120,
            null,
            ['document_id' => $document->id],
        );

        $event->forceFill([
            'subject_id' => $document->id,
            'subject_type' => Document::class,
        ])->save();

        $this->actingAs($admin);

        Livewire::test(AdminUsage::class)
            ->assertSee('Upload Dokumen')
            ->assertSee('text-embedding-3-small', false);

        Carbon::setTestNow();
    }

    public function test_admin_usage_hides_started_lifecycle_events_by_default(): void
    {
        $now = Carbon::parse('2026-05-18 12:00:00');
        Carbon::setTestNow($now);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $this->makeEvent($user->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_STARTED, AIUsageEvent::STATUS_PENDING, $now->copy()->subMinute(), 'req-started');
        $this->makeEvent($user->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_COMPLETED, AIUsageEvent::STATUS_SUCCESS, $now->copy()->subMinutes(2), 'req-completed');

        $this->actingAs($admin);

        Livewire::test(AdminUsage::class)
            ->assertSee('started disembunyikan', false)
            ->assertSee('100% sukses', false)
            ->assertSee('Tidak ada pending', false)
            ->assertDontSee('STARTED', false)
            ->assertSee('COMPLETED', false)
            ->set('showLifecycleEvents', true)
            ->assertSee('50% sukses', false)
            ->assertSee('50% pending', false)
            ->assertSee('STARTED', false);

        Carbon::setTestNow();
    }

    public function test_admin_usage_paginates_event_table_at_five_rows(): void
    {
        $now = Carbon::parse('2026-05-18 12:00:00');
        Carbon::setTestNow($now);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        for ($index = 1; $index <= 6; $index++) {
            $rowUser = User::factory()->create([
                'role' => User::ROLE_USER,
                'email' => 'usage-row-'.$index.'@example.test',
            ]);

            $this->makeEvent(
                $rowUser->id,
                AIUsageEvent::FEATURE_CHAT,
                AIUsageEvent::ACTION_COMPLETED,
                AIUsageEvent::STATUS_SUCCESS,
                $now->copy()->subMinutes($index),
                'req-page-'.$index,
            );
        }

        $this->actingAs($admin);

        Livewire::test(AdminUsage::class)
            ->assertSee('Menampilkan 5 event per halaman')
            ->assertSee('usage-row-1@example.test', false)
            ->assertDontSee('usage-row-6@example.test', false)
            ->call('gotoPage', 2)
            ->assertSee('usage-row-6@example.test', false)
            ->assertDontSee('usage-row-1@example.test', false);

        Carbon::setTestNow();
    }

    public function test_admin_usage_pagination_keeps_full_controls_after_livewire_page_change(): void
    {
        $now = Carbon::parse('2026-05-18 12:00:00');
        Carbon::setTestNow($now);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        for ($index = 1; $index <= 66; $index++) {
            $this->makeEvent(
                $user->id,
                AIUsageEvent::FEATURE_CHAT,
                AIUsageEvent::ACTION_COMPLETED,
                AIUsageEvent::STATUS_SUCCESS,
                $now->copy()->subMinutes($index),
                'req-usage-window-'.$index,
            );
        }

        $this->actingAs($admin);

        Livewire::test(AdminUsage::class)
            ->assertSee('Menampilkan 1-5', false)
            ->assertSee('gotoPage(14, \'page\')', false)
            ->call('gotoPage', 3)
            ->assertSee('Menampilkan 11-15', false)
            ->assertSee('admin-usage-pagination-3-14-66-11-15', false)
            ->assertSee('admin-pagination-page-3-14-11-15', false)
            ->assertSee('gotoPage(4, \'page\')', false)
            ->assertSee('gotoPage(14, \'page\')', false)
            ->assertSee('nextPage(\'page\')', false);

        Carbon::setTestNow();
    }

    public function test_admin_usage_end_date_filter_includes_the_full_selected_day(): void
    {
        $now = Carbon::parse('2026-05-20 12:00:00');
        Carbon::setTestNow($now);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $includedUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'email' => 'usage-end-day@example.test',
        ]);
        $excludedUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'email' => 'usage-next-day@example.test',
        ]);

        $this->makeEvent($includedUser->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_COMPLETED, AIUsageEvent::STATUS_SUCCESS, Carbon::parse('2026-05-18 23:45:00'), 'req-end-day');
        $this->makeEvent($excludedUser->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_COMPLETED, AIUsageEvent::STATUS_SUCCESS, Carbon::parse('2026-05-19 00:01:00'), 'req-next-day');

        $this->actingAs($admin);

        Livewire::test(AdminUsage::class)
            ->set('startDate', '2026-05-18')
            ->set('endDate', '2026-05-18')
            ->assertSee('usage-end-day@example.test', false)
            ->assertDontSee('usage-next-day@example.test', false);

        Carbon::setTestNow();
    }

    public function test_admin_errors_page_only_shows_failed_or_blocked_events(): void
    {
        $now = Carbon::parse('2026-05-18 12:00:00');
        Carbon::setTestNow($now);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $this->makeEvent($user->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_COMPLETED, AIUsageEvent::STATUS_SUCCESS, $now->copy()->subHour(), 'req-success');
        $this->makeEvent($user->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_FAILED, AIUsageEvent::STATUS_ERROR, $now->copy()->subMinutes(30), 'req-fail', null, 'error_sentinel', [
            'model_label' => 'GPT-4o (Primary)',
            'model_name' => 'openai/gpt-4o',
            'model_provider' => 'github_models',
        ]);

        $response = $this->actingAs($admin)->get('/admin/errors');
        $response->assertOk();
        $response->assertSee('Error Operasional', false);
        $response->assertSee('admin-errors-kpi-card', false);
        $response->assertSee('admin-errors-kpi-card__icon', false);
        $response->assertSee('Menampilkan 5 error per halaman', false);
        $response->assertSee('ERROR SENTINEL', false);
        $response->assertSee('Severity', false);
        $response->assertSee('High', false);
        $response->assertSee('Detail', false);
        $response->assertDontSee('req-success', false);

        Carbon::setTestNow();
    }

    public function test_admin_errors_detail_modal_shows_guidance_and_safe_metadata(): void
    {
        $now = Carbon::parse('2026-05-18 12:00:00');
        Carbon::setTestNow($now);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $event = $this->makeEvent($user->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_FAILED, AIUsageEvent::STATUS_ERROR, $now->copy()->subMinutes(10), 'req-detail', 1200, 'error_sentinel', [
            'conversation_id' => 99,
            'channel' => 'stream',
            'model_label' => 'GPT-4o (Primary)',
            'model_name' => 'openai/gpt-4o',
            'model_provider' => 'github_models',
        ]);

        $this->actingAs($admin);

        Livewire::test(AdminErrors::class)
            ->call('showDetail', $event->id)
            ->assertSee('Detail Error')
            ->assertSee('Langkah Penanganan')
            ->assertSee('Kemungkinan Penyebab')
            ->assertSee('GPT-4o (Primary)', false)
            ->assertSee('req-detail', false)
            ->assertSee('Cari request ID di log Laravel dan Python AI.');

        Carbon::setTestNow();
    }

    public function test_admin_errors_paginates_error_table_at_five_rows(): void
    {
        $now = Carbon::parse('2026-05-18 12:00:00');
        Carbon::setTestNow($now);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        for ($index = 1; $index <= 6; $index++) {
            $rowUser = User::factory()->create([
                'role' => User::ROLE_USER,
                'email' => 'error-row-'.$index.'@example.test',
            ]);

            $this->makeEvent(
                $rowUser->id,
                AIUsageEvent::FEATURE_CHAT,
                AIUsageEvent::ACTION_FAILED,
                AIUsageEvent::STATUS_ERROR,
                $now->copy()->subMinutes($index),
                'req-error-'.$index,
                null,
                'error_code_'.$index,
            );
        }

        $this->actingAs($admin);

        Livewire::test(AdminErrors::class)
            ->assertSee('Menampilkan 5 error per halaman')
            ->assertSee('error-row-1@example.test', false)
            ->assertDontSee('error-row-6@example.test', false)
            ->call('gotoPage', 2)
            ->assertSee('error-row-6@example.test', false)
            ->assertSee('admin-errors-pagination-2-2-6-6-6', false)
            ->assertSee('admin-pagination-page-2-2-6-6', false)
            ->assertDontSee('error-row-1@example.test', false);

        Carbon::setTestNow();
    }

    public function test_admin_documents_page_shows_status_kpis(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $owner = User::factory()->create(['role' => User::ROLE_USER]);

        Document::create([
            'user_id' => $owner->id,
            'filename' => 'a.pdf',
            'original_name' => 'a.pdf',
            'file_path' => 'docs/a.pdf',
            'status' => 'ready',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 1024,
        ]);

        Document::create([
            'user_id' => $owner->id,
            'filename' => 'b.pdf',
            'original_name' => 'b.pdf',
            'file_path' => 'docs/b.pdf',
            'status' => 'error',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 2048,
        ]);

        $response = $this->actingAs($admin)->get('/admin/documents');
        $response->assertOk();
        $response->assertSee('Dokumen User', false);
        $response->assertSee('admin-documents-kpi-card', false);
        $response->assertSee('admin-documents-kpi-card__icon', false);
        $response->assertSee('Maksimum 10 baris', false);
        $response->assertSee('Distribusi Tipe', false);
        $response->assertSee('Status Pipeline', false);
        $response->assertSee('admin-documents-type-donut', false);
        $response->assertSee('admin-documents-pipeline-summary', false);
        $response->assertSee('PDF', false);
        $response->assertSee('Dokumen Terbaru', false);
        $response->assertSee('Chunks', false);
        $response->assertDontSee('Pipeline Dokumen', false);
        $response->assertDontSee('admin-documents-type-label', false);
        $response->assertSee('admin-documents-file-icon--pdf', false);
        $response->assertSee('admin-status-chip--success', false);
        $response->assertSee('admin-status-chip--danger', false);
        $response->assertSee('Detail', false);
        $response->assertSee('a.pdf', false);
        $response->assertSee('b.pdf', false);
        $response->assertSee('Ready', false);
        $response->assertSee('Failed', false);
    }

    public function test_admin_documents_filters_by_type_owner_and_date(): void
    {
        $now = Carbon::parse('2026-05-18 12:00:00');
        Carbon::setTestNow($now);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $includedOwner = User::factory()->create([
            'role' => User::ROLE_USER,
            'email' => 'included-owner@example.test',
        ]);
        $otherOwner = User::factory()->create([
            'role' => User::ROLE_USER,
            'email' => 'other-owner@example.test',
        ]);

        $included = Document::create([
            'user_id' => $includedOwner->id,
            'filename' => 'finance.csv',
            'original_name' => 'finance.csv',
            'file_path' => 'docs/finance.csv',
            'status' => 'ready',
            'mime_type' => 'text/csv',
            'file_size_bytes' => 1024,
        ]);
        $included->timestamps = false;
        $included->created_at = Carbon::parse('2026-05-18 09:00:00');
        $included->updated_at = Carbon::parse('2026-05-18 09:00:00');
        $included->save();

        $excludedByType = Document::create([
            'user_id' => $includedOwner->id,
            'filename' => 'finance.pdf',
            'original_name' => 'finance.pdf',
            'file_path' => 'docs/finance.pdf',
            'status' => 'ready',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 2048,
        ]);
        $excludedByType->timestamps = false;
        $excludedByType->created_at = Carbon::parse('2026-05-18 10:00:00');
        $excludedByType->updated_at = Carbon::parse('2026-05-18 10:00:00');
        $excludedByType->save();

        $excludedByOwner = Document::create([
            'user_id' => $otherOwner->id,
            'filename' => 'other.csv',
            'original_name' => 'other.csv',
            'file_path' => 'docs/other.csv',
            'status' => 'ready',
            'mime_type' => 'text/csv',
            'file_size_bytes' => 1024,
        ]);
        $excludedByOwner->timestamps = false;
        $excludedByOwner->created_at = Carbon::parse('2026-05-18 11:00:00');
        $excludedByOwner->updated_at = Carbon::parse('2026-05-18 11:00:00');
        $excludedByOwner->save();

        $this->actingAs($admin);

        Livewire::test(AdminDocuments::class)
            ->set('type', 'csv')
            ->set('ownerId', (string) $includedOwner->id)
            ->set('startDate', '2026-05-18')
            ->set('endDate', '2026-05-18')
            ->assertSee('finance.csv', false)
            ->assertDontSee('finance.pdf', false)
            ->assertDontSee('other.csv', false);

        Carbon::setTestNow();
    }

    public function test_admin_documents_detail_modal_shows_compact_metadata(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $owner = User::factory()->create(['role' => User::ROLE_USER]);

        $document = Document::create([
            'user_id' => $owner->id,
            'filename' => 'ai-policy.pdf',
            'original_name' => 'ai-policy.pdf',
            'file_path' => 'docs/ai-policy.pdf',
            'status' => 'ready',
            'preview_status' => Document::PREVIEW_STATUS_READY,
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 4096,
            'source_provider' => 'google_drive',
            'source_external_id' => 'drive-document-id',
            'source_synced_at' => Carbon::parse('2026-05-18 08:00:00'),
            'indexed_chunk_count' => 2,
            'embedding_provider' => 'openai',
            'indexed_at' => Carbon::parse('2026-05-18 08:05:00'),
        ]);

        DocumentChunk::create([
            'document_id' => $document->id,
            'page_number' => 1,
            'text_content' => 'Hidden chunk content must not be rendered.',
        ]);
        DocumentChunk::create([
            'document_id' => $document->id,
            'page_number' => 2,
            'text_content' => 'Another hidden chunk.',
        ]);

        $this->actingAs($admin);

        Livewire::test(AdminDocuments::class)
            ->call('showDetail', $document->id)
            ->assertSee('Document Detail')
            ->assertSee('Status AI')
            ->assertSee('admin-documents-stage-list', false)
            ->assertSee('Metadata ringkas')
            ->assertSee('Original file')
            ->assertSee('Uploaded')
            ->assertSee('Source')
            ->assertSee('Source ID')
            ->assertSee('drive-document-id')
            ->assertSee('Chunks')
            ->assertSee('2')
            ->assertSee('Indexed')
            ->assertSee('Embedding')
            ->assertSee('openai')
            ->assertSee('2026-05-18 08:05:00')
            ->assertSee('GOOGLE DRIVE', false)
            ->assertDontSee('Stored file')
            ->assertDontSee('Hidden chunk content must not be rendered.', false);
    }

    public function test_admin_documents_detail_marks_empty_index_when_ready_document_has_no_chunks(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $owner = User::factory()->create(['role' => User::ROLE_USER]);

        $document = Document::create([
            'user_id' => $owner->id,
            'filename' => 'empty-index.pdf',
            'original_name' => 'empty-index.pdf',
            'file_path' => 'docs/empty-index.pdf',
            'status' => 'ready',
            'preview_status' => Document::PREVIEW_STATUS_READY,
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 4096,
            'indexed_chunk_count' => 0,
            'indexed_at' => Carbon::parse('2026-05-18 08:00:00'),
        ]);

        $this->actingAs($admin);

        Livewire::test(AdminDocuments::class)
            ->call('showDetail', $document->id)
            ->assertSee('Index kosong')
            ->assertDontSee('chunk siap');
    }

    public function test_admin_documents_paginates_document_table_at_ten_rows(): void
    {
        $now = Carbon::parse('2026-05-18 12:00:00');
        Carbon::setTestNow($now);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $owner = User::factory()->create(['role' => User::ROLE_USER]);

        for ($index = 1; $index <= 11; $index++) {
            $document = Document::create([
                'user_id' => $owner->id,
                'filename' => 'document-row-'.$index.'.pdf',
                'original_name' => 'document-row-'.$index.'.pdf',
                'file_path' => 'docs/document-row-'.$index.'.pdf',
                'status' => 'ready',
                'mime_type' => 'application/pdf',
                'file_size_bytes' => 1024,
            ]);

            $document->timestamps = false;
            $document->created_at = $now->copy()->subMinutes($index);
            $document->updated_at = $now->copy()->subMinutes($index);
            $document->save();
        }

        $this->actingAs($admin);

        Livewire::test(AdminDocuments::class)
            ->assertSee('Maksimum 10 baris')
            ->assertSee('Menampilkan', false)
            ->assertSee('dari 11', false)
            ->assertSee('admin-pagination__link--active', false)
            ->assertDontSee('Showing', false)
            ->assertSee('document-row-1.pdf', false)
            ->assertDontSee('document-row-11.pdf', false)
            ->call('gotoPage', 2)
            ->assertSee('document-row-11.pdf', false)
            ->assertSee('admin-documents-pagination-2-2-11-11-11', false)
            ->assertSee('admin-pagination-page-2-2-11-11', false)
            ->assertDontSee('document-row-1.pdf', false);

        Carbon::setTestNow();
    }

    public function test_regular_user_cannot_access_monitoring_pages(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $this->actingAs($user)->get('/admin/users')->assertRedirect(route('admin.login'));
        $this->actingAs($user)->get('/admin/usage')->assertRedirect(route('admin.login'));
        $this->actingAs($user)->get('/admin/errors')->assertRedirect(route('admin.login'));
        $this->actingAs($user)->get('/admin/documents')->assertRedirect(route('admin.login'));
    }

    public function test_guest_is_redirected_to_admin_login_for_monitoring_pages(): void
    {
        $this->get('/admin/users')->assertRedirect(route('admin.login'));
        $this->get('/admin/usage')->assertRedirect(route('admin.login'));
        $this->get('/admin/errors')->assertRedirect(route('admin.login'));
        $this->get('/admin/documents')->assertRedirect(route('admin.login'));
    }

    public function test_admin_pages_do_not_leak_message_content(): void
    {
        $now = Carbon::parse('2026-05-18 12:00:00');
        Carbon::setTestNow($now);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $secret = 'SECRET_PROMPT_NOT_FOR_DASHBOARD_'.uniqid();

        $conv = Conversation::create([
            'user_id' => $user->id,
            'title' => $secret,
        ]);

        Message::create([
            'conversation_id' => $conv->id,
            'role' => 'user',
            'content' => $secret,
        ]);

        AIUsageEvent::create([
            'user_id' => $user->id,
            'feature' => AIUsageEvent::FEATURE_CHAT,
            'action' => AIUsageEvent::ACTION_COMPLETED,
            'status' => AIUsageEvent::STATUS_SUCCESS,
            'request_id' => 'req-no-leak',
            'metadata' => null,
        ]);

        Memo::create([
            'user_id' => $user->id,
            'title' => $secret,
            'memo_type' => 'memo_internal',
            'status' => Memo::STATUS_DRAFT,
            'searchable_text' => $secret,
        ]);

        $this->actingAs($admin)->get('/admin')->assertOk()->assertDontSee($secret);
        $this->actingAs($admin)->get('/admin/users')->assertOk()->assertDontSee($secret);
        $this->actingAs($admin)->get('/admin/usage')->assertOk()->assertDontSee($secret);
        $this->actingAs($admin)->get('/admin/errors')->assertOk()->assertDontSee($secret);
        $this->actingAs($admin)->get('/admin/documents')->assertOk()->assertDontSee($secret);

        Carbon::setTestNow();
    }

    public function test_admin_sidebar_shows_monitoring_menu_links(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)->get('/admin');
        $response->assertOk();
        $response->assertSee(route('admin.users'), false);
        $response->assertSee(route('admin.usage'), false);
        $response->assertSee('M4 19V10m5 9V5m5 14v-7m5 7V8M3 19h18', false);
        $response->assertSee(route('admin.errors'), false);
        $response->assertSee(route('admin.documents'), false);
    }

    public function test_admin_usage_handles_malformed_date_query_strings_gracefully(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        // Malformed start/end dates from the query string must not raise
        // a 500. The page should render normally and ignore the invalid
        // values rather than passing them into Carbon::parse().
        $response = $this->actingAs($admin)->get('/admin/usage?startDate=not-a-date&endDate=also-bad');
        $response->assertOk();
        $response->assertSee('Usage Events', false);

        // Empty values must also be tolerated.
        $response = $this->actingAs($admin)->get('/admin/usage?startDate=&endDate=');
        $response->assertOk();
        $response->assertSee('Usage Events', false);
    }

    public function test_admin_usage_livewire_component_handles_invalid_date_input(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin);

        Livewire::test(AdminUsage::class)
            ->set('startDate', 'banana')
            ->set('endDate', 'pineapple')
            ->assertOk()
            ->assertSee('Usage Events');
    }

    private function makeEvent(int $userId, string $feature, string $action, string $status, Carbon $createdAt, ?string $requestId = null, ?int $latencyMs = null, ?string $errorCode = null, ?array $metadata = null): AIUsageEvent
    {
        $event = new AIUsageEvent([
            'user_id' => $userId,
            'feature' => $feature,
            'action' => $action,
            'status' => $status,
            'request_id' => $requestId,
            'latency_ms' => $latencyMs,
            'error_code' => $errorCode,
            'metadata' => $metadata,
        ]);
        $event->timestamps = false;
        $event->created_at = $createdAt;
        $event->updated_at = $createdAt;
        $event->save();

        return $event;
    }
}
