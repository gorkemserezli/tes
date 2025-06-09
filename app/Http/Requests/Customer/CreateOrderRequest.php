<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class CreateOrderRequest extends FormRequest
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
            'order_type' => ['nullable', 'in:warehouse,dropshipping,cargo,pickup'],
            'delivery_type' => ['nullable', 'in:standard,express,pickup'],
            'payment_method' => ['required', 'in:credit_card,bank_transfer,balance'],
            'use_different_shipping' => ['boolean'],
            'shipping_address' => ['required_if:use_different_shipping,true', 'string', 'max:500'],
            'shipping_contact_name' => ['nullable', 'string', 'max:255'],
            'shipping_contact_phone' => ['nullable', 'string', 'regex:/^[0-9]{10,20}$/'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Get custom error messages
     */
    public function messages(): array
    {
        return [
            'payment_method.required' => 'Ödeme yöntemi seçmelisiniz.',
            'payment_method.in' => 'Geçersiz ödeme yöntemi.',
            'shipping_address.required_if' => 'Farklı teslimat adresi kullanıyorsanız adres girmelisiniz.',
            'shipping_contact_phone.regex' => 'Geçerli bir telefon numarası giriniz.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Set defaults
        $this->merge([
            'use_different_shipping' => $this->use_different_shipping ?? false,
            'order_type' => $this->order_type ?? 'cargo',
            'delivery_type' => $this->delivery_type ?? 'standard',
        ]);
    }
}
