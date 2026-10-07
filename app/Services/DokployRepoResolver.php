<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resolves a site hostname to its Git repository via the Dokploy API.
 *
 * WHY this service exists: when a user wants to "Fix with Claude", we need to
 * tell them which repository to clone. Dokploy is the deployment platform, so
 * it's the authoritative source for hostname → repo mapping.
 *
 * API notes:
 *   - Authentication uses the `x-api-key` header, NOT `Authorization: Bearer`.
 *   - `GET /api/project.all` returns all projects with nested environments and
 *     applications. Field availability varies by Dokploy version:
 *       • Some versions include `domains[]` and `customGitUrl` directly on the
 *         application object inside project.all.
 *       • Others require a follow-up `GET /api/application.one?applicationId=X`
 *         to retrieve those fields.
 *     STRATEGY: try project.all first; if an application lacks `domains`, fetch
 *     application.one only for apps in the matched project to minimise API calls.
 *
 * This service NEVER throws — it returns null on any failure and logs debug
 * information so callers can degrade gracefully.
 */
class DokployRepoResolver
{
    /** Cached marker for "resolution failed" — Cache::remember cannot store null. */
    private const MISS_SENTINEL = '__miss__';

    public function __construct(
        private readonly string $baseUrl = '',
        private readonly string $apiToken = '',
    ) {
        // Allow constructor injection or fall back to config at call time.
        // We read config lazily in resolveRepoForHost so tests can override env.
    }

