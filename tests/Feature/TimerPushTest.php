<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TimerPush;
use App\Models\User;
use App\Notifications\TimerFinishedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TimerPushTest extends TestCase
{
    use RefreshDatabase;

    private function correr(): void
    {
        Artisan::call('timers:fire-due', ['--max-time' => 0, '--sleep' => 1]);
    }

    public function test_due_timer_notifies_user_and_is_marked_sent(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $timer = TimerPush::create([
            'user_id' => $user->id,
            'pool_id' => null,
            'fase' => 'lavagem',
            'fire_at' => now()->subSecond(),
        ]);

        $this->correr();

        Notification::assertSentTo($user, TimerFinishedNotification::class);
        $this->assertNotNull($timer->fresh()->sent_at);
    }

    public function test_future_cancelled_and_sent_timers_are_ignored(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        TimerPush::create([
            'user_id' => $user->id, 'fase' => 'lavagem',
            'fire_at' => now()->addMinutes(5),
        ]);
        TimerPush::create([
            'user_id' => $user->id, 'fase' => 'enxaguamento',
            'fire_at' => now()->subSecond(), 'cancelled_at' => now(),
        ]);
        TimerPush::create([
            'user_id' => $user->id, 'fase' => 'lavagem',
            'fire_at' => now()->subSecond(), 'sent_at' => now(),
        ]);

        $this->correr();

        Notification::assertNothingSent();
    }
}
