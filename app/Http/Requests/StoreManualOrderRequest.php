<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ManualOrder;
use App\Models\Position;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreManualOrderRequest extends FormRequest
{
    private const DECIMAL_6 = '/^\d{1,12}(\.\d{1,6})?$/';

    private const DECIMAL_4 = '/^\d{1,12}(\.\d{1,4})?$/';

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'symbol' => strtoupper(trim($this->string('symbol')->toString())),
            'currency' => strtoupper(trim($this->string('currency')->toString())) ?: 'USD',
            'fees' => $this->filled('fees') ? $this->input('fees') : '0',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'position_id' => [
                Rule::requiredIf(fn (): bool => $this->input('side') === 'sell'),
                'nullable',
                'integer',
                'exists:positions,id',
            ],
            'symbol' => ['required_without:position_id', 'nullable', 'regex:/^[A-Z0-9.\-]{1,20}$/'],
            'side' => ['required', Rule::in(ManualOrder::SIDES)],
            'quantity' => ['required', 'string', 'regex:'.self::DECIMAL_6, 'gt:0'],
            'price' => [
                Rule::requiredIf(fn (): bool => $this->input('status') === ManualOrder::STATUS_FILLED),
                'nullable',
                'string',
                'regex:'.self::DECIMAL_4,
                'gt:0',
            ],
            'fees' => ['required', 'string', 'regex:'.self::DECIMAL_4],
            'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'order_date' => [
                'required',
                'date_format:Y-m-d',
                Rule::when(
                    $this->input('status') === ManualOrder::STATUS_FILLED,
                    ['before_or_equal:today'],
                ),
            ],
            'note' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', Rule::in(ManualOrder::STATUSES)],
        ];
    }

    /**
     * Sells are checked against what is held minus sells already planned. A filled or
     * cancelled sell is history: the holding is broker-imported and may already reflect it.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()
                    || $this->input('side') !== 'sell'
                    || $this->input('status') !== ManualOrder::STATUS_PLANNED) {
                    return;
                }

                $position = Position::find($this->integer('position_id'));

                if ($position === null) {
                    return;
                }

                $planned = $this->plannedSells($position);
                $available = (float) $position->quantity - $planned;

                if ($this->float('quantity') > $available + 0.000001) {
                    $validator->errors()->add('quantity', sprintf(
                        'You hold %s of %s and %s is already planned to sell, so at most %s is left to sell.',
                        $this->plain((float) $position->quantity),
                        $position->symbol,
                        $this->plain($planned),
                        $this->plain(max(0.0, $available)),
                    ));
                }
            },
        ];
    }

    protected function ignoredOrderId(): ?int
    {
        return null;
    }

    private function plannedSells(Position $position): float
    {
        return (float) ManualOrder::where('position_id', $position->id)
            ->where('side', 'sell')
            ->where('status', ManualOrder::STATUS_PLANNED)
            ->when($this->ignoredOrderId(), fn ($query, int $id) => $query->whereKeyNot($id))
            ->sum('quantity');
    }

    private function plain(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.') ?: '0';
    }
}
