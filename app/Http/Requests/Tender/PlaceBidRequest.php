<?php

namespace App\Http\Requests\Tender;

use Illuminate\Foundation\Http\FormRequest;

class PlaceBidRequest extends FormRequest
{
    public function authorize(): bool
    {
        \Illuminate\Support\Facades\Gate::authorize('placeBid', $this->route('cycle'));
        return true;
    }

    public function rules(): array
    {
        $cycle = $this->route('cycle');
        $maxBid = $cycle ? (float) $cycle->total_pool : 999999999;
        return [
            'bid_amount' => ['required', 'numeric', 'min:1', 'max:' . $maxBid],
        ];
    }
}
