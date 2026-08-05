<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApiTokenRequest extends FormRequest
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
        $userId = $this->user()?->getKey();

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                // Token names are unique per user so revoking by name is unambiguous.
                Rule::unique('personal_access_tokens', 'name')
                    ->where('tokenable_id', is_int($userId) ? $userId : null),
            ],
        ];
    }
}
