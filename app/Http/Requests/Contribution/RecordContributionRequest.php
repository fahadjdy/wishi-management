<?php

namespace App\Http\Requests\Contribution;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordContributionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only the WISHI admin can record contribution payments (privacy + integrity rule).
        $cycle = $this->route('cycle');
        $user = $this->user();
        return (int) $cycle->wishi->created_by === (int) $user->id;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'paid_at' => ['nullable', 'date'],
            'payment_method' => ['nullable', Rule::in(['bank_transfer', 'upi', 'cash', 'cheque', 'other'])],
            'payment_reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
