<?php

namespace App\Http\Requests;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInsightRequest extends FormRequest
{
    public function authorize(): bool
    {
        // InsightPolicy has no create() method; auth:sanctum middleware handles auth.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(InsightType::class)],
            'severity' => ['required', Rule::enum(InsightSeverity::class)],
            'title' => ['required', 'string', 'max:255'],
            'site' => ['required', 'string', 'max:255'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'payload' => ['nullable', 'array'],
            'impact_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'detected_at' => ['nullable', 'date'],
        ];
    }
}
