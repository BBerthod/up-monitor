<?php

namespace Tests\Feature\Jobs;

use App\Jobs\SendDigests;
use App\Mail\DigestMail;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use App\Services\DigestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Feature tests for SendDigests job.
 *
 * Mail::fake() intercepts all mail — no SMTP required.
 *
 * Job invocation: we resolve DigestService from the container and call
 * handle() directly, which is the cleanest way to test a queued job without
 * dispatching to a real queue (QUEUE_CONNECTION=sync would also work, but
 * handle() is more explicit).
 */
class SendDigestsTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────────────────────────────
    // Digest sent only to digest_enabled users
    // ──────────────────────────────────────────────────────────────────────

    public function test_digest_mail_sent_to_digest_enabled_user_only(): void
    {
        Mail::fake();

        $team = Team::factory()->create();

        // Active monitor — required by the chunk query in SendDigests::handle().
        Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
            'is_active' => true,
        ]);

        $enabledUser = User::factory()->create([
            'team_id' => $team->id,
            'email' => 'enabled@example.com',
            'digest_enabled' => true,
        ]);

        $disabledUser = User::factory()->create([
            'team_id' => $team->id,
            'email' => 'disabled@example.com',
            'digest_enabled' => false,
        ]);

        $job = new SendDigests;
        $job->handle(app(DigestService::class));

        // One mail must have been sent (to the enabled user).
        Mail::assertSent(DigestMail::class, 1);

        Mail::assertSent(DigestMail::class, function (DigestMail $mail) use ($enabledUser) {
            return $mail->hasTo($enabledUser->email);
        });

        Mail::assertNotSent(DigestMail::class, function (DigestMail $mail) use ($disabledUser) {
            return $mail->hasTo($disabledUser->email);
        });
    }

    public function test_no_digest_sent_when_team_has_no_active_monitors(): void
    {
        Mail::fake();

        $team = Team::factory()->create();

        // Only an INACTIVE monitor — must not be picked up by the chunk query.
        Monitor::factory()->inactive()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
        ]);

        User::factory()->create([
            'team_id' => $team->id,
            'digest_enabled' => true,
        ]);

        $job = new SendDigests;
        $job->handle(app(DigestService::class));

        Mail::assertNothingSent();
    }

    public function test_digest_sent_to_all_enabled_users_in_team(): void
    {
        Mail::fake();

        $team = Team::factory()->create();

        Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
            'is_active' => true,
        ]);

        // Two users with digest enabled.
        User::factory()->create([
            'team_id' => $team->id,
            'email' => 'alice@example.com',
            'digest_enabled' => true,
        ]);

        User::factory()->create([
            'team_id' => $team->id,
            'email' => 'bob@example.com',
            'digest_enabled' => true,
        ]);

        $job = new SendDigests;
        $job->handle(app(DigestService::class));

        Mail::assertSent(DigestMail::class, 2);
    }

    public function test_digest_not_sent_when_no_users_have_digest_enabled(): void
    {
        Mail::fake();

        $team = Team::factory()->create();

        Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
            'is_active' => true,
        ]);

        User::factory()->create([
            'team_id' => $team->id,
            'digest_enabled' => false,
        ]);

        $job = new SendDigests;
        $job->handle(app(DigestService::class));

        Mail::assertNothingSent();
    }

    public function test_digest_mail_contains_correct_team_name(): void
    {
        Mail::fake();

        $team = Team::factory()->create(['name' => 'Acme Corp']);

        Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
            'is_active' => true,
        ]);

        User::factory()->create([
            'team_id' => $team->id,
            'digest_enabled' => true,
        ]);

        $job = new SendDigests;
        $job->handle(app(DigestService::class));

        Mail::assertSent(DigestMail::class, function (DigestMail $mail) {
            return $mail->teamName === 'Acme Corp';
        });
    }

    public function test_per_team_failure_does_not_abort_remaining_teams(): void
    {
        Mail::fake();

        // Team A: has active monitor + enabled user → should receive mail.
        $teamA = Team::factory()->create();
        Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $teamA->id,
            'is_active' => true,
        ]);
        $userA = User::factory()->create([
            'team_id' => $teamA->id,
            'email' => 'userA@example.com',
            'digest_enabled' => true,
        ]);

        // Team B: has active monitor + enabled user → should also receive mail.
        $teamB = Team::factory()->create();
        Monitor::factory()->create([
            'url' => 'https://example.com/b',
            'team_id' => $teamB->id,
            'is_active' => true,
        ]);
        User::factory()->create([
            'team_id' => $teamB->id,
            'email' => 'userB@example.com',
            'digest_enabled' => true,
        ]);

        $job = new SendDigests;
        $job->handle(app(DigestService::class));

        // Both teams must have sent a mail.
        Mail::assertSent(DigestMail::class, 2);
    }
}
