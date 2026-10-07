<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSiteRequest;
use App\Http\Requests\UpdateSiteRequest;
use App\Http\Resources\SiteResource;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class SiteApiController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Site::class);

        // BelongsToTeam global scope ensures only the authenticated team's sites are returned.
        $sites = Site::query()
            ->withCount('monitors')
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return SiteResource::collection($sites);
    }

    public function show(Site $site): SiteResource
    {
        $this->authorize('view', $site);

        $site->loadCount('monitors');

        return new SiteResource($site);
    }

    public function store(StoreSiteRequest $request): JsonResponse
    {
        $site = Site::create(array_merge($request->validated(), [
            'team_id' => $request->user()->team_id,
        ]));

        return (new SiteResource($site))->response()->setStatusCode(201);
    }

    public function update(UpdateSiteRequest $request, Site $site): SiteResource
    {
        $this->authorize('update', $site);

        $site->update($request->validated());

        return new SiteResource($site);
    }

    public function destroy(Site $site): Response
    {
        $this->authorize('delete', $site);

        $site->delete();

        return response()->noContent();
    }
}
