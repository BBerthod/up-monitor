<?php

namespace App\Http\Requests;

use App\Models\Server;
use Illuminate\Foundation\Http\FormRequest;

class StoreServerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Server::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'dokploy_server_id' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'settings' => ['nullable', 'array'],
            'settings.thresholds' => ['nullable', 'array'],
            'settings.thresholds.load_cores' => ['nullable', 'integer', 'min:1', 'max:1024'],
            'settings.thresholds.load_warning' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'settings.thresholds.load_critical' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'settings.thresholds.*' => ['nullable', 'numeric'],
        ];
    }
}
