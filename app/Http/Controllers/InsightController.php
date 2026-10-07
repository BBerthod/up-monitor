<?php

namespace App\Http\Controllers;

use App\Enums\InsightType;
use App\Exceptions\VikunjaException;
use App\Models\Insight;
use App\Services\Vikunja\VikunjaClient;
use App\Services\Vikunja\VikunjaTaskService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class InsightController extends Controller
{
    public function acknowledge(Insight $insight): RedirectResponse
    {
        $this->authorize('update', $insight);

        $insight->acknowledge();

        return back();
    }

    public function snooze(Insight $insight, Request $request): RedirectResponse
    {
        $this->authorize('update', $insight);

        $validated = $request->validate([
            'duration' => ['required', 'string', 'in:1h,1d,7d,until_recovery'],
        ]);

        $duration = $validated['duration'];

        if ($duration === 'until_recovery' && $insight->type !== InsightType::SERVER_HEALTH) {
            return back()->withErrors([
                'duration' => 'The "until_recovery" duration is only available for Server Health insights.',
            ])->withInput();
        }

        $until = match ($duration) {
            '1h' => now()->addHour(),
            '1d' => now()->addDay(),
            '7d' => now()->addDays(7),
            // until_recovery: snooze for one year; the ServerHealthDetector will
            // stamp acknowledged_at when the metric recovers, removing the insight
            // from triage before the snooze ever expires naturally.
            'until_recovery' => now()->addYear(),
        };

        $insight->snooze($until);

        return back();
    }

    /**
     * Send an insight to the Vikunja board on demand.
     *
     * This is the manual escape hatch from the promotion policy: automatic
     * promotion only fires for CRITICAL problems that have persisted, so a
     * WARNING worth working on would otherwise never reach the board. A human
     * looking at the insight has already decided it is work — no threshold
     * applies, and no severity check either.
     *
     * Idempotent: a second click returns the existing card instead of creating
     * a duplicate.
     */
    public function toVikunja(Insight $insight, VikunjaTaskService $service, VikunjaClient $client): RedirectResponse
    {
        $this->authorize('update', $insight);

        if (! $client->isConfigured()) {
            return back()->withErrors([
                'vikunja' => "L'intégration Vikunja n'est pas configurée.",
            ]);
        }

        try {
            $link = $service->promoteManually($insight);
        } catch (VikunjaException $e) {
            report($e);

            return back()->withErrors([
                'vikunja' => 'Vikunja n\'a pas répondu, la carte n\'a pas été créée.',
            ]);
        }

        return back()->with('status', "Carte Vikunja #{$link->vikunja_task_id} créée.");
    }
}
