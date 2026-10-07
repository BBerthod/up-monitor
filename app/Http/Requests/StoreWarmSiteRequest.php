<?php

namespace App\Http\Requests;

use App\Enums\WarmSiteMode;
use App\Rules\HttpHeadersMap;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWarmSiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->mode === 'sitemap') {
            $this->merge(['urls' => null]);
        } elseif (is_array($this->urls)) {
            $this->merge(['urls' => array_values(array_filter($this->urls, fn ($u) => trim($u) !== ''))]);
        }

        // Drop an emptied timeout so the column default applies instead of NULL.
        if ($this->input('timeout_seconds') === null) {
            $this->request->remove('timeout_seconds');
        }
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'domain' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)*\.[a-z]{2,}$/i',
                Rule::unique('warm_sites')->where('team_id', auth()->user()->team_id),
            ],
            'mode' => ['required', Rule::enum(WarmSiteMode::class)],
            'sitemap_url' => 'nullable|url|required_if:mode,sitemap',
            'urls' => 'nullable|array|max:500|required_if:mode,urls',
            'urls.*' => 'exclude_if:mode,sitemap|url',
            'frequency_minutes' => ['required', 'integer', Rule::in([15, 30, 60, 120, 360, 720, 1440])],
            'max_urls' => 'required|integer|min:1|max:500',
            'custom_headers' => ['nullable', 'array', 'max:10', new HttpHeadersMap],
            'timeout_seconds' => 'sometimes|nullable|integer|min:5|max:60',
            'monitor_id' => 'nullable|integer|exists:monitors,id',
        ];
    }
}
