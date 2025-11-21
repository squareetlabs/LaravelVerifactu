<?php

declare(strict_types=1);

namespace Squareetlabs\VeriFactu\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Squareetlabs\VeriFactu\Enums\InvoiceType;

class Invoice extends Model
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
                'invoice_type' => $invoice->type instanceof \BackedEnum ? $invoice->type->value : (string)$invoice->type,
                'total_tax' => (string)$invoice->tax,
                'total_amount' => (string)$invoice->total,
                'previous_hash' => $invoice->previous_hash ?? '',
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
        'csv',
    ];

    protected $casts = [
        'date' => 'date',
        'type' => InvoiceType::class,
        'amount' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function breakdowns()
    {
        return $this->hasMany(Breakdown::class);
    }

    public function recipients()
    {
        return $this->hasMany(Recipient::class);
    }
} 