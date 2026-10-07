<?php

namespace App\Http\Controllers;

use App\Enums\FunctionalCheckType;
use App\Jobs\RunFunctionalCheck;
use App\Models\FunctionalCheck;
use App\Models\Monitor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FunctionalCheckController extends Controller
{
    public function store(Request $request, Monitor $monitor): RedirectResponse
    {
        $this->authorize('update', $monitor);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string', 'max:2048'],
            'type' => ['required', Rule::enum(FunctionalCheckType::class)],
            'rules' => ['required', 'array'],
            'rules.*.type' => ['required', 'string'],
            'rules.*.value' => ['nullable'],
            'check_interval' => ['sometimes', 'integer', 'min:5', 'max:1440'],
        ]);

        $monitor->functionalChecks()->create($validated);

        return back()->with('success', 'Functional check created.');
    }

    public function update(Request $request, Monitor $monitor, FunctionalCheck $functionalCheck): RedirectResponse
    {
        $this->authorize('update', $monitor);
        abort_if($functionalCheck->monitor_id !== $monitor->id, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string', 'max:2048'],
            'type' => ['required', Rule::enum(FunctionalCheckType::class)],
            'rules' => ['required', 'array'],
            'rules.*.type' => ['required', 'string'],
            'rules.*.value' => ['nullable'],
            'check_interval' => ['sometimes', 'integer', 'min:5', 'max:1440'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);

        $functionalCheck->update($validated);

        return back()->with('success', 'Functional check updated.');
    }

    public function destroy(Monitor $monitor, FunctionalCheck $functionalCheck): RedirectResponse
    {
        $this->authorize('update', $monitor);
        abort_if($functionalCheck->monitor_id !== $monitor->id, 403);

        $functionalCheck->delete();

        return back()->with('success', 'Functional check deleted.');
    }

    public function runNow(Monitor $monitor, FunctionalCheck $functionalCheck): RedirectResponse
    {
        $this->authorize('update', $monitor);
        abort_if($functionalCheck->monitor_id !== $monitor->id, 403);

        RunFunctionalCheck::dispatch($functionalCheck);

        return back()->with('success', 'Check queued.');
    }
}
