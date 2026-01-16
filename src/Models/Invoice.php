<?php

declare(strict_types=1);

namespace Squareetlabs\VeriFactu\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Squareetlabs\VeriFactu\Enums\InvoiceType;
use Squareetlabs\VeriFactu\Contracts\VeriFactuInvoice;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class Invoice extends Model implements VeriFactuInvoice
{
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory()
    {
        return \Database\Factories\Squareetlabs\VeriFactu\Models\InvoiceFactory::new();
    }

    protected static function booted()
    {
        static::saving(function ($invoice) {
            // Preparar datos para el hash
            $hashData = [
                'issuer_tax_id' => $invoice->issuer_tax_id,
                'invoice_number' => $invoice->number,
                'issue_date' => $invoice->date instanceof \Illuminate\Support\Carbon ? $invoice->date->format('Y-m-d') : $invoice->date,
                'invoice_type' => $invoice->type instanceof \BackedEnum ? $invoice->type->value : (string) $invoice->type,
                'total_tax' => (string) $invoice->tax,
                'total_amount' => (string) $invoice->total,
                'previous_hash' => $invoice->previous_hash ?? '', // Si implementas encadenamiento
                'generated_at' => now()->format('c'),
            ];
            $hashResult = \Squareetlabs\VeriFactu\Helpers\HashHelper::generateInvoiceHash($hashData);
            $invoice->hash = $hashResult['hash'];
        });
    }

    protected $table = 'invoices';

    protected $fillable = [
        'uuid',
        'number',
        'date',
        'customer_name',
        'customer_tax_id',
        'customer_country',
        'issuer_name',
        'issuer_tax_id',
        'issuer_country',
        'amount',
        'tax',
        'total',
        'type',
        'external_reference',
        'description',
        'status',
        'issued_at',
        'cancelled_at',
        'hash',
        'operation_date',
        'tax_period',
        'correction_type',
        // New fields from PR #8
        'csv',
        'previous_invoice_number',
        'previous_invoice_date',
        'previous_invoice_hash',
        'is_first_invoice',
        'rectificative_type',
        'rectified_invoices',
        'rectification_amount',
        'is_subsanacion',
        'rejected_invoice_number',
        'rejection_date',
        'numero_instalacion',
        'aeat_estado_registro',
        'aeat_codigo_error',
        'aeat_descripcion_error',
        'has_aeat_warnings',
    ];

    protected $casts = [
        'date' => 'date',
        'operation_date' => 'date',
        'type' => InvoiceType::class,
        'amount' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
        // New casts from PR #8
        'previous_invoice_date' => 'date',
        'rejection_date' => 'date',
        'is_first_invoice' => 'boolean',
        'is_subsanacion' => 'boolean',
        'has_aeat_warnings' => 'boolean',
        'rectified_invoices' => 'array',
        'rectification_amount' => 'array',
    ];

    public function breakdowns()
    {
        return $this->hasMany(Breakdown::class);
    }

    public function recipients()
    {
        return $this->hasMany(Recipient::class);
    }

    // VeriFactuInvoice Contract Implementation

    public function getInvoiceNumber(): string
    {
        return $this->number;
    }

    public function getIssueDate(): Carbon
    {
        return $this->date;
    }

    public function getInvoiceType(): string
    {
        return $this->type->value ?? (string) $this->type;
    }

    public function getTotalAmount(): float
    {
        return (float) $this->total;
    }

    public function getTaxAmount(): float
    {
        return (float) $this->tax;
    }

    public function getCustomerName(): string
    {
        return $this->customer_name;
    }

    public function getCustomerTaxId(): ?string
    {
        return $this->customer_tax_id;
    }

    public function getBreakdowns(): Collection
    {
        return $this->breakdowns;
    }

    public function getRecipients(): Collection
    {
        return $this->recipients;
    }

    public function getPreviousHash(): ?string
    {
        return $this->previous_hash ?? null;
    }

    public function getOperationDescription(): string
    {
        return $this->description ?? 'Invoice issued';
    }

    public function getOperationDate(): ?Carbon
    {
        return $this->operation_date;
    }

    public function getTaxPeriod(): ?string
    {
        return $this->tax_period;
    }

    public function getCorrectionType(): ?string
    {
        if (!empty($this->correction_type)) {
            return $this->correction_type;
        }

        return $this->rectificative_type ?: null;
    }

    public function getExternalReference(): ?string
    {
        return $this->external_reference;
    }
}
