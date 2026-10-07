<?php

use App\Http\Controllers\ActionPlanController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\BadgeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\DigestController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\FixPromptController;
use App\Http\Controllers\FunctionalCheckController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\IngestSourceController;
use App\Http\Controllers\InsightController;
use App\Http\Controllers\KpiTrendsController;
use App\Http\Controllers\MonitorController;
use App\Http\Controllers\NotificationChannelController;
use App\Http\Controllers\NotificationLogController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicStatusPageController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\StatusPageController;
use App\Http\Controllers\StrikingDistanceController;
use App\Http\Controllers\TeamSettingsController;
use App\Http\Controllers\WarmSiteController;
use App\Http\Controllers\WelcomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', WelcomeController::class)->name('welcome');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:auth');

    // Password reset
    Route::get('/forgot-password', [PasswordResetController::class, 'showForgotForm'])->name('password.request')->middleware('throttle:auth');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])->name('password.email')->middleware('throttle:auth');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'showResetForm'])->name('password.reset')->middleware('throttle:auth');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->name('password.update')->middleware('throttle:auth');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/inbox', [InboxController::class, 'index'])->name('inbox.index');
    Route::get('/action-plan', [ActionPlanController::class, 'index'])->name('action-plan.index');
    Route::get('/kpi-trends', [KpiTrendsController::class, 'index'])->name('kpi-trends.index');
    Route::get('/striking-distance', [StrikingDistanceController::class, 'index'])->name('striking-distance.index');
    Route::get('/digest', [DigestController::class, 'index'])->name('digest.index');

    // Profile
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    Route::get('/settings', [TeamSettingsController::class, 'index'])->name('settings.index');
    Route::put('/settings/team', [TeamSettingsController::class, 'updateTeam'])->name('settings.team.update');
    Route::post('/settings/tokens', [TeamSettingsController::class, 'createToken'])->name('settings.tokens.store');
    Route::delete('/settings/tokens/{tokenId}', [TeamSettingsController::class, 'deleteToken'])->name('settings.tokens.destroy');
    Route::delete('/settings/purge', [TeamSettingsController::class, 'purgeAll'])->name('settings.purge');
    Route::post('/settings/weekly-report', [TeamSettingsController::class, 'updateWeeklyReport'])->name('settings.weekly-report.update');

    Route::post('/insights/{insight}/acknowledge', [InsightController::class, 'acknowledge'])->name('insights.acknowledge');
    Route::post('/insights/{insight}/snooze', [InsightController::class, 'snooze'])->name('insights.snooze');
    Route::post('/insights/{insight}/vikunja', [InsightController::class, 'toVikunja'])->name('insights.vikunja');

    Route::get('/incidents/{incident}/fix', [FixPromptController::class, 'incident'])->name('incidents.fix');
    Route::get('/insights/{insight}/fix', [FixPromptController::class, 'insight'])->name('insights.fix');

    Route::get('/incidents', [IncidentController::class, 'index'])->name('incidents.index');
    Route::get('/incidents/export', [IncidentController::class, 'export'])->name('incidents.export');
    Route::put('/incidents/{incident}', [IncidentController::class, 'update'])->name('incidents.update');

    Route::resource('servers', ServerController::class);
    Route::post('/servers/{server}/rotate-token', [ServerController::class, 'rotateToken'])->name('servers.rotate-token');
    Route::resource('sites', SiteController::class);

    Route::post('/monitors/bulk-action', [MonitorController::class, 'bulkAction'])->name('monitors.bulk-action');
    Route::resource('monitors', MonitorController::class);
    Route::post('/monitors/{monitor}/pause', [MonitorController::class, 'pause'])->name('monitors.pause');
    Route::post('/monitors/{monitor}/resume', [MonitorController::class, 'resume'])->name('monitors.resume');
    Route::post('/monitors/{monitor}/lighthouse', [MonitorController::class, 'lighthouse'])->name('monitors.lighthouse');
    Route::get('/monitors/{monitor}/lighthouse-history', [MonitorController::class, 'lighthouseHistory'])->name('monitors.lighthouse-history');
    Route::delete('/monitors/{monitor}/purge', [MonitorController::class, 'purge'])->name('monitors.purge');

    Route::prefix('monitors/{monitor}/functional-checks')->name('monitors.functional-checks.')->group(function () {
        Route::post('/', [FunctionalCheckController::class, 'store'])->name('store');
        Route::put('/{functionalCheck}', [FunctionalCheckController::class, 'update'])->name('update');
        Route::delete('/{functionalCheck}', [FunctionalCheckController::class, 'destroy'])->name('destroy');
        Route::post('/{functionalCheck}/run-now', [FunctionalCheckController::class, 'runNow'])->name('run-now');
    });

    Route::resource('channels', NotificationChannelController::class)->except(['show']);
    Route::post('/channels/{channel}/test', [NotificationChannelController::class, 'test'])->name('channels.test');

    Route::resource('status-pages', StatusPageController::class)->except(['show']);

    // Ingest Sources
    Route::get('/sources', [IngestSourceController::class, 'index'])->name('sources.index');
    Route::post('/sources', [IngestSourceController::class, 'store'])->name('sources.store');
    Route::put('/sources/{source}', [IngestSourceController::class, 'update'])->name('sources.update');
    Route::delete('/sources/{source}', [IngestSourceController::class, 'destroy'])->name('sources.destroy');
    Route::post('/sources/{source}/rotate-token', [IngestSourceController::class, 'rotateToken'])->name('sources.rotate-token');

    // Notification History
    Route::get('/notification-logs', [NotificationLogController::class, 'index'])->name('notification-logs.index');

    // Events
    Route::get('/events', [EventController::class, 'index'])->name('events.index');

    // Deploy gates
    Route::get('/deployments', [DeploymentController::class, 'index'])->name('deployments.index');
    Route::post('/deployments/smoke-test-configs', [DeploymentController::class, 'storeSmokeTestConfig'])->name('deployments.smoke-test-configs.store');
    Route::delete('/deployments/smoke-test-configs/{config}', [DeploymentController::class, 'destroySmokeTestConfig'])->name('deployments.smoke-test-configs.destroy');

    // Cache Warming
    Route::resource('warming', WarmSiteController::class);
    Route::post('/warming/{warming}/warm-now', [WarmSiteController::class, 'warmNow'])->name('warming.warm-now');
    Route::get('/warming/{warming}/runs/{warmRun}', [WarmSiteController::class, 'runDetail'])->name('warming.run-detail');

    // Admin routes
    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::resource('users', AdminUserController::class);
    });
});

// Public routes (no auth)
Route::get('/status/{slug}', [PublicStatusPageController::class, 'show'])->name('status.show');
Route::get('/badge/{secret}.svg', BadgeController::class)->name('badge');
