<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class CreateUserRequest extends FormRequest
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
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', Password::defaults()],
            'phone' => ['nullable', 'string', 'regex:/^[0-9]{10,20}$/'],
            'is_active' => ['boolean'],
            'is_admin' => ['boolean'],
            'group_ids' => ['nullable', 'array'],
            'group_ids.*' => ['exists:customer_groups,id'],
        ];

        // Company validation for customers
        if (!$this->is_admin) {
            $rules['company'] = ['required', 'array'];
            $rules['company.company_name'] = ['required', 'string', 'max:255'];
            $rules['company.tax_number'] = ['required', 'string', 'regex:/^[0-9]{10,11}$/', 'unique:companies,tax_number'];
            $rules['company.tax_office'] = ['required', 'string', 'max:255'];
            $rules['company.address'] = ['required', 'string', 'max:500'];
            $rules['company.city'] = ['required', 'string', 'max:100'];
            $rules['company.district'] = ['required', 'string', 'max:100'];
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
            'password.required' => 'Şifre zorunludur.',
            'phone.regex' => 'Geçerli bir telefon numarası giriniz.',
            'company.required' => 'Müşteri için şirket bilgileri zorunludur.',
            'company.company_name.required' => 'Şirket adı zorunludur.',
            'company.tax_number.required' => 'Vergi numarası zorunludur.',
            'company.tax_number.unique' => 'Bu vergi numarası zaten kayıtlı.',
        ];
    }
}
