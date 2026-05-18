<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class UserPresenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_presence_is_recorded_for_authenticated_user(): void
    {
        $user = User::factory()->create([
            'last_seen_at' => null,
            'last_active_feature' => null,
        ]);

        $this->actingAs($user)->get('/profile')->assertOk();

        $user->refresh();

        $this->assertNotNull($user->last_seen_at);
        $this->assertNotNull($user->last_active_feature);
        $this->assertSame('profile', $user->last_active_feature);
    }

    public function test_presence_does_not_update_within_throttle_window(): void
    {
        $user = User::factory()->create([
            'last_seen_at' => null,
        ]);

        $this->actingAs($user)->get('/profile')->assertOk();

        $user->refresh();
        $firstSeen = $user->last_seen_at;
        $this->assertNotNull($firstSeen);

        // Hit a second authenticated route within the throttle window.
        $this->actingAs($user)->get('/profile')->assertOk();

        $user->refresh();
        $this->assertNotNull($user->last_seen_at);
        $this->assertTrue(
            $firstSeen->equalTo($user->last_seen_at),
            'Presence timestamp should not change within the throttle window.'
        );
    }

    public function test_presence_is_skipped_for_guests(): void
    {
        $response = $this->get('/');
        $response->assertStatus(200);

        $this->assertSame(0, User::query()->whereNotNull('last_seen_at')->count());
    }
}