    /**
     * Resolve a hostname (or URL) to repository information from Dokploy.
     *
     * @param  string  $host  Hostname or full URL, e.g. "fr.examplestore.com" or
     *                        "https://fr.examplestore.com/api/health"
     * @return array{repo: string|null, app_name: string, app_id: string, project_name: string, branch: string|null, all_domains: string[]}|null
     *                                                                                                                                           Returns null if Dokploy is unavailable, not configured, or
     *                                                                                                                                           no matching application is found.
     */
    public function resolveRepoForHost(string $host): ?array
    {
        $host = $this->normaliseHost($host);

        if ($host === '') {
            return null;
        }

        // Cache per host to avoid hammering a potentially slow/flaky Dokploy
        // instance on every page load that displays repo context.
        //
        // Misses are cached too, behind a sentinel with a shorter TTL:
        // Cache::remember() refuses to store null, so a failed resolution
        // (Dokploy down, unknown host) used to re-run the full API walk — N
        // synchronous calls with a 15 s timeout each — inside every web
        // request that rendered a /fix page for that host.
        $cached = Cache::get("dokploy:repo:{$host}");

        if ($cached === self::MISS_SENTINEL) {
            return null;
        }

        if (is_array($cached)) {
            return $cached;
        }

        $resolved = $this->doResolve($host);

        Cache::put(
            "dokploy:repo:{$host}",
            $resolved ?? self::MISS_SENTINEL,
            $resolved !== null ? 3600 : 600,
        );

        return $resolved;
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Perform the actual Dokploy API calls. Returns null on any failure.
     */
    private function doResolve(string $host): ?array
    {
        $token = $this->apiToken ?: config('services.dokploy.api_token');
        $baseUrl = $this->baseUrl ?: config('services.dokploy.base_url', 'https://dokploy.example.com');

        // No token configured → silently degrade (common in local dev).
        if (empty($token)) {
            Log::debug('DokployRepoResolver: no api_token configured, skipping resolution', [
                'host' => $host,
            ]);

            return null;
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders(['x-api-key' => $token])
                ->get("{$baseUrl}/api/project.all");

            if (! $response->successful()) {
                Log::debug('DokployRepoResolver: project.all request failed', [
                    'host' => $host,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $projects = $response->json();

            if (! is_array($projects)) {
                return null;
            }

            return $this->findAppInProjects($projects, $host, $baseUrl, $token);
        } catch (\Throwable $e) {
            Log::debug('DokployRepoResolver: exception during resolution', [
                'host' => $host,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Walk the project/environment/application tree to find a matching app.
     *
     * Dokploy structure: Project → environments[] → applications[].
     * Each application may carry `domains[]` (objects with `.host`) and
     * `customGitUrl`, or we may need to fetch application.one for those fields.
     *
     * @param  array<mixed>  $projects
     */
    private function findAppInProjects(array $projects, string $host, string $baseUrl, string $token): ?array
    {
        foreach ($projects as $project) {
            $projectName = $project['name'] ?? 'unknown';

            foreach ($project['environments'] ?? [] as $environment) {
                foreach ($environment['applications'] ?? [] as $app) {
                    $appId = $app['applicationId'] ?? $app['id'] ?? null;
                    $appName = $app['name'] ?? 'unknown';

                    if ($appId === null) {
                        continue;
                    }

                    // Some Dokploy versions embed domains directly in project.all.
                    // If absent we'll fetch them via application.one (below).
                    $domains = $this->extractDomains($app);
                    $hasRepoData = isset($app['customGitUrl']) || isset($app['repository']);

                    // If project.all didn't include domains or repo data, fetch
                    // the full application object. We only do this for apps in
                    // the current iteration — not all apps — to keep API calls low.
                    if (empty($domains) || ! $hasRepoData) {
                        $app = $this->fetchApplicationDetail($baseUrl, $token, $appId) ?? $app;
                        $domains = $this->extractDomains($app);
                    }

                    if ($this->matchesDomain($domains, $host)) {
                        return [
                            'repo' => $this->extractRepo($app),
                            'app_name' => $appName,
                            'app_id' => (string) $appId,
                            'project_name' => $projectName,
                            'branch' => $app['branch'] ?? null,
                            'all_domains' => $domains,
                        ];
                    }
                }
            }
        }

        Log::debug('DokployRepoResolver: no application found for host', ['host' => $host]);

        return null;
    }

    /**
     * Fetch full application details when project.all omits domains/repo.
     *
     * @return array<mixed>|null
     */
    private function fetchApplicationDetail(string $baseUrl, string $token, string $appId): ?array
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['x-api-key' => $token])
                ->get("{$baseUrl}/api/application.one", ['applicationId' => $appId]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::debug('DokployRepoResolver: application.one failed', [
                'app_id' => $appId,
                'status' => $response->status(),
            ]);
        } catch (\Throwable $e) {
            Log::debug('DokployRepoResolver: application.one exception', [
                'app_id' => $appId,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Extract the list of hostnames from an application object.
     *
     * Dokploy stores domains as an array of objects: `[{ "host": "example.com" }, ...]`
     * Some versions may use a flat array of strings — we handle both.
     *
     * @param  array<mixed>  $app
     * @return string[]
     */
    private function extractDomains(array $app): array
    {
        $rawDomains = $app['domains'] ?? [];

        if (! is_array($rawDomains)) {
            return [];
        }

        $hosts = [];

        foreach ($rawDomains as $domain) {
            if (is_string($domain)) {
                $hosts[] = $this->normaliseHost($domain);
            } elseif (is_array($domain) && isset($domain['host'])) {
                $hosts[] = $this->normaliseHost((string) $domain['host']);
            }
        }

        return array_filter($hosts);
    }

    /**
     * Check whether the host we're looking for appears in the app's domain list.
     *
     * @param  string[]  $domains
     */
    private function matchesDomain(array $domains, string $host): bool
    {
        return in_array($host, $domains, strict: true);
    }

    /**
     * Extract the canonical Git repository URL from an application object.
     *
     * Priority:
     *   1. `customGitUrl` — verbatim URL (e.g. "git@github.com:Owner/Repo.git")
     *   2. `owner` + `repository` via GitHub provider → SSH URL
     *   3. null — caller will display "unknown — resolve manually"
     *
     * @param  array<mixed>  $app
     */
    private function extractRepo(array $app): ?string
    {
        if (! empty($app['customGitUrl'])) {
            return (string) $app['customGitUrl'];
        }

        if (! empty($app['owner']) && ! empty($app['repository'])) {
            return "git@github.com:{$app['owner']}/{$app['repository']}.git";
        }

        return null;
    }

    /**
     * Normalise a raw host or URL to a plain lowercase hostname.
     *
     * "https://fr.examplestore.com/api/health" → "fr.examplestore.com"
     * "FR.EXAMPLESTORE.COM"                    → "fr.examplestore.com"
     */
    private function normaliseHost(string $input): string
    {
        $input = trim($input);

        // If the string has a scheme (http:// or https://) parse it properly;
        // otherwise parse_url needs a dummy scheme to work correctly.
        if (! str_contains($input, '://')) {
            $input = 'https://'.$input;
        }

        $parsed = parse_url($input, PHP_URL_HOST);

        return strtolower((string) ($parsed ?: ''));
    }
}
