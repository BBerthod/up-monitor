<?php

namespace Tests\Feature\Jobs;

use App\Enums\ReportFrequency;
use App\Jobs\SendSiteReport;
use App\Mail\SiteReportMail;
use App\Models\Site;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendSiteReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-21 07:30:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_sends_to_the_sites_own_recipients_when_configured(): void
    {
        Mail::fake();
        Config::set('reports.default_recipients', ['fallback@example.com']);

        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->weeklyReport(['owner@example.com'])->create();

        (new SendSiteReport($site->id, ReportFrequency::WEEKLY->value))->handle(app(\App\Services\SiteReportService::class));

        Mail::assertSent(SiteReportMail::class, fn (SiteReportMail $mail) => $mail->hasTo('owner@example.com'));
        Mail::assertNotSent(SiteReportMail::class, fn (SiteReportMail $mail) => $mail->hasTo('fallback@example.com'));
    }

    public function test_falls_back_to_config_default_recipients_when_site_has_none(): void
    {
        Mail::fake();
        Config::set('reports.default_recipients', ['fallback@example.com']);

        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->weeklyReport()->create();

        (new SendSiteReport($site->id, ReportFrequency::WEEKLY->value))->handle(app(\App\Services\SiteReportService::class));

        Mail::assertSent(SiteReportMail::class, fn (SiteReportMail $mail) => $mail->hasTo('fallback@example.com'));
    }

    public function test_skips_sending_when_no_recipients_are_configured_anywhere(): void
    {
        Mail::fake();
        Config::set('reports.default_recipients', []);

        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->weeklyReport()->create();

        (new SendSiteReport($site->id, ReportFrequency::WEEKLY->value))->handle(app(\App\Services\SiteReportService::class));

        Mail::assertNothingSent();
        $this->assertNull($site->fresh()->last_report_sent_at);
    }

    public function test_stamps_last_report_sent_at_after_sending(): void
    {
        Mail::fake();
        Config::set('reports.default_recipients', ['fallback@example.com']);

        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->weeklyReport()->create();

        (new SendSiteReport($site->id, ReportFrequency::WEEKLY->value))->handle(app(\App\Services\SiteReportService::class));

        $this->assertSame('2026-09-21 07:30:00', $site->fresh()->last_report_sent_at->toDateTimeString());
    }
}
