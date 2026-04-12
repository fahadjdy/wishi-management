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
            'duration_months' => ['required', 'integer', 'min:2', 'max:120'],
            'cycle_frequency' => ['sometimes', Rule::in(['daily', 'weekly', 'monthly', 'quarterly', 'yearly', 'custom'])],
            'cycle_interval_days' => ['nullable', 'integer', 'min:1', 'max:365', 'required_if:cycle_frequency,custom'],
            'cycle_day' => ['nullable', 'string', 'max:20'],
            'bidding_window_days' => ['nullable', 'integer', 'min:0', 'max:30'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'auto_join' => ['sometimes', 'boolean'],
            'require_approval' => ['sometimes', 'boolean'],
            'winner_selection_mode' => ['sometimes', Rule::in(['auto', 'manual'])],
            'cycle_type' => ['required', Rule::in(['random', 'tender', 'hybrid'])],
            'hybrid_pattern' => ['nullable', 'array', 'min:1', 'max:24', 'required_if:cycle_type,hybrid'],
            'hybrid_pattern.*' => [Rule::in(['random', 'tender', 'auto'])],
            'min_credit_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'max_active_wishis_per_member' => ['nullable', 'integer', 'min:1', 'max:50'],
            'block_if_missed_payments' => ['sometimes', 'boolean'],
            'tender_start_time' => ['nullable', 'date_format:H:i'],
            'tender_end_time' => ['nullable', 'date_format:H:i'],
            'status' => ['sometimes', Rule::in(['draft', 'active'])],
        ];
    }

    public function validated($key = null, $default = null): array
    {
        $data = parent::validated();
        if (isset($data['duration_months']) && isset($data['total_members']) && $data['duration_months'] < $data['total_members']) {
            // each member needs at least one cycle to win; soft warning only
        }
        return $data;
    }
}
