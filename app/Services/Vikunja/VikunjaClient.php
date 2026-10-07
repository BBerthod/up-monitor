<?php

namespace App\Services\Vikunja;

use App\Exceptions\VikunjaException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Thin HTTP client for the Vikunja API (v1).
 *
 * Deliberately dumb: it knows the API's quirks and nothing about insights. All
 * promotion policy lives in VikunjaTaskService.
 *
 * API QUIRKS THIS CLASS ABSORBS (verified against v2.5.0)
 * ──────────────────────────────────────────────────────
 *  • Creation uses PUT, not POST — PUT /projects/{id}/tasks.
 *
 *  • POST /tasks/{id} REPLACES the task; fields absent from the payload are
 *    reset. Renaming a task by sending only {"title": …} wipes its due date,
 *    priority and position. updateTask() therefore always re-reads the task and
 *    merges, never sends a bare patch.
 *
 *  • Bucket ids are per PROJECT and belong to the Kanban *view*, not the project
 *    ("À faire" is bucket 53 on one project and bucket 2 on another). Columns can
 *    only be addressed by title and resolved at runtime — cached, since the board
 *    layout changes about once a year.
 *
 *  • A task's real column is invisible from GET /tasks/{id} (bucket_id comes back
 *    as 0) and from GET …/buckets (which omits tasks). The only endpoint that
 *    tells the truth is GET /projects/{p}/views/{v}/tasks, which returns buckets
 *    with their tasks nested — hence bucketTitleOfTask().
 *
 *  • done ↔ column is handled by Vikunja itself through the view's done_bucket_id,
 *    so closing a card is just done=true. No bucket juggling required.
 */
class VikunjaClient
{
    /** Board layout is stable; re-resolving it on every call would be pure waste. */
    private const VIEW_CACHE_TTL = 3600;

    /** Server-side page cap: asking for more per page returns 50 anyway. */
    private const PAGE_SIZE = 50;

    public function isConfigured(): bool
    {
        return (bool) config('vikunja.enabled')
            && is_string(config('vikunja.token'))
            && config('vikunja.token') !== '';
    }

    // -------------------------------------------------------------------------
    // Tasks
    // -------------------------------------------------------------------------

    /**
     * Create a task in a project.
     *
     * @param  array<string,mixed>  $payload  title, description, priority, due_date…
     * @return array<string,mixed> The created task.
     */
    public function createTask(int $projectId, array $payload): array
    {
        return $this->request('put', "/projects/{$projectId}/tasks", $payload);
    }

