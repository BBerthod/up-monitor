<?php

namespace App\Http\Requests;

use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Rules\HttpHeadersMap;
use App\Support\UrlNormalizer;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMonitorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $monitor = $this->route('monitor');

        if (! $this->user() || ! $monitor instanceof \App\Models\Monitor) {
            return false;
        }

        return $this->user()->team_id === $monitor->team_id;
    }

    /**
     * Reject a URL that only differs from another HTTP monitor (same team)
     * by host casing or a trailing slash — see
     * StoreMonitorRequest::withValidator() for the full rationale.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $monitor = $this->route('monitor');
            $type = $this->input('type', $monitor?->type?->value ?? MonitorType::HTTP->value);

            if ($type !== MonitorType::HTTP->value) {
                return;
            }

            $url = $this->input('url', $monitor?->url);

            if (! is_string($url) || $url === '') {
                return;
            }

            $normalized = UrlNormalizer::normalize($url);

            $duplicateExists = Monitor::withoutGlobalScopes()
                ->where('team_id', $this->user()->team_id)
                ->where('type', MonitorType::HTTP->value)
                ->where('normalized_url', $normalized)
                ->when($monitor, fn ($query) => $query->whereKeyNot($monitor->id))
                ->exists();

            if ($duplicateExists) {
                $validator->errors()->add(
                    'url',
                    'A monitor for this URL already exists for your team (ignoring host casing and a trailing slash).'
                );
            }
        });
    }

    public function rules(): array
    {
        $type = $this->input('type', $this->route('monitor')?->type?->value ?? 'http');

        $rules = [
            'name' => 'sometimes|required|string|max:255',
            'type' => ['sometimes', 'required', Rule::enum(MonitorType::class)],
            'interval' => 'sometimes|required|integer|min:1|max:60',
            'warning_threshold_ms' => 'nullable|integer|min:1',
            'critical_threshold_ms' => 'nullable|integer|min:1',
            'request_timeout_s' => 'nullable|integer|min:5|max:60',
            'alert_after_failures' => 'integer|min:1|max:10',
            'notification_channels' => 'array',
            'notification_channels.*' => [
                'integer',
                Rule::exists('notification_channels', 'id')
                    ->where('team_id', $this->user()->team_id),
            ],
        ];

        return match ($type) {
            'ping' => array_merge($rules, [
                'url' => 'sometimes|required|string|max:2048',
            ]),
            'port' => array_merge($rules, [
                'url' => 'sometimes|required|string|max:2048',
                'port' => 'sometimes|required|integer|min:1|max:65535',
            ]),
            'dns' => array_merge($rules, [
                'url' => 'sometimes|required|string|max:2048',
                'dns_record_type' => 'sometimes|required|in:A,AAAA,CNAME,MX,TXT,NS,SOA,SRV',
                'dns_expected_value' => 'sometimes|required|string|max:255',
            ]),
            default => array_merge($rules, [
                'url' => ['sometimes', 'required', 'url', 'regex:#^https?://#i', 'max:2048'],
                'method' => 'sometimes|required|in:GET,POST,HEAD',
                'expected_status_code' => 'sometimes|required|integer|min:100|max:599',
                'keyword' => 'nullable|string|max:255',
                'verify_tls' => 'nullable|boolean',
                'follow_redirects' => 'nullable|boolean',
                'redirect_location_keyword' => 'nullable|string|max:255',
                'request_headers' => ['nullable', 'array', 'max:10', new HttpHeadersMap],
            ]),
        };
    }
}
