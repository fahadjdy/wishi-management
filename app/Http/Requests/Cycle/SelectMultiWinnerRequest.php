<?php

namespace App\Http\Requests\Cycle;

use Illuminate\Foundation\Http\FormRequest;

class SelectMultiWinnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        \Illuminate\Support\Facades\Gate::authorize('selectWinner', $this->route('cycle'));
        return true;
    }

    public function rules(): array
    {
        return [
            'tender_ids' => ['required', 'array', 'min:1'],
            'tender_ids.*' => ['integer', 'distinct'],
            'accept_topup' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
