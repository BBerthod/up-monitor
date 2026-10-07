<?php

namespace App\Http\Requests;

use App\Enums\ReportFrequency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'alias' => [
                'required',
                'string',
                'max:255',
                Rule::unique('sites', 'alias')->where('team_id', $this->user()->team_id),
            ],
            'organization' => ['nullable', 'string', 'max:255'],
            'primary_domain' => ['required', 'string', 'max:255'],
            'domains' => ['required', 'array', 'min:1'],
            'domains.*' => ['string', 'max:255'],
            'locales' => ['nullable', 'array'],
            'locales.*' => ['string', 'max:10'],
            'primary_locale' => ['nullable', 'string', 'max:10'],
            'type' => ['required', 'string', Rule::in(['laravel', 'wordpress', 'static', 'compose'])],
            'health_endpoint' => ['nullable', 'string', 'max:2048'],
            'gsc_property' => ['nullable', 'string', 'max:255'],
            'bing_url' => ['nullable', 'string', 'max:2048'],
            'ga4_property' => ['nullable', 'string', 'max:255'],
            'sitemap_path' => ['nullable', 'string', 'max:2048'],
            'sitemap_locale_pattern' => ['nullable', 'string', 'max:255'],
            'key_pages' => ['nullable', 'array'],
            'key_pages.*' => ['string', 'max:2048'],
            'ad_networks' => ['nullable', 'array'],
            'ad_networks.*' => ['string', 'max:255'],
            'merchant_domains' => ['nullable', 'array'],
            'merchant_domains.*' => ['string', 'max:255'],
            'amazon_tag' => ['nullable', 'string', 'max:255'],
            'dokploy_app_id' => ['nullable', 'string', 'max:255'],
            'dokploy_resource_type' => [
                'nullable',
                'string',
                Rule::in(['application', 'compose']),
            ],
            'is_active' => ['boolean'],
            'report_frequency' => ['sometimes', 'string', Rule::in(array_column(ReportFrequency::cases(), 'value'))],
            'report_recipients' => ['nullable', 'array'],
            'report_recipients.*' => ['email', 'max:255'],
        ];
    }
}
