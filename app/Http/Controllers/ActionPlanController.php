<?php

namespace App\Http\Controllers;

use App\Services\ActionPlanService;
use App\Services\TriageService;
use Inertia\Inertia;
use Inertia\Response;

class ActionPlanController extends Controller
{
    public function index(ActionPlanService $actionPlan, TriageService $triage): Response
    {
        $team = auth()->user()->team;

        if (! $team) {
            return Inertia::render('ActionPlan', [
                'actions' => [],
                'counts' => ['total' => 0, 'critical' => 0, 'domains' => []],
            ]);
        }

        return Inertia::render('ActionPlan', [
            'actions' => $actionPlan->forTeam($team),
            'counts' => $triage->counts($team),
        ]);
    }
}
