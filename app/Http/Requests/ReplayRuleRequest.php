<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReplayRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'position_id' => ['required', 'exists:positions,id'],
            'take_profit_pct' => ['nullable', 'numeric', 'min:0.01'],
            'stop_loss_pct' => ['nullable', 'numeric', 'min:0.01'],
            'stop_loss_type' => ['nullable', 'in:entry,trailing'],
            'cooldown_minutes' => ['required', 'integer', 'min:1'],
        ];
    }
}
