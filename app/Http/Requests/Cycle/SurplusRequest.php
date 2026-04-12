<?php

namespace App\Http\Requests\Cycle;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SurplusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('handleSurplus', $this->route('cycle'));
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['distribute', 'reserve', 'admin_adjust', 'bonus'])],
            'recipient_id' => ['required_if:action,bonus', 'integer', 'exists:users,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
