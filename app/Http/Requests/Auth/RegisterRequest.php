<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
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
            // User information
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'phone' => ['required', 'string', 'regex:/^[0-9]{10,20}$/'],

            // Company information
            'company_name' => ['required', 'string', 'max:255'],
            'tax_number' => ['required', 'string', 'regex:/^[0-9]{10,11}$/', 'unique:companies'],
            'tax_office' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:500'],
            'city' => ['required', 'string', 'max:100'],
            'district' => ['required', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:10'],
        ];
    }

    /**
     * Get custom error messages
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Ad soyad zorunludur.',
            'email.required' => 'Email adresi zorunludur.',
            'email.email' => 'Geçerli bir email adresi giriniz.',
            'email.unique' => 'Bu email adresi zaten kayıtlı.',
            'password.required' => 'Şifre zorunludur.',
            'password.confirmed' => 'Şifre tekrarı eşleşmiyor.',
            'phone.required' => 'Telefon numarası zorunludur.',
            'phone.regex' => 'Geçerli bir telefon numarası giriniz (10-20 haneli).',

            'company_name.required' => 'Şirket adı zorunludur.',
            'tax_number.required' => 'Vergi numarası zorunludur.',
            'tax_number.regex' => 'Vergi numarası 10 veya 11 haneli olmalıdır.',
            'tax_number.unique' => 'Bu vergi numarası zaten kayıtlı.',
            'tax_office.required' => 'Vergi dairesi zorunludur.',
            'address.required' => 'Adres zorunludur.',
            'city.required' => 'Şehir zorunludur.',
            'district.required' => 'İlçe zorunludur.',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Vergi numarası doğrulama (basit kontrol)
            if ($this->tax_number && !$this->validateTaxNumber($this->tax_number)) {
                $validator->errors()->add('tax_number', 'Geçersiz vergi numarası.');
            }
        });
    }

    /**
     * Validate Turkish tax number
     */
    private function validateTaxNumber(string $taxNumber): bool
    {
        // 10 haneli TC kimlik no veya 11 haneli vergi no kontrolü
        if (strlen($taxNumber) == 10) {
            // Basit TC kimlik no algoritma kontrolü
            $digits = str_split($taxNumber);
            $sum = 0;

            for ($i = 0; $i < 9; $i++) {
                $sum += $digits[$i];
            }

            return ($sum % 10) == $digits[9];
        }

        return strlen($taxNumber) == 11;
    }
}
