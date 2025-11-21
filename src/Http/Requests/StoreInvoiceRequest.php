<?php

declare(strict_types=1);

namespace Squareetlabs\VeriFactu\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Squareetlabs\VeriFactu\Enums\InvoiceType;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'number' => ['required', 'string', 'max:60', 'unique:invoices,number'],
            'date' => ['required', 'date'],
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_tax_id' => ['required', 'string', 'max:20'],
            'customer_country' => ['nullable', 'string', 'size:2'],
            'issuer_name' => ['required', 'string', 'max:120'],
            'issuer_tax_id' => ['required', 'string', 'max:20'],
            'issuer_country' => ['nullable', 'string', 'size:2'],
            'numero_instalacion' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:0'],
            'tax' => ['required', 'numeric', 'min:0'],
            'total' => ['required', 'numeric', 'min:0'],
            'type' => ['required', Rule::in(array_column(InvoiceType::cases(), 'value'))],
            'external_reference' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', 'string', 'max:30'],
            'issued_at' => ['nullable', 'date'],
            'cancelled_at' => ['nullable', 'date'],
        ];
    }
} 