    /** @return array<string,mixed>|null Null when the task no longer exists. */
    public function getTask(int $taskId): ?array
    {
        try {
            return $this->request('get', "/tasks/{$taskId}");
        } catch (VikunjaException $e) {
            // A card deleted by hand on the board is an expected outcome, not an
            // error: the caller closes the link instead of retrying forever.
            if ($e->getCode() === 404) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Fields Vikunja returns when reading a task but rejects when writing one.
     *
     * POST /tasks/{id} replaces the model, so the read-modify-write below echoes
     * the task back — and any read-only relation in that echo makes the whole
     * call fail with 400 "Invalid model provided". `related_tasks` is such a
     * relation: it is a map of relation kind => tasks, maintained through its own
     * endpoint, and sending it back is a validation error even when it is empty.
     *
     * Failing here is silent and expensive: reconciliation could no longer close
     * a single card, and because the sweep comments before it closes, every
     * resolved card collected one "Résolu" comment every 15 minutes — 50 of them
     * on one card before it was noticed.
     *
     * @var array<int, string>
     */
    private const READ_ONLY_FIELDS = ['related_tasks'];

    /**
     * Update a task without destroying the fields we did not send.
     *
     * @param  array<string,mixed>  $changes
     * @return array<string,mixed>
     */
    public function updateTask(int $taskId, array $changes): array
    {
        $current = $this->getTask($taskId);

        if ($current === null) {
            throw new VikunjaException("Vikunja task {$taskId} no longer exists", 404);
        }

        $payload = array_diff_key(
            array_merge($current, $changes),
            array_flip(self::READ_ONLY_FIELDS),
        );

        return $this->request('post', "/tasks/{$taskId}", $payload);
    }

    /** Mark a task done. Vikunja moves it to the view's done bucket by itself. */
    public function markDone(int $taskId): void
    {
        $this->updateTask($taskId, ['done' => true]);
    }

    public function addLabel(int $taskId, int $labelId): void
    {
        $this->request('put', "/tasks/{$taskId}/labels", ['label_id' => $labelId]);
    }

    public function comment(int $taskId, string $text): void
    {
        $this->request('put', "/tasks/{$taskId}/comments", ['comment' => $text]);
    }

    // -------------------------------------------------------------------------
    // Board layout
    // -------------------------------------------------------------------------

    /** Move a task into a column, addressed by title. No-op if the column is unknown. */
    public function moveToBucket(int $projectId, int $taskId, string $bucketTitle): bool
    {
        $viewId = $this->kanbanViewId($projectId);

        if ($viewId === null) {
            return false;
        }

        $bucketId = $this->bucketId($projectId, $bucketTitle);

        if ($bucketId === null) {
            return false;
        }

        $this->request('post', "/projects/{$projectId}/views/{$viewId}/buckets/{$bucketId}/tasks", [
            'task_id' => $taskId,
        ]);

        return true;
    }

    /**
     * The column a task currently sits in, or null if it cannot be determined.
     *
     * Used to honour the board's locking convention: a card in "En cours" belongs
     * to whoever moved it there and must not be force-closed by an automated sweep.
     */
    public function bucketTitleOfTask(int $projectId, int $taskId): ?string
    {
        $viewId = $this->kanbanViewId($projectId);

        if ($viewId === null) {
            return null;
        }

        $buckets = $this->request('get', "/projects/{$projectId}/views/{$viewId}/tasks");

        foreach ($buckets as $bucket) {
            // The `tasks` key is omitted entirely when a bucket is empty.
            foreach ($bucket['tasks'] ?? [] as $task) {
                if ((int) ($task['id'] ?? 0) === $taskId) {
                    return $bucket['title'] ?? null;
                }
            }
        }

        return null;
    }

    /** Id of the project's Kanban view, cached. */
    public function kanbanViewId(int $projectId): ?int
    {
        return Cache::remember(
            "vikunja:view:kanban:{$projectId}",
            self::VIEW_CACHE_TTL,
            function () use ($projectId): ?int {
                foreach ($this->request('get', "/projects/{$projectId}/views") as $view) {
                    if (($view['view_kind'] ?? null) === 'kanban') {
                        return (int) $view['id'];
                    }
                }

                return null;
            },
        );
    }

    /** Id of a column by title within a project, cached. */
    public function bucketId(int $projectId, string $title): ?int
    {
        $viewId = $this->kanbanViewId($projectId);

        if ($viewId === null) {
            return null;
        }

        return Cache::remember(
            "vikunja:bucket:{$projectId}:{$viewId}:".md5($title),
            self::VIEW_CACHE_TTL,
            function () use ($projectId, $viewId, $title): ?int {
                foreach ($this->request('get', "/projects/{$projectId}/views/{$viewId}/buckets") as $bucket) {
                    if (($bucket['title'] ?? null) === $title) {
                        return (int) $bucket['id'];
                    }
                }

                return null;
            },
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function projects(): array
    {
        return $this->paginated('/projects');
    }

    /**
     * A single project, by id — works for archived projects too (GET still
     * resolves them; only task creation is rejected there).
     *
     * @return array<string,mixed>|null Null when the project no longer exists.
     */
    public function project(int $id): ?array
    {
        try {
            return $this->request('get', "/projects/{$id}");
        } catch (VikunjaException $e) {
            if ($e->getCode() === 404) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Every label in the instance. Label ids are global (unlike buckets), but
     * their titles are only readable through this endpoint.
     *
     * @return array<int,array<string,mixed>>
     */
    public function labels(): array
    {
        return $this->paginated('/labels');
    }

    /**
     * Every page of a list endpoint. The server caps a page at 50 items
     * whatever `per_page` asks for — a single request silently drops the rest,
     * which is how "projet: …" labels past the 50th went unseen (14/09/2026).
     * A short page is the last one.
     *
     * @return array<int,array<string,mixed>>
     */
    private function paginated(string $path, int $maxPages = 100): array
    {
        $items = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            // Query passed as an array: request() hands its body to get() as the
            // query, which REPLACES any query string written into the path.
            $chunk = $this->request('get', $path, ['per_page' => self::PAGE_SIZE, 'page' => $page]);
            array_push($items, ...$chunk);

            if (count($chunk) < self::PAGE_SIZE) {
                break;
            }
        }

        return $items;
    }

    // -------------------------------------------------------------------------
    // Transport
    // -------------------------------------------------------------------------

    /**
     * @param  array<string,mixed>|null  $body
     * @return array<mixed>
     *
     * @throws VikunjaException
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        if (! $this->isConfigured()) {
            throw new VikunjaException('Vikunja integration is disabled or missing a token');
        }

        $url = rtrim((string) config('vikunja.base_url'), '/').$path;

        try {
            $response = $this->http()->{$method}($url, $body ?? []);
        } catch (Throwable $e) {
            throw new VikunjaException("Vikunja request failed: {$e->getMessage()}", 0, $e);
        }

        if ($response->failed()) {
            throw new VikunjaException(
                "Vikunja {$method} {$path} returned {$response->status()}: ".mb_substr($response->body(), 0, 200),
                $response->status(),
            );
        }

        $decoded = $response->json();

        return is_array($decoded) ? $decoded : [];
    }

    private function http(): PendingRequest
    {
        return Http::withToken((string) config('vikunja.token'))
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('vikunja.timeout', 10))
            // One retry only: this runs inside a queued sweep that will come back
            // in fifteen minutes anyway, so a stubborn failure costs a delay, not data.
            ->retry(2, 200, throw: false);
    }
}
