<?php

namespace Tests\Feature\Mail;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Enums\ReportFrequency;
use App\Mail\SiteReportMail;
use App\Models\Insight;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorLighthouseScore;
use App\Models\Site;
use App\Models\Team;
use App\Services\SiteReportPdf;
use App\Services\SiteReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class SiteReportMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-21 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_mail_renders_key_values_in_html(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create([
            'alias' => 'my-site',
            'primary_domain' => 'example.com',
        ]);
        Monitor::factory()->for($team)->create(['site_id' => $site->id, 'name' => 'Homepage']);

        $report = app(SiteReportService::class)->generate($site, ReportFrequency::WEEKLY);

        $mailable = new SiteReportMail($report);

        $mailable->assertSeeInHtml('RADIANK');
        $mailable->assertSeeInHtml('my-site');
        $mailable->assertSeeInHtml('example.com');
        $mailable->assertSeeInHtml('Homepage');

        // Plain-French explanations for non-technical readers, and the
        // rule-based "En bref" summary — both must be present, not just the
        // raw numbers. The old KPI row (with its own uptime/response-time
        // captions) was removed from the mail for consistency with the
        // gauges — the gauges section carries the explanation now.
        $mailable->assertSeeInHtml('En bref :');
        $mailable->assertSeeInHtml('Où se situe votre site');
    }

    public function test_subject_mentions_frequency_and_period(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create(['alias' => 'my-site']);

        $report = app(SiteReportService::class)->generate($site, ReportFrequency::MONTHLY);

        $mailable = new SiteReportMail($report);
        $envelope = $mailable->envelope();

        $this->assertStringContainsString('mensuel', $envelope->subject);
        $this->assertStringContainsString('my-site', $envelope->subject);
    }

    public function test_mail_has_a_pdf_attachment(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create(['alias' => 'my-site']);

        $report = app(SiteReportService::class)->generate($site, ReportFrequency::WEEKLY);

        $mailable = new SiteReportMail($report);
        $attachments = $mailable->attachments();

        $this->assertCount(1, $attachments);
        $this->assertSame('application/pdf', $attachments[0]->mime);
        $this->assertStringStartsWith('rapport-my-site-', $attachments[0]->as);
        $this->assertStringEndsWith('.pdf', $attachments[0]->as);
    }

    public function test_pdf_renderer_produces_a_valid_pdf_document(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create();

        $report = app(SiteReportService::class)->generate($site, ReportFrequency::WEEKLY);

        $bytes = app(SiteReportPdf::class)->render($report);

        $this->assertStringStartsWith('%PDF-', $bytes);
    }

    public function test_pdf_template_includes_explanations_and_a_lexicon(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create();

        $report = app(SiteReportService::class)->generate($site, ReportFrequency::WEEKLY);

        $html = View::make('reports.site-report-pdf', ['report' => $report])->render();

        $this->assertStringContainsString('En bref :', $html);
        $this->assertStringContainsString('LEXIQUE', $html);
        $this->assertStringContainsString('Score de santé', $html);
        $this->assertStringContainsString('rang moyen dans les résultats', $html);
    }

    public function test_mail_renders_the_gauges_section_when_a_gauge_has_data(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create(['alias' => 'my-site']);
        $monitor = Monitor::factory()->for($team)->create(['site_id' => $site->id]);
        MonitorCheck::factory()->create([
            'monitor_id' => $monitor->id,
            'checked_at' => '2026-09-15 12:00:00',
            'response_time_ms' => 300,
        ]);

        $report = app(SiteReportService::class)->generate($site, ReportFrequency::WEEKLY);

        $mailable = new SiteReportMail($report);

        $mailable->assertSeeInHtml('Où se situe votre site');
        $mailable->assertSeeInHtml('Temps de réponse serveur');
        $mailable->assertSeeInHtml('Très bien');
    }

    public function test_mail_omits_the_gauges_section_entirely_when_no_gauge_has_data(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create(['alias' => 'my-site']);

        $report = app(SiteReportService::class)->generate($site, ReportFrequency::WEEKLY);

        $mailable = new SiteReportMail($report);
        $html = $mailable->render();

        $this->assertStringNotContainsString('Où se situe votre site', $html);
    }

    public function test_pdf_renders_the_gauges_section_when_a_gauge_has_data(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create();
        $monitor = Monitor::factory()->for($team)->create(['site_id' => $site->id]);
        MonitorCheck::factory()->create([
            'monitor_id' => $monitor->id,
            'checked_at' => '2026-09-15 12:00:00',
            'response_time_ms' => 300,
        ]);

        $report = app(SiteReportService::class)->generate($site, ReportFrequency::WEEKLY);

        $html = View::make('reports.site-report-pdf', ['report' => $report])->render();

        $this->assertStringContainsString('Où se situe votre site', $html);
        $this->assertStringContainsString('Temps de réponse serveur', $html);
        $this->assertStringContainsString('échelle construite à partir des seuils Google', $html);
    }

    public function test_mail_does_not_render_accessibility_or_best_practices_gauges_but_pdf_does(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create();
        $monitor = Monitor::factory()->for($team)->create(['site_id' => $site->id]);
        MonitorLighthouseScore::factory()->create([
            'monitor_id' => $monitor->id,
            'accessibility' => 40,
            'best_practices' => 40,
            'scored_at' => '2026-09-18 00:00:00',
        ]);

        $report = app(SiteReportService::class)->generate($site, ReportFrequency::WEEKLY);

        $mailHtml = (new SiteReportMail($report))->render();
        $pdfHtml = View::make('reports.site-report-pdf', ['report' => $report])->render();

        // The pre-existing Lighthouse score breakdown (unrelated to gauges)
        // legitimately mentions "Accessibilité"/"Bonnes pratiques" in both
        // templates, so assert on the GAUGE-specific source caption instead.
        $accessibilityGaugeSource = 'échelle construite à partir des seuils Google (Lighthouse — Accessibilité)';
        $bestPracticesGaugeSource = 'échelle construite à partir des seuils Google (Lighthouse — Bonnes pratiques)';

        $this->assertStringNotContainsString($accessibilityGaugeSource, $mailHtml);
        $this->assertStringNotContainsString($bestPracticesGaugeSource, $mailHtml);
        $this->assertStringContainsString($accessibilityGaugeSource, $pdfHtml);
        $this->assertStringContainsString($bestPracticesGaugeSource, $pdfHtml);
    }

    public function test_mail_and_pdf_render_insights_in_french_never_the_english_title(): void
    {
        $team = Team::factory()->create();
        $site = Site::factory()->for($team)->create(['primary_domain' => 'example.com']);

        Insight::factory()->for($team)->create([
            'site_id' => $site->id,
            'title' => '28 of 113 published pages on example.com have no search visibility',
            'type' => InsightType::ZOMBIE_PAGE->value,
            'payload' => ['zombie_count' => 28, 'published_count' => 113],
            'severity' => InsightSeverity::WARNING->value,
            'impact_score' => 50,
        ]);

        $report = app(SiteReportService::class)->generate($site, ReportFrequency::WEEKLY);

        $mailHtml = (new SiteReportMail($report))->render();
        $pdfHtml = View::make('reports.site-report-pdf', ['report' => $report])->render();

        // Blade's {{ }} HTML-escapes the apostrophe to &#039; — compare
        // against the escaped form, same as the rendered output.
        $frenchSentence = '28 pages publiées sur 113 n&#039;apparaissent pas dans les résultats Google.';
        $englishTitle = '28 of 113 published pages on example.com have no search visibility';

        $this->assertStringContainsString($frenchSentence, $mailHtml);
        $this->assertStringContainsString($frenchSentence, $pdfHtml);
        $this->assertStringNotContainsString($englishTitle, $mailHtml);
        $this->assertStringNotContainsString($englishTitle, $pdfHtml);
    }
}
