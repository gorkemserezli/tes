<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class VerifyTwoFactorRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'two_factor_token' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6', 'regex:/^[0-9]{6}$/'],
        ];
    }

    /**
     * Get custom error messages
     */
    public function messages(): array
    {
        return [
            'two_factor_token.required' => 'Doğrulama token\'ı gereklidir.',
            'code.required' => 'Doğrulama kodu zorunludur.',
            'code.size' => 'Doğrulama kodu 6 haneli olmalıdır.',
            'code.regex' => 'Doğrulama kodu sadece rakamlardan oluşmalıdır.',
        ];
    }
}
