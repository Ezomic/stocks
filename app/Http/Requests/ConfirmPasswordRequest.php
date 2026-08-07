<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmPasswordRequest extends FormRequest
{
    /**
     * Kept in its own bag so a failed password on one two-factor action does not light up
     * every other form on the settings page.
     */
    protected $errorBag = 'twoFactor';

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
            'password' => ['required', 'string', 'current_password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.current_password' => 'That is not your current password.',
            'password.required' => 'Enter your password to confirm this change.',
        ];
    }
}
