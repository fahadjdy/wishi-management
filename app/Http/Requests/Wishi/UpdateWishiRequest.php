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
            'wishi_opening_time' => ['sometimes', 'date_format:H:i'],
            'start_date' => ['sometimes', 'date', 'after_or_equal:today'],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'cancelled'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Guard: start_date can only be rescheduled while the WISHI has not
        // yet activated. Once status is active / completed / cancelled, the
        // cycle-#1 start is baked into cycle rows and contribution due dates.
        if ($this->has('start_date')) {
            $wishi = $this->route('wishi');
            if ($wishi && ! in_array($wishi->status, ['draft', 'planned'], true)) {
                abort(422, 'Start date can only be changed while the WISHI is in draft or planned state.');
            }
        }
    }
}
