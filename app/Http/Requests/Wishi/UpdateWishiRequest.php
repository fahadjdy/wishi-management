<?php

namespace App\Http\Requests\Wishi;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWishiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('wishi'));
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'auto_join' => ['sometimes', 'boolean'],
            'require_approval' => ['sometimes', 'boolean'],
            'winner_selection_mode' => ['sometimes', Rule::in(['auto', 'manual'])],
            'min_credit_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'max_active_wishis_per_member' => ['nullable', 'integer', 'min:1', 'max:50'],
            'block_if_missed_payments' => ['sometimes', 'boolean'],
            'tender_start_time' => ['nullable', 'date_format:H:i'],
            'tender_end_time' => ['nullable', 'date_format:H:i'],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'cancelled'])],
        ];
    }
}
