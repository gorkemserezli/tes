<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->user()->isAdmin();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $userId = $this->route('id');

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($userId)],
            'password' => ['nullable', Password::defaults()],
            'phone' => ['nullable', 'string', 'regex:/^[0-9]{10,20}$/'],
            'is_active' => ['boolean'],
            'group_ids' => ['nullable', 'array'],
            'group_ids.*' => ['exists:customer_groups,id'],
        ];

        // Company validation if provided
        if ($this->has('company')) {
            $rules['company'] = ['array'];
            $rules['company.company_name'] = ['sometimes', 'string', 'max:255'];
            $rules['company.tax_office'] = ['sometimes', 'string', 'max:255'];
            $rules['company.address'] = ['sometimes', 'string', 'max:500'];
            $rules['company.city'] = ['sometimes', 'string', 'max:100'];
            $rules['company.district'] = ['sometimes', 'string', 'max:100'];
            $rules['company.postal_code'] = ['nullable', 'string', 'max:10'];
            $rules['company.is_approved'] = ['boolean'];
            $rules['company.credit_limit'] = ['nullable', 'numeric', 'min:0'];
        }

        return $rules;
    }

    /**
     * Get custom error messages
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Ad soyad zorunludur.',
            'email.required' => 'Email adresi zorunludur.',
            'email.unique' => 'Bu email adresi zaten kullanımda.',
            'phone.regex' => 'Geçerli bir telefon numarası giriniz.',
        ];
    }
}
