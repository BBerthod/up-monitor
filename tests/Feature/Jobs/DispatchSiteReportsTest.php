<?php

namespace Tests\Feature\Jobs;

use App\Enums\ReportFrequency;
use App\Jobs\DispatchSiteReports;
use App\Jobs\SendSiteReport;
use App\Models\Site;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchSiteReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dispatches_weekly_sites_on_monday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-21 07:30:00')); // Monday
        Queue::fake();

        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->weeklyReport()->create();

        (new DispatchSiteReports)->handle();

        Queue::assertPushed(SendSiteReport::class, fn (SendSiteReport $job) => $job->siteId === $site->id
            && $job->frequency === ReportFrequency::WEEKLY->value);
    }

    public function test_does_not_dispatch_weekly_sites_on_a_non_monday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-22 07:30:00')); // Tuesday
        Queue::fake();

        $team = Team::factory()->create();
        Site::factory()->for($team)->weeklyReport()->create();

        (new DispatchSiteReports)->handle();

        Queue::assertNotPushed(SendSiteReport::class);
    }

    public function test_dispatches_monthly_sites_on_the_first_of_the_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 07:30:00')); // Tuesday, day 1
        Queue::fake();

        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->monthlyReport()->create();

        (new DispatchSiteReports)->handle();

        Queue::assertPushed(SendSiteReport::class, fn (SendSiteReport $job) => $job->siteId === $site->id
            && $job->frequency === ReportFrequency::MONTHLY->value);
    }

    public function test_does_not_dispatch_monthly_sites_on_a_day_other_than_the_first(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-21 07:30:00')); // Monday but day 21
        Queue::fake();

        $team = Team::factory()->create();
        Site::factory()->for($team)->monthlyReport()->create();

        (new DispatchSiteReports)->handle();

        Queue::assertNotPushed(SendSiteReport::class);
    }

    public function test_is_idempotent_when_a_report_already_went_out_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-21 07:30:00')); // Monday
        Queue::fake();

        $team = Team::factory()->create();
        Site::factory()->for($team)->weeklyReport()->create([
            'last_report_sent_at' => Carbon::parse('2026-09-21 07:30:00'),
        ]);

        (new DispatchSiteReports)->handle();

        Queue::assertNotPushed(SendSiteReport::class);
    }

    public function test_dispatches_again_when_the_last_report_was_sent_before_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-21 07:30:00')); // Monday
        Queue::fake();

        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->weeklyReport()->create([
            'last_report_sent_at' => Carbon::parse('2026-09-14 07:30:00'),
        ]);

        (new DispatchSiteReports)->handle();

        Queue::assertPushed(SendSiteReport::class, fn (SendSiteReport $job) => $job->siteId === $site->id);
    }

    public function test_does_not_dispatch_sites_with_frequency_none(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-21 07:30:00')); // Monday
        Queue::fake();

        $team = Team::factory()->create();
        Site::factory()->for($team)->create(['report_frequency' => 'none']);

        (new DispatchSiteReports)->handle();

        Queue::assertNotPushed(SendSiteReport::class);
    }

    public function test_does_not_dispatch_inactive_sites(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-21 07:30:00')); // Monday
        Queue::fake();

        $team = Team::factory()->create();
        Site::factory()->for($team)->weeklyReport()->create(['is_active' => false]);

        (new DispatchSiteReports)->handle();

        Queue::assertNotPushed(SendSiteReport::class);
    }
}
