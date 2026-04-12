<?php

namespace App\Http\Requests\Cycle;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SelectWinnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('selectWinner', $this->route('cycle'));
    }

    public function rules(): array
    {
        return [
            'method' => ['required', Rule::in(['auto', 'manual'])],
            'user_id' => ['required_if:method,manual', 'integer', 'exists:users,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
