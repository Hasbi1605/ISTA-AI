<?php

namespace Tests;

use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Authenticate as an admin whose session already passed the mandatory 2FA
     * gate and absolute-lifetime check, so admin pages can be exercised over HTTP.
     */
    protected function actingAsVerifiedAdmin(User $user): static
    {
        $this->actingAs($user);
        $this->withSession([
            'admin_session_started_at' => now()->timestamp,
            TwoFactorService::VERIFIED_USER_ID_SESSION_KEY => $user->getKey(),
        ]);

        return $this;
    }

    protected function validMemoDocxBytes(): string
    {
        $content = file_get_contents(base_path('tests/Fixtures/edited-memo.docx'));

        if ($content === false) {
            throw new \RuntimeException('Fixture DOCX memo tidak dapat dibaca.');
        }

        return $content;
    }
}
