<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Rule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule as ValidationRule;
use Illuminate\Validation\Validator;

class StoreRuleRequest extends FormRequest
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
            'position_id' => [
                'nullable',
                'exists:positions,id',
                ValidationRule::unique('rules', 'position_id')->ignore($this->ignoredRuleId()),
            ],
            'action' => ['nullable', 'in:order,notify'],
            'take_profit_pct' => ['nullable', 'numeric', 'min:0.01'],
            'stop_loss_pct' => ['nullable', 'numeric', 'min:0.01'],
            'stop_loss_type' => ['nullable', 'in:entry,trailing'],
            'sell_pct' => ['nullable', 'numeric', 'min:0.01', 'max:100'],
            'buy_below_pct' => ['nullable', 'numeric', 'min:0.01'],
            // Both are required together: a buy threshold with no amount cannot be sized, and
            // an uncapped buy rule keeps firing all the way down.
            'buy_amount' => ['nullable', 'required_with:buy_below_pct', 'numeric', 'min:0.01'],
            'max_position_value' => ['nullable', 'required_with:buy_below_pct', 'numeric', 'min:0.01'],
            'is_active' => ['boolean'],
            'cooldown_minutes' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * A unique index cannot cover the global rule: SQLite treats each NULL position_id as
     * distinct, so a second global row would pass the constraint and leave rule selection
     * ambiguous.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->integer('position_id') !== 0) {
                    return;
                }

                $duplicate = Rule::whereNull('position_id')
                    ->when($this->ignoredRuleId(), fn ($query, int $id) => $query->whereKeyNot($id))
                    ->exists();

                if ($duplicate) {
                    $validator->errors()->add(
                        'position_id',
                        'A global default rule already exists. Edit that rule instead of adding a second one.'
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'position_id.unique' => 'That position already has a rule. Edit the existing rule instead.',
            'buy_amount.required_with' => 'A buy rule needs an amount to spend each time it fires.',
            'max_position_value.required_with' => 'A buy rule needs a cap on total position value, so it cannot keep buying all the way down.',
        ];
    }

    protected function ignoredRuleId(): ?int
    {
        return null;
    }
}
