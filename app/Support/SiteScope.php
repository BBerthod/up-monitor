<?php

namespace App\Support;

use App\Models\Site;

/**
 * Value object representing the active site-scope lens.
 *
 * Three modes:
 *   - all        → no site filter applied (show everything)
 *   - site       → filter by the specific site (site_id column)
 *   - unassigned → filter rows that have no site relationship (site_id IS NULL)
 *
 * Serialised to / from the session as a small array, so no Eloquent objects
 * are stored in the session.
 */
final class SiteScope
{
    public const MODE_ALL = 'all';

    public const MODE_SITE = 'site';

    public const MODE_UNASSIGNED = 'unassigned';

    private function __construct(
        public readonly string $mode,
        public readonly ?Site $site = null,
    ) {}

    // -------------------------------------------------------------------------
    // Constructors
    // -------------------------------------------------------------------------

    public static function all(): self
    {
        return new self(self::MODE_ALL);
    }

    public static function unassigned(): self
    {
        return new self(self::MODE_UNASSIGNED);
    }

    public static function forSite(Site $site): self
    {
        return new self(self::MODE_SITE, $site);
    }

    // -------------------------------------------------------------------------
    // Session serialisation
    // -------------------------------------------------------------------------

    /**
     * @return array{mode: string, site_id: int|null, site_name: string|null, site_domain: string|null}
     */
    public function toSession(): array
    {
        if ($this->site !== null) {
            $domain = $this->site->resolvedPrimaryDomain();

            return [
                'mode' => self::MODE_SITE,
                'site_id' => $this->site->id,
                'site_name' => $this->site->alias ?: $domain,
                'site_domain' => $domain,
            ];
        }

        return [
            'mode' => $this->mode,
            'site_id' => null,
            'site_name' => null,
            'site_domain' => null,
        ];
    }

    /**
     * Restore a SiteScope from its session representation.
     *
     * We intentionally do NOT reload the Site model here — we only store the
     * lightweight site metadata (id, name, domain) that the frontend needs.
     * If the site was deleted between requests the mode falls back to `all`.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromSession(array $data): self
    {
        $mode = $data['mode'] ?? self::MODE_ALL;

        if ($mode === self::MODE_SITE && isset($data['site_id'])) {
            // Re-hydrate the Site model from the DB to confirm it still exists.
            $site = Site::withoutGlobalScopes()
                ->find((int) $data['site_id'], ['id', 'alias', 'primary_domain', 'domains', 'locales', 'primary_locale']);

            if ($site !== null) {
                return self::forSite($site);
            }

            // Site deleted between sessions → fall back to all.
            return self::all();
        }

        if ($mode === self::MODE_UNASSIGNED) {
            return self::unassigned();
        }

        return self::all();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function isAll(): bool
    {
        return $this->mode === self::MODE_ALL;
    }

    public function isSite(): bool
    {
        return $this->mode === self::MODE_SITE;
    }

    public function isUnassigned(): bool
    {
        return $this->mode === self::MODE_UNASSIGNED;
    }

    /**
     * Inertia-shareable representation (lightweight, no Eloquent models).
     *
     * @return array{mode: string, site: array{id: int, name: string, domain: string}|null}
     */
    public function toInertia(): array
    {
        if ($this->site !== null) {
            $domain = $this->site->resolvedPrimaryDomain();

            return [
                'mode' => self::MODE_SITE,
                'site' => [
                    'id' => $this->site->id,
                    'name' => $this->site->alias ?: $domain,
                    'domain' => $domain,
                ],
            ];
        }

        return [
            'mode' => $this->mode,
            'site' => null,
        ];
    }
}
