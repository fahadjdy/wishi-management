<?php

namespace App\Http\Requests\Wishi;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWishiRequest extends FormRequest
{
    public function authorize(): bool
    {
        \Illuminate\Support\Facades\Gate::authorize('create', \App\Models\Wishi::class);
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'total_members' => ['required', 'integer', 'min:2', 'max:100'],
            'monthly_contribution' => ['required', 'numeric', 'min:1', 'max:9999999.99'],
            'cycle_frequency' => ['sometimes', Rule::in(['daily', 'weekly', 'monthly', 'quarterly', 'yearly', 'custom'])],
            'cycle_interval_days' => ['nullable', 'integer', 'min:1', 'max:365', 'required_if:cycle_frequency,custom'],
            'cycle_day' => ['nullable', 'string', 'max:20'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'wishi_opening_time' => ['required', 'date_format:H:i'],
            'auto_join' => ['sometimes', 'boolean'],
            'require_approval' => ['sometimes', 'boolean'],
            'winner_selection_mode' => ['sometimes', Rule::in(['auto', 'manual'])],
            'cycle_type' => ['required', Rule::in(['random', 'hybrid'])],
            'hybrid_pattern' => ['nullable', 'array', 'min:1', 'max:24', 'required_if:cycle_type,hybrid'],
            'hybrid_pattern.*' => [Rule::in(['random', 'tender', 'auto'])],
            'status' => ['sometimes', Rule::in(['draft', 'active'])],
        ];
    }
}
