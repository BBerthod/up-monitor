<?php

namespace App\Providers;

use App\Events\IncidentCreated;
use App\Events\IncidentResolved;
use App\Listeners\CreateUptimeInsight;
use App\Listeners\ResolveUptimeInsight;
use App\Models\IngestSource;
use App\Models\Insight;
use App\Models\Monitor;
use App\Models\MonitorIncident;
use App\Models\NotificationChannel;
use App\Models\Server;
use App\Models\Site;
use App\Models\StatusPage;
use App\Models\WarmSite;
use App\Observers\SiteObserver;
use App\Policies\IngestSourcePolicy;
use App\Policies\InsightPolicy;
use App\Policies\MonitorIncidentPolicy;
use App\Policies\MonitorPolicy;
use App\Policies\NotificationChannelPolicy;
use App\Policies\ServerPolicy;
use App\Policies\SitePolicy;
use App\Policies\StatusPagePolicy;
use App\Policies\WarmSitePolicy;
use App\Services\Ai\AiProvider;
use App\Services\Ai\GeminiProvider;
use App\Services\Ai\NullProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    protected $policies = [
        IngestSource::class => IngestSourcePolicy::class,
        Insight::class => InsightPolicy::class,
        Monitor::class => MonitorPolicy::class,
        MonitorIncident::class => MonitorIncidentPolicy::class,
        NotificationChannel::class => NotificationChannelPolicy::class,
        Server::class => ServerPolicy::class,
        Site::class => SitePolicy::class,
        StatusPage::class => StatusPagePolicy::class,
        WarmSite::class => WarmSitePolicy::class,
    ];

    public function register(): void
    {
        $this->app->bind(AiProvider::class, function ($app) {
            $provider = config('services.ai.provider', 'null');

            return match ($provider) {
                'gemini' => $app->make(GeminiProvider::class),
                default => $app->make(NullProvider::class),
            };
        });
    }

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        RateLimiter::for('api', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(60)->by($request->user()->id)
                : Limit::perMinute(30)->by($request->ip());
        });

        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)->by($request->email.'|'.$request->ip());
        });

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        Event::listen(IncidentCreated::class, CreateUptimeInsight::class);
        Event::listen(IncidentResolved::class, ResolveUptimeInsight::class);

        Site::observe(SiteObserver::class);
    }
}
