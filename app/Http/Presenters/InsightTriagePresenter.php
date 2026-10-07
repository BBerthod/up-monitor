<?php

namespace App\Http\Presenters;

use App\Models\Insight;

/**
 * Canonical triage card serialisation — shared by DashboardController and InboxController.
 * Eager-load site/server/monitor before calling present() to avoid N+1.
 */
class InsightTriagePresenter
{
    public static function present(Insight $insight): array
    {
        return [
            'id' => $insight->id,
            'severity' => $insight->severity->value,
            'type' => $insight->type->value,
            'domain' => $insight->type->domain()->value,
            'title' => $insight->title,
            'label' => $insight->type->label(),
            'display_title' => self::displayTitle($insight),
            // linkedSite, not site: the legacy `site` attribute is the hostname string.
            'site' => $insight->site_id && $insight->linkedSite
                ? ['id' => $insight->linkedSite->id, 'name' => $insight->linkedSite->name]
                : null,
            'server' => $insight->server_id && $insight->server
                ? ['id' => $insight->server->id, 'name' => $insight->server->name]
                : null,
            'monitor_id' => $insight->monitor_id,
            'detected_at' => $insight->detected_at?->toIso8601String(),
            'impact_score' => (float) $insight->impact_score,
            'fix_url' => route('insights.fix', $insight->id),
            'acknowledge_url' => route('insights.acknowledge', $insight->id),
            'snooze_url' => route('insights.snooze', $insight->id),
            // Null while the integration is off, so the button simply does not
            // render rather than posting to an endpoint that would refuse.
            'vikunja_url' => config('vikunja.enabled')
                ? route('insights.vikunja', $insight->id)
                : null,
        ];
    }

    /**
     * Human-readable headline for a triage card.
     *
     * Detectors write real sentences ("No consent platform detected on …"), but
     * some rows carry a machine title built from the enum value
     * ("Health_drop detected on delta"). Those are replaced by the type label,
     * followed by the entity it concerns, so the card never shows raw slugs.
     */
    public static function displayTitle(Insight $insight): string
    {
        $title = trim((string) $insight->title);

        if ($title !== '' && ! str_starts_with(strtolower($title), $insight->type->value)) {
            return $title;
        }

        $entity = ($insight->site_id ? $insight->linkedSite?->name : null)
            ?? ($insight->server_id ? $insight->server?->name : null)
            ?? (is_string($insight->site) && $insight->site !== '' ? $insight->site : null);

        return $entity !== null
            ? $insight->type->label().' — '.$entity
            : $insight->type->label();
    }
}
