<?php

namespace App\Jobs;

use App\Enums\WarmRunStatus;
use App\Events\WarmRunProgress;
use App\Models\WarmRun;
use App\Models\WarmRunUrl;
use App\Models\WarmSite;
use App\Services\WarmingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunWarmSite implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600; // 10 min — covers sitemap index fetch + URL warming

    public array $backoff = [300, 3600];

    public int $uniqueFor = 660; // Prevent duplicate jobs for same site (slightly > timeout)

    public function __construct(public WarmSite $warmSite)
    {
        // Use redis_long connection: retry_after=700s > $timeout=600s, preventing
        // premature requeue that would defeat ShouldBeUnique and cause duplicate warm runs.
        // Dedicated 'warming' queue keeps warm runs isolated from lighthouse audits.
        $this->onConnection('redis_long')->onQueue('warming');
    }

    public function uniqueId(): string
    {
        return (string) $this->warmSite->id;
    }

    public function retryUntil(): \DateTime
    {
        $maxUrls = max(1, (int) $this->warmSite->max_urls);
        $seconds = max(120, min(7200, $maxUrls * 15));

        return now()->addSeconds($seconds);
    }

    public function handle(WarmingService $warmingService): void
    {
        $lock = Cache::lock("warming:{$this->warmSite->id}", 660); // Match timeout + buffer

        if (! $lock->get()) {
            return;
        }

        $warmRun = null;

        try {
            $warmRun = WarmRun::create([
                'warm_site_id' => $this->warmSite->id,
                'status' => WarmRunStatus::RUNNING,
                'urls_total' => 0,
                'urls_hit' => 0,
                'urls_miss' => 0,
                'urls_error' => 0,
                'avg_response_ms' => 0,
                'started_at' => now(),
            ]);

            $urls = $warmingService->resolveUrls($this->warmSite);
            $customHeaders = $this->warmSite->custom_headers ?? [];
            $timeoutSeconds = $this->warmSite->timeout_seconds ?? 10;

            if (empty($urls)) {
                $warmRun->update([
                    'status' => WarmRunStatus::FAILED,
                    'error_message' => 'No URLs resolved (sitemap empty or unreachable)',
                    'completed_at' => now(),
                ]);
                $this->warmSite->update(['last_warmed_at' => now()]);

                return;
            }

            $hits = 0;
            $misses = 0;
            $errors = 0;
            $totalResponseMs = 0;
            $consecutiveErrors = 0;
            $errorMessage = null;
            $urlBatch = [];
            $urlCount = count($urls);

            foreach ($urls as $index => $url) {
                if ($index > 0) {
                    usleep(1_000_000);
                }

                $result = $warmingService->warmUrl($url, $customHeaders, $timeoutSeconds);

                $totalResponseMs += $result->responseTimeMs;

                if ($result->isError()) {
                    $errors++;
                    $consecutiveErrors++;
                } elseif ($result->isHit()) {
                    $hits++;
                    $consecutiveErrors = 0;
                } else {
                    $misses++;
                    $consecutiveErrors = 0;
                }

                $urlBatch[] = [
                    'warm_run_id' => $warmRun->id,
                    'url' => $result->url,
                    'status_code' => $result->statusCode,
                    'cache_status' => $result->cacheStatus,
                    'response_time_ms' => $result->responseTimeMs,
                    'error_message' => $result->errorMessage,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if ($result->statusCode === 429) {
                    sleep(5);
                }

                if ($consecutiveErrors >= 3) {
                    $errorMessage = "Stopped early: 3 consecutive errors. Last error: {$result->errorMessage}";
                    break;
                }

                if (($index + 1) % 5 === 0 || $index === $urlCount - 1) {
                    WarmRunProgress::dispatch(
                        $this->warmSite->team_id,
                        $this->warmSite->id,
                        $warmRun->id,
                        $index + 1,
                        $urlCount,
                        $hits,
                        $misses,
                        $errors,
                    );
                }
            }

            if (! empty($urlBatch)) {
                WarmRunUrl::insert($urlBatch);
            }

            $total = $hits + $misses + $errors;
            $avgResponseMs = $total > 0 ? (int) round($totalResponseMs / $total) : 0;

            $warmRun->update([
                'urls_total' => $total,
                'urls_hit' => $hits,
                'urls_miss' => $misses,
                'urls_error' => $errors,
                'avg_response_ms' => $avgResponseMs,
                'status' => $errorMessage ? WarmRunStatus::FAILED : WarmRunStatus::COMPLETED,
                'error_message' => $errorMessage,
                'completed_at' => now(),
            ]);

            WarmRunProgress::dispatch(
                $this->warmSite->team_id,
                $this->warmSite->id,
                $warmRun->id,
                count($urls),
                count($urls),
                $hits,
                $misses,
                $errors,
                true,
            );

            $this->warmSite->update(['last_warmed_at' => now()]);

            if ($errorMessage) {
                app(\App\Services\NotificationService::class)->notifyWarmingFailed($this->warmSite, $warmRun);
            } else {
                // A successful run resolves any open WARMING_DISABLED insight, whether
                // it completed because the site was reactivated or via a manual
                // "Warm Now" while still disabled (warmNow() does not gate on is_active).
                app(\App\Services\NotificationService::class)->resolveWarmingDisabled($this->warmSite);
            }
        } catch (\Throwable $e) {
            if ($warmRun) {
                $warmRun->update([
                    'status' => WarmRunStatus::FAILED,
                    'error_message' => 'Job exception: '.$e->getMessage(),
                    'completed_at' => now(),
                ]);
            }

            Log::error('RunWarmSite: unexpected exception', [
                'warm_site_id' => $this->warmSite->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            $lock->release();
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('RunWarmSite job failed', [
            'warm_site_id' => $this->warmSite->id,
            'warm_site_name' => $this->warmSite->name,
            'error' => $e->getMessage(),
        ]);

        try {
            $run = WarmRun::create([
                'warm_site_id' => $this->warmSite->id,
                'status' => WarmRunStatus::FAILED,
                'error_message' => $e->getMessage(),
                'started_at' => now(),
                'completed_at' => now(),
            ]);

            app(\App\Services\NotificationService::class)->notifyWarmingFailed($this->warmSite, $run);
        } catch (\Throwable $notifyError) {
            Log::error('RunWarmSite: failed to send failure notification', [
                'error' => $notifyError->getMessage(),
            ]);
        }
    }
}
