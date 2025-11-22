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
        'numero_instalacion',
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
        // Estado AEAT
        'aeat_estado_registro',
        'aeat_codigo_error',
        'aeat_descripcion_error',
        'has_aeat_warnings',
        // Encadenamiento
        'previous_invoice_number',
        'previous_invoice_date',
        'previous_invoice_hash',
        'is_first_invoice',
        // Facturas rectificativas
        'rectificative_type',
        'rectified_invoices',
        'rectification_amount',
        // Campos opcionales AEAT
        'operation_date',
        'is_subsanacion',
        'rejected_invoice_number',
        'rejection_date',
    ];

    protected $casts = [
        'date' => 'date',
        'type' => InvoiceType::class,
        'amount' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
        // Estado AEAT
        'has_aeat_warnings' => 'boolean',
        // Encadenamiento
        'previous_invoice_date' => 'date',
        'is_first_invoice' => 'boolean',
        // Facturas rectificativas
        'rectified_invoices' => 'array',
        'rectification_amount' => 'array',
        // Campos opcionales AEAT
        'operation_date' => 'date',
        'is_subsanacion' => 'boolean',
        'rejection_date' => 'date',
    ];

    public function breakdowns()
    {
        return $this->hasMany(Breakdown::class);
    }

    public function recipients()
    {
        return $this->hasMany(Recipient::class);
    }

    /**
     * Scope para filtrar facturas con warnings de AEAT
     */
    public function scopeWithAeatWarnings($query)
    {
        return $query->where('has_aeat_warnings', true);
    }

    /**
     * Scope para filtrar facturas aceptadas sin problemas
     */
    public function scopeAcceptedWithoutWarnings($query)
    {
        return $query->where('status', 'submitted')
                     ->where('has_aeat_warnings', false);
    }

    /**
     * Scope para filtrar por estado de registro AEAT
     */
    public function scopeByAeatEstado($query, string $estado)
    {
        return $query->where('aeat_estado_registro', $estado);
    }

    public function isAceptadaPorAeat(): bool
    {
        return $this->status === 'submitted' && !empty($this->csv);
    }

    public function tieneWarningsAeat(): bool
    {
        return $this->has_aeat_warnings && 
               $this->aeat_estado_registro === 'AceptadoConErrores';
    }

    public function getMensajeAeat(): ?string
    {
        if (!$this->aeat_descripcion_error) {
            return null;
        }

        $mensaje = $this->aeat_descripcion_error;
        
        if ($this->aeat_codigo_error) {
            $mensaje = "[{$this->aeat_codigo_error}] {$mensaje}";
        }

        return $mensaje;
    }
} 