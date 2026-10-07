<?php

namespace App\Http\Controllers;

use App\Models\StatusPage;
use App\Services\PublicStatusPageService;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class PublicStatusPageController extends Controller
{
    public function __construct(private readonly PublicStatusPageService $service) {}

    public function show(string $slug): Response
    {
        $statusPage = StatusPage::withoutGlobalScopes()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $cachedData = Cache::remember(
            "status_page:{$slug}",
            120,
            fn () => $this->service->build($statusPage, route('status.show', $slug)),
        );

        return Inertia::render('StatusPages/Public', $cachedData);
    }
}
