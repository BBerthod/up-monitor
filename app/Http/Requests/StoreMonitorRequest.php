<?php

namespace App\Http\Requests;

use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Rules\HttpHeadersMap;
use App\Support\UrlNormalizer;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMonitorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Reject a URL that only differs from an existing HTTP monitor (same
     * team) by host casing or a trailing slash — the exact shape of
     * duplicate that doubled PSI audits and burned quota (audit 2026-09-01).
     * Ping/Port/DNS monitors are excluded: they legitimately reuse the same
     * host with a different port or record type. Scheme (http vs https) and
     * "www." are deliberately NOT considered duplicates: see UrlNormalizer.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = $this->input('type', MonitorType::HTTP->value);

            if ($type !== MonitorType::HTTP->value || ! $this->filled('url')) {
                return;
            }

            $normalized = UrlNormalizer::normalize((string) $this->input('url'));

            $duplicateExists = Monitor::withoutGlobalScopes()
                ->where('team_id', $this->user()->team_id)
                ->where('type', MonitorType::HTTP->value)
                ->where('normalized_url', $normalized)
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
        $type = $this->input('type', 'http');

        $rules = [
            'name' => 'required|string|max:255',
            'type' => ['required', Rule::enum(MonitorType::class)],
            'interval' => 'required|integer|min:1|max:60',
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
                'url' => 'required|string|max:2048',
            ]),
            'port' => array_merge($rules, [
                'url' => 'required|string|max:2048',
                'port' => 'required|integer|min:1|max:65535',
            ]),
            'dns' => array_merge($rules, [
                'url' => 'required|string|max:2048',
                'dns_record_type' => 'required|in:A,AAAA,CNAME,MX,TXT,NS,SOA,SRV',
                'dns_expected_value' => 'required|string|max:255',
            ]),
            default => array_merge($rules, [
                'url' => ['required', 'url', 'regex:#^https?://#i', 'max:2048'],
                'method' => 'required|in:GET,POST,HEAD',
                'expected_status_code' => 'required|integer|min:100|max:599',
                'keyword' => 'nullable|string|max:255',
                'verify_tls' => 'nullable|boolean',
                'follow_redirects' => 'nullable|boolean',
                'redirect_location_keyword' => 'nullable|string|max:255',
                'request_headers' => ['nullable', 'array', 'max:10', new HttpHeadersMap],
            ]),
        };
    }
}
