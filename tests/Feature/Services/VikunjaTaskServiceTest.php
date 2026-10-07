<?php

namespace Tests\Feature\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Site;
use App\Models\Team;
use App\Models\VikunjaTaskLink;
use App\Services\Vikunja\VikunjaTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VikunjaTaskServiceTest extends TestCase
{
    use RefreshDatabase;

    private VikunjaTaskService $service;

    private Team $team;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('vikunja.enabled', true);
        config()->set('vikunja.token', 'tk_test');
        config()->set('vikunja.base_url', 'https://vikunja.test/api/v1');
        config()->set('vikunja.fallback_project_id', 1);
        config()->set('vikunja.labels.claude', 12);
        config()->set('vikunja.buckets.todo', 'À faire');
        config()->set('vikunja.buckets.in_progress', 'En cours');
        config()->set('vikunja.promotion.severities', ['critical']);
        config()->set('vikunja.promotion.persistence_hours', 6);
        config()->set('vikunja.promotion.transient_types', ['uptime_incident']);
        config()->set('vikunja.promotion.transient_persistence_hours', 24);
        config()->set('vikunja.promotion.immediate_types', ['ssl_expiry', 'outdated_cms']);
        config()->set('vikunja.promotion.never_types', []);
        config()->set('vikunja.promotion.max_per_run', 5);
        config()->set('vikunja.reconcile.resolved_after_hours', 2);
        config()->set('vikunja.reconcile.reopen_cooldown_hours', 24);

        // Board layout is cached per project — a stale entry would leak between tests.
        Cache::flush();

        $this->service = app(VikunjaTaskService::class);
        $this->team = Team::factory()->create();
        $this->site = Site::factory()->create([
            'team_id' => $this->team->id,
            'vikunja_project_id' => 13,
        ]);
    }

    /**
     * Fake the whole Vikunja surface. `$task` overrides what GET /tasks/{id} returns.
     *
     * @param  array<string,mixed>  $task
     */
    /**
     * @param  array<string,mixed>  $task
     * @param  array<int,array<string,mixed>>  $labels  Board-mode: the instance's labels (GET /labels).
     * @param  array<int,string>  $projectTitles  Board-mode: project id => title (GET /projects/{id}).
     */
    private function fakeVikunja(
        array $task = [],
        ?string $columnOfTask = null,
        bool $taskMissing = false,
        array $labels = [],
        array $projectTitles = [],
    ): void {
        $task = array_merge(['id' => 999, 'title' => 'card', 'done' => false], $task);

        Http::fake([
            // Board layout
            'vikunja.test/api/v1/projects/*/views' => Http::response([
                ['id' => 56, 'view_kind' => 'kanban', 'title' => 'Kanban'],
            ]),
            'vikunja.test/api/v1/projects/*/views/*/buckets/*/tasks' => Http::response(['task_id' => $task['id']]),
            'vikunja.test/api/v1/projects/*/views/*/buckets' => Http::response([
                ['id' => 53, 'title' => 'À faire'],
                ['id' => 124, 'title' => 'En cours'],
            ]),
            // Which column a task sits in (only this endpoint tells the truth)
            'vikunja.test/api/v1/projects/*/views/*/tasks' => Http::response([
                ['id' => 53, 'title' => 'À faire', 'tasks' => $columnOfTask === 'À faire' ? [$task] : []],
                ['id' => 124, 'title' => 'En cours', 'tasks' => $columnOfTask === 'En cours' ? [$task] : []],
            ]),
            // Board mode: the instance's labels.
            'vikunja.test/api/v1/labels*' => Http::response($labels),
            // Tasks
            'vikunja.test/api/v1/projects/*/tasks' => Http::response($task),
            'vikunja.test/api/v1/tasks/*/labels' => Http::response(['label_id' => 12]),
            'vikunja.test/api/v1/tasks/*/comments' => Http::response(['id' => 1]),
            // Closure rather than a second Http::fake() later: repeated fake()
            // calls MERGE their stubs and the earlier, broader pattern keeps
            // winning — a 404 declared afterwards would never be reached.
            'vikunja.test/api/v1/tasks/*' => function ($request) use ($task, $taskMissing) {
                if ($taskMissing && $request->method() === 'GET') {
                    return Http::response(['message' => 'not found'], 404);
                }

                return Http::response($task);
            },
            // Board mode: a single project by id (GET /projects/{id}), used to
            // resolve an archived site project's title. Declared LAST, right
            // before the catch-all — it is the most generic '/projects/*'
            // pattern and must not swallow the more specific project routes
            // above (…/views, …/tasks, …/buckets…).
            'vikunja.test/api/v1/projects/*' => function ($request) use ($projectTitles) {
                $id = (int) basename(parse_url($request->url(), PHP_URL_PATH));

                return isset($projectTitles[$id])
                    ? Http::response(['id' => $id, 'title' => $projectTitles[$id]])
                    : Http::response(['message' => 'not found'], 404);
            },
            '*' => Http::response([], 200),
        ]);
    }

    private function insight(array $attributes = []): Insight
    {
        return Insight::factory()->create(array_merge([
            'team_id' => $this->team->id,
            'site_id' => $this->site->id,
            'site' => $this->site->primary_domain,
            'monitor_id' => null,
            'type' => InsightType::CONTENT_DECAY->value,
            'severity' => InsightSeverity::CRITICAL->value,
            'acknowledged_at' => null,
            'snoozed_until' => null,
        ], $attributes));
    }

    // -------------------------------------------------------------------------
    // observe — the persistence memory
    // -------------------------------------------------------------------------

    public function test_observe_opens_a_link_and_stamps_first_seen(): void
    {
        $this->fakeVikunja();
        $insight = $this->insight();

        $this->service->observe($this->team);

        $link = VikunjaTaskLink::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('site:'.$this->site->id.'|content_decay', $link->scope_key);
        $this->assertSame($insight->id, $link->insight_id);
        $this->assertNull($link->vikunja_task_id, 'a freshly seen problem must not get a card yet');
    }

    /**
     * THE regression this whole table exists to prevent.
     *
     * Detectors delete their unacknowledged insights and re-insert them on every
     * run, so the SAME problem shows up tomorrow under a NEW insight id. If the
     * link keyed on that id — or reset its age — the problem would look brand new
     * forever and either never get promoted, or get a second card every day.
     */
    public function test_a_recreated_insight_reuses_the_same_link_without_resetting_its_age(): void
    {
        $this->fakeVikunja();

        $first = $this->insight();
        $this->travelTo(now()->subHours(10));
        $this->service->observe($this->team);
        $this->travelBack();

        $originalFirstSeen = VikunjaTaskLink::withoutGlobalScopes()->firstOrFail()->first_seen_at;

        // The detector wipes and rebuilds: new row, new id, same problem.
        $first->delete();
        $recreated = $this->insight();

        $this->service->observe($this->team);

        $links = VikunjaTaskLink::withoutGlobalScopes()->get();
        $this->assertCount(1, $links, 'a recreated insight must not open a second link');
        $this->assertSame($recreated->id, $links[0]->insight_id);
        $this->assertSame(
            $originalFirstSeen->timestamp,
            $links[0]->first_seen_at->timestamp,
            'first_seen_at is the age of the problem, not of the insight row',
        );
    }

    public function test_acknowledged_insights_are_ignored(): void
    {
        $this->fakeVikunja();
        $this->insight(['acknowledged_at' => now()]);

        $this->service->observe($this->team);

        $this->assertSame(0, VikunjaTaskLink::withoutGlobalScopes()->count());
    }

    public function test_snoozed_insights_are_ignored(): void
    {
        $this->fakeVikunja();
        $this->insight(['snoozed_until' => now()->addDay()]);

        $this->service->observe($this->team);

        $this->assertSame(0, VikunjaTaskLink::withoutGlobalScopes()->count());
    }

    // -------------------------------------------------------------------------
    // promote — persistence gate
    // -------------------------------------------------------------------------

    public function test_a_fresh_problem_is_not_promoted(): void
    {
        $this->fakeVikunja();
        $this->insight();

        $this->service->observe($this->team);
        $promoted = $this->service->promote($this->team);

        $this->assertSame(0, $promoted, 'a transient problem must never reach the board');
    }

    public function test_a_problem_that_persists_past_the_threshold_is_promoted(): void
    {
        $this->fakeVikunja(['id' => 4242]);
        $this->insight();

        $this->travelTo(now()->subHours(7));
        $this->service->observe($this->team);
        $this->travelBack();

        $this->service->observe($this->team);
        $promoted = $this->service->promote($this->team);

        $this->assertSame(1, $promoted);

        $link = VikunjaTaskLink::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(4242, $link->vikunja_task_id);
        $this->assertSame(13, $link->vikunja_project_id, 'card must land in the site\'s project');
        $this->assertNotNull($link->promoted_at);
    }

    public function test_warnings_are_never_promoted_automatically(): void
    {
        $this->fakeVikunja();
        $this->insight(['severity' => InsightSeverity::WARNING->value]);

        $this->travelTo(now()->subDays(5));
        $this->service->observe($this->team);
        $this->travelBack();

        $this->assertSame(0, $this->service->promote($this->team));
    }

    public function test_deadline_types_are_promoted_immediately(): void
    {
        // An expiring certificate never resolves itself; waiting only shortens
        // the runway to act.
        $this->fakeVikunja();
        $this->insight(['type' => InsightType::SSL_EXPIRY->value, 'monitor_id' => null]);

        $this->service->observe($this->team);

        $this->assertSame(1, $this->service->promote($this->team));
    }

    /**
     * "WARNING is never promoted automatically" has to hold for EVERY type, or it
     * is not a rule. Deadline types skip the persistence delay, not the severity
     * gate — otherwise a WARNING-level outdated CMS would land on the board the
     * moment the integration is switched on, which is precisely what the rule
     * exists to prevent.
     *
     * The types police themselves anyway: SslExpiryDetector raises CRITICAL only
     * at 3 days remaining and WARNING at 14, so urgency still gets through at once.
     */
    public function test_a_deadline_type_still_has_to_clear_the_severity_gate(): void
    {
        $this->fakeVikunja();
        $this->insight([
            'type' => InsightType::OUTDATED_CMS->value,
            'severity' => InsightSeverity::WARNING->value,
        ]);

        $this->travelTo(now()->subDays(5));
        $this->service->observe($this->team);
        $this->travelBack();
        $this->service->observe($this->team);

        $this->assertSame(0, $this->service->promote($this->team));
    }

    public function test_transient_types_wait_longer(): void
    {
        $this->fakeVikunja();
        $this->insight(['type' => InsightType::UPTIME_INCIDENT->value]);

        // 7 h: past the standard threshold, well short of the transient one.
        $this->travelTo(now()->subHours(7));
        $this->service->observe($this->team);
        $this->travelBack();

        $this->assertSame(0, $this->service->promote($this->team));
    }

    public function test_promotion_is_capped_per_run(): void
    {
        // A wildcard cert or a dead host lights up every site at once; the board
        // must not receive thirty cards in one sweep.
        config()->set('vikunja.promotion.max_per_run', 2);
        $this->fakeVikunja();

        for ($i = 0; $i < 4; $i++) {
            $site = Site::factory()->create(['team_id' => $this->team->id, 'vikunja_project_id' => 13]);
            $this->insight(['site_id' => $site->id, 'site' => $site->primary_domain]);
        }

        $this->travelTo(now()->subHours(7));
        $this->service->observe($this->team);
        $this->travelBack();

        $this->assertSame(2, $this->service->promote($this->team));
        $this->assertSame(2, VikunjaTaskLink::withoutGlobalScopes()->promoted()->count());
    }

    // -------------------------------------------------------------------------
    // reconcile — both sides must agree
    // -------------------------------------------------------------------------

    public function test_a_card_marked_done_acknowledges_the_insight(): void
    {
        $this->fakeVikunja(['id' => 555, 'done' => true]);
        $insight = $this->insight();

        $this->travelTo(now()->subHours(7));
        $this->service->observe($this->team);
        $this->travelBack();
        $this->service->promote($this->team);

        $outcome = $this->service->reconcile($this->team);

        $this->assertSame(1, $outcome['acknowledged'], "Up's badge must not keep showing handled work");
        $this->assertNotNull($insight->fresh()->acknowledged_at);

        $link = VikunjaTaskLink::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('done_in_vikunja', $link->close_reason);
    }

    public function test_a_resolved_problem_closes_its_card(): void
    {
        $this->fakeVikunja(['id' => 556], columnOfTask: 'À faire');
        $insight = $this->insight();

        $this->travelTo(now()->subHours(7));
        $this->service->observe($this->team);
        $this->travelBack();
        $this->service->promote($this->team);

        // Problem gone: the detector no longer recreates the insight, so nothing
        // refreshes last_seen_at.
        $insight->delete();
        $this->travel(3)->hours();

        $outcome = $this->service->reconcile($this->team);

        $this->assertSame(1, $outcome['closed']);
        $this->assertSame('resolved', VikunjaTaskLink::withoutGlobalScopes()->firstOrFail()->close_reason);
    }

    public function test_a_card_in_progress_is_never_force_closed(): void
    {
        // "En cours" is the board's lock: the card belongs to whoever moved it
        // there, and deciding the work is finished is their call, not a sweep's.
        $this->fakeVikunja(['id' => 557], columnOfTask: 'En cours');
        $insight = $this->insight();

        $this->travelTo(now()->subHours(7));
        $this->service->observe($this->team);
        $this->travelBack();
        $this->service->promote($this->team);

        $insight->delete();
        $this->travel(3)->hours();

        $outcome = $this->service->reconcile($this->team);

        $this->assertSame(0, $outcome['closed'], 'a locked card must not be marked done');

        // It was commented on, not completed.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/comments'));
        Http::assertNotSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/tasks/557')
            && ($request->data()['done'] ?? false) === true);
    }

    public function test_a_card_deleted_by_hand_stops_being_polled(): void
    {
        // The card is gone from the board while the problem is still live: the
        // link must close rather than keep polling a ghost forever.
        $this->fakeVikunja(['id' => 558], taskMissing: true);
        $this->insight();

        $this->travelTo(now()->subHours(7));
        $this->service->observe($this->team);
        $this->travelBack();

        // Refresh last_seen_at so "problem resolved" cannot be the reason it closes.
        $this->service->observe($this->team);
        $this->service->promote($this->team);

        $outcome = $this->service->reconcile($this->team);

        $this->assertSame(1, $outcome['closed']);
        $this->assertSame('abandoned', VikunjaTaskLink::withoutGlobalScopes()->firstOrFail()->close_reason);
    }

    // -------------------------------------------------------------------------
    // recurrence
    // -------------------------------------------------------------------------

    public function test_a_recurring_problem_does_not_get_a_new_card_during_the_cooldown(): void
    {
        $this->fakeVikunja(['id' => 559]);
        $this->insight();

        $this->travelTo(now()->subHours(7));
        $this->service->observe($this->team);
        $this->travelBack();
        $this->service->promote($this->team);

        VikunjaTaskLink::withoutGlobalScopes()->firstOrFail()
            ->update(['closed_at' => now(), 'close_reason' => 'resolved']);

        // Same problem comes back an hour later.
        $this->travel(1)->hours();
        $this->service->observe($this->team);

        $link = VikunjaTaskLink::withoutGlobalScopes()->firstOrFail();
        $this->assertNotNull($link->closed_at, 'the link must stay closed during the cooldown');
        $this->assertSame(0, $this->service->promote($this->team));
    }

    public function test_a_problem_returning_after_the_cooldown_opens_a_new_window(): void
    {
        $this->fakeVikunja(['id' => 560]);
        $this->insight();

        $this->travelTo(now()->subHours(7));
        $this->service->observe($this->team);
        $this->travelBack();
        $this->service->promote($this->team);

        VikunjaTaskLink::withoutGlobalScopes()->firstOrFail()
            ->update(['closed_at' => now(), 'close_reason' => 'resolved']);

        $this->travel(25)->hours();
        $this->service->observe($this->team);

        $link = VikunjaTaskLink::withoutGlobalScopes()->firstOrFail();
        $this->assertNull($link->closed_at, 'a recurrence past the cooldown reopens the link');
        $this->assertNull($link->vikunja_task_id, 'and it must earn a fresh card, not reuse the old one');
    }

    // -------------------------------------------------------------------------
    // routing
    // -------------------------------------------------------------------------

    public function test_an_unmapped_site_falls_back_to_the_inbox(): void
    {
        $this->fakeVikunja(['id' => 561]);
        $unmapped = Site::factory()->create([
            'team_id' => $this->team->id,
            'vikunja_project_id' => null,
        ]);
        $this->insight(['site_id' => $unmapped->id, 'site' => $unmapped->primary_domain]);

        $this->travelTo(now()->subHours(7));
        $this->service->observe($this->team);
        $this->travelBack();
        $this->service->promote($this->team);

        $this->assertSame(
            1,
            VikunjaTaskLink::withoutGlobalScopes()->firstOrFail()->vikunja_project_id,
            'an unmapped site must stay visible in the Inbox rather than vanish',
        );
    }

    // -------------------------------------------------------------------------
    // board mode
    // -------------------------------------------------------------------------

    public function test_board_mode_creates_the_card_in_the_board_project_with_all_three_labels(): void
    {
        config()->set('vikunja.board_project_id', 900);
        config()->set('vikunja.labels.sphere', 45);
        config()->set('vikunja.project_label_prefix', 'projet: ');

        $this->fakeVikunja(
            ['id' => 700],
            labels: [['id' => 77, 'title' => 'projet: '.$this->site->primary_domain]],
        );
        $this->insight();

        $this->travelTo(now()->subHours(7));
        $this->service->observe($this->team);
        $this->travelBack();

        $this->assertSame(1, $this->service->promote($this->team));

        $link = VikunjaTaskLink::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(900, $link->vikunja_project_id, 'card must land in the shared board, not the site project');

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/projects/900/tasks') && $request->method() === 'PUT');
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/tasks/700/labels') && ($request->data()['label_id'] ?? null) === 12);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/tasks/700/labels') && ($request->data()['label_id'] ?? null) === 45);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/tasks/700/labels') && ($request->data()['label_id'] ?? null) === 77);
    }

    public function test_board_mode_still_creates_the_card_when_no_project_label_resolves(): void
    {
        // Vikunja is a convenience, never a dependency: a card missing its
        // "projet: …" label beats a card that never gets created.
        config()->set('vikunja.board_project_id', 900);

        $this->fakeVikunja(['id' => 701], labels: []);
        $this->insight();

        $this->travelTo(now()->subHours(7));
        $this->service->observe($this->team);
        $this->travelBack();

        $this->assertSame(1, $this->service->promote($this->team));
        $this->assertSame(900, VikunjaTaskLink::withoutGlobalScopes()->firstOrFail()->vikunja_project_id);
    }

    /**
     * THE bug board mode must never reintroduce.
     *
     * A link promoted BEFORE the migration stores the id of its old, now
     * archived project. In board mode, reading the card's column MUST use the
     * board id instead of that stale stored id — using the stale id would make
     * bucketTitleOfTask() find nothing, read that as "not locked", and let the
     * sweep force-close a card someone is actively working on in "En cours".
     */
    public function test_board_mode_reconciles_a_link_created_before_the_migration_using_the_board_project(): void
    {
        $this->fakeVikunja(['id' => 702], columnOfTask: 'En cours');
        $insight = $this->insight();

        // Promoted under the OLD per-site-project regime: the link stores the
        // site's own (now archived) project id, 13.
        $this->travelTo(now()->subHours(7));
        $this->service->observe($this->team);
        $this->travelBack();
        $this->service->promote($this->team);

        $link = VikunjaTaskLink::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(13, $link->vikunja_project_id, 'sanity: the link still carries its pre-migration project');

        // The migration happens: board mode switches on. The card physically
        // moved to project 900; only the stale link record still says 13.
        config()->set('vikunja.board_project_id', 900);

        $insight->delete();
        $this->travel(3)->hours();

        // Re-fake to reset the request recorder: the assertions below must
        // only see what reconcile() itself sends, not the promotion above.
        $this->fakeVikunja(['id' => 702], columnOfTask: 'En cours');

        $outcome = $this->service->reconcile($this->team);

        $this->assertSame(0, $outcome['closed'], 'a locked card must not be force-closed');

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/projects/900/views'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/projects/13/views'));
    }
}
