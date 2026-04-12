<?php

namespace App\Http\Requests\Cycle;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('recordPayout', $this->route('cycle'));
    }

    public function rules(): array
    {
        return [
            'method' => ['required', Rule::in(['bank_transfer', 'upi', 'cash', 'cheque', 'other'])],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
