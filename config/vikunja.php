<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Connection
    |--------------------------------------------------------------------------
    |
    | Base URL must include the API prefix (…/api/v1) — the Vikunja instance
    | serves the frontend on the bare domain and the API one level down.
    |
    | The integration is OFF by default: without an explicit VIKUNJA_ENABLED=true
    | nothing is created, so a fresh install or a test run never talks to a real
    | Kanban board.
    |
    */

    'enabled' => env('VIKUNJA_ENABLED', false),

    'base_url' => env('VIKUNJA_BASE_URL', 'https://vikunja.example.com/api/v1'),

    'token' => env('VIKUNJA_API_TOKEN'),

    'timeout' => (int) env('VIKUNJA_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Board conventions
    |--------------------------------------------------------------------------
    |
    | Bucket ids are NOT global in Vikunja — every project has its own set, and
    | they are attached to a *view* (the Kanban one), not to the project. So we
    | can only address a column by its TITLE and resolve the id per project at
    | runtime (see VikunjaClient::resolveBucketId, cached).
    |
    | Label ids, by contrast, ARE global and stable, so they are configured
    | directly. Run `php artisan vikunja:doctor` to print the current ids.
    |
    */

    'buckets' => [
        // Where a freshly promoted card lands.
        'todo' => env('VIKUNJA_BUCKET_TODO', 'À faire'),
        // Locked column: a card sitting here belongs to a human/session and is
        // never force-closed by Up (see VikunjaTaskService::close).
        'in_progress' => env('VIKUNJA_BUCKET_IN_PROGRESS', 'En cours'),
    ],

    'labels' => [
        // Attached to every card Up creates: these tasks carry an executable
        // fix prompt, so a Claude session is the intended executor.
        'claude' => (int) env('VIKUNJA_LABEL_CLAUDE', 12),

        // Attached to every card Up creates in BOARD MODE (see below): every
        // card Up produces belongs to the same sphere, so this is a single
        // fixed id rather than something resolved per card. 0 = not set, no
        // sphere label is added (kept optional so board mode still works
        // before this id is known).
        'sphere' => (int) env('VIKUNJA_LABEL_SPHERE', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Project routing
    |--------------------------------------------------------------------------
    |
    | A card is filed under the Vikunja project of its Site (sites.vikunja_project_id)
    | or of its Server (servers.vikunja_project_id). Anything unmapped falls back
    | here — project 1 is Vikunja's native Inbox, whose whole purpose is
    | unqualified capture, so an unmapped site is visible instead of silently lost.
    |
    */

    'fallback_project_id' => (int) env('VIKUNJA_FALLBACK_PROJECT_ID', 1),

    /*
    |--------------------------------------------------------------------------
    | Board mode — single shared project
    |--------------------------------------------------------------------------
    |
    | The Kanban model changed: instead of one Vikunja project per site/server,
    | every card now lives in ONE project (the "board"), and what used to be the
    | project becomes a LABEL instead ("projet: <old project title>"). The old
    | per-site/per-server projects are archived, not deleted — GET /projects/{id}
    | still resolves, but POST …/tasks no longer accepts new cards there.
    |
    | board_project_id = 0 (the default) keeps the OLD behaviour byte-for-byte:
    | every method below that branches on "board mode" checks this value first,
    | so a fresh install or an environment that has not migrated its Vikunja
    | instance yet is entirely unaffected.
    |
    | Once set, a link's stored vikunja_project_id can still point at an old,
    | now-archived project (any link created before the migration). Every read
    | of a link's project — closing a card, checking its column — MUST use the
    | board id instead, never the stored one, or Up will look for a card in a
    | project it no longer lives in and get back "not found" (bucketTitleOfTask
    | returns null), which reads as "not locked" and lets a sweep force-close a
    | card someone is actively working on.
    |
    */

    'board_project_id' => (int) env('VIKUNJA_BOARD_PROJECT_ID', 0),

    // Prefix used to build the "projet: <title>" label attached to a card in
    // board mode, replacing what used to be the routing project.
    'project_label_prefix' => env('VIKUNJA_PROJECT_LABEL_PREFIX', 'projet: '),

    // Title used to resolve a server's project label — servers never had their
    // own project per machine, they all shared one "Infrastructure" project.
    'server_project_title' => env('VIKUNJA_SERVER_PROJECT_TITLE', 'Infrastructure'),

    /*
    |--------------------------------------------------------------------------
    | Promotion policy — which insights become Kanban cards
    |--------------------------------------------------------------------------
    |
    | The guiding rule: Up stays the inbox, Vikunja receives WORK. An insight
    | that resolves itself within a few hours must never become a card, or the
    | board degrades into an alert dump — the exact noise problem the insight
    | pipeline already had to be cured of.
    |
    | Hence promotion by PERSISTENCE rather than at detection: a problem earns a
    | card by still being there after `persistence_hours`. Persistence is measured
    | from vikunja_task_links.first_seen_at, NOT from insights.detected_at, because
    | detectors delete and recreate their insights on every run — detected_at is
    | reset nightly and would never age past a few hours.
    |
    | Note for daily detectors (DispatchInsights runs at 04:00): a 6 h threshold
    | means "still present at the next daily cycle", i.e. promotion on D+1. For
    | push-driven signals (server health every 5 min, heartbeats) it means what
    | it says.
    |
    */

    'promotion' => [

        // Only these severities are ever promoted automatically. WARNING stays
        // manual — the "→ Vikunja" button on the insight page.
        'severities' => ['critical'],

        'persistence_hours' => (int) env('VIKUNJA_PERSISTENCE_HOURS', 6),

        // Tasks by nature: a real deadline, no self-resolution, human planning
        // required. Waiting for persistence would only shorten the runway.
        'immediate_types' => [
            'ssl_expiry',
            'domain_expiry',
            'outdated_cms',
            // A site auto-disabled after consecutive warming failures is already
            // an acquired fact by the time the insight exists (the circuit breaker
            // only trips after several failed runs) — not a blip to wait out.
            'warming_disabled',
            // The one self-healing path (automatic rollback) already failed.
            // Waiting out persistence_hours would only delay the human
            // intervention this insight exists to trigger.
            'deploy_rollback_failed',
            // Does not self-resolve either — the daily vikunja:doctor sweep keeps
            // re-raising it, but nothing about Up itself fixes a missing project
            // mapping. Waiting persistence_hours would only delay the fix while
            // every other alert for that site keeps landing in the fallback Inbox.
            'vikunja_unmapped_site',
        ],

        // Transient by nature: the system resolves them on its own most of the
        // time. They only earn a card once they have clearly outlived a blip.
        'transient_types' => [
            'uptime_incident',
            'server_health',
            'heartbeat_missed',
        ],

        'transient_persistence_hours' => (int) env('VIKUNJA_TRANSIENT_PERSISTENCE_HOURS', 24),

        // Never promoted automatically, whatever their severity or age.
        'never_types' => [],

        // Flood guard: an incident touching every site at once (a dead server,
        // an expired wildcard certificate) must not create thirty cards in one
        // run. The overflow is not lost — it is promoted on the next run.
        'max_per_run' => (int) env('VIKUNJA_MAX_PER_RUN', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reconciliation
    |--------------------------------------------------------------------------
    |
    | Pull, not webhooks. Vikunja webhooks are registered PER PROJECT, so keeping
    | them in sync would mean creating and maintaining one per site project, plus
    | exposing a public endpoint on Up. Up already knows every task id it created,
    | so re-reading them costs a handful of GETs per run.
    |
    */

    'reconcile' => [
        // A problem not seen for this long is considered resolved, and its card
        // is closed. Generous on purpose: detectors delete their insights before
        // re-inserting them, so a sweep landing inside that gap must not mistake
        // a rewrite for a resolution. No detector leaves a two-hour hole.
        'resolved_after_hours' => (int) env('VIKUNJA_RESOLVED_AFTER_HOURS', 2),

        // Grace period before a recurring problem earns a NEW card. Without it,
        // a card closed in the morning is recreated the same afternoon and the
        // board flaps.
        'reopen_cooldown_hours' => (int) env('VIKUNJA_REOPEN_COOLDOWN_HOURS', 24),

        // Stop polling a card that has been open this long with no matching
        // insight and no movement — it has become a human's business.
        'max_open_days' => (int) env('VIKUNJA_MAX_OPEN_DAYS', 90),
    ],

];
