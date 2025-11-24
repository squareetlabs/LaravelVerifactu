<?php

declare(strict_types=1);

namespace Squareetlabs\VeriFactu\Helpers;

class HashHelper
{
    private static array $invoiceRequiredFields = [
        'issuer_tax_id',
        'invoice_number',
        'issue_date',
        'invoice_type',
        'total_tax',
        'total_amount',
        'previous_hash',
        'generated_at',
    ];

    /**
     * Generates the hash for an invoice record according to AEAT specifications.
     * 
     * CRITICAL: Field names MUST match the official AEAT XML field names as per
     * "Detalle de las especificaciones técnicas para generación de la huella o hash
     * de los registros de facturación" v0.1.2 (27/08/2024), page 6.
     *
     * @param array $data Invoice record data with snake_case keys (for compatibility).
     * @return array ['hash' => string, 'inputString' => string]
     */
    public static function generateInvoiceHash(array $data): array
    {
        self::validateData(self::$invoiceRequiredFields, $data);                
        $inputString = self::field('IDEmisorFactura', $data['issuer_tax_id']);
        $inputString .= self::field('NumSerieFactura', $data['invoice_number']);
        $inputString .= self::field('FechaExpedicionFactura', $data['issue_date']);
        $inputString .= self::field('TipoFactura', $data['invoice_type']);
        $inputString .= self::field('CuotaTotal', $data['total_tax']);
        $inputString .= self::field('ImporteTotal', $data['total_amount']);
        $inputString .= self::field('Huella', $data['previous_hash']);
        $inputString .= self::field('FechaHoraHusoGenRegistro', $data['generated_at'], false);                
        $hash = strtoupper(hash('sha256', $inputString, false));        
        return ['hash' => $hash, 'inputString' => $inputString];
    }

    private static function validateData(array $requiredFields, array $data): void
    {
        $missing = array_diff($requiredFields, array_keys($data));
        if (!empty($missing)) {
            throw new \InvalidArgumentException('Missing required fields: ' . implode(', ', $missing));
        }
        $extra = array_diff(array_keys($data), $requiredFields);
        if (!empty($extra)) {
            throw new \InvalidArgumentException('Unexpected fields: ' . implode(', ', $extra));
        }
    }

    private static function field(string $name, string $value, bool $includeSeparator = true): string
    {
        $value = trim($value);
        return "{$name}={$value}" . ($includeSeparator ? '&' : '');
    }
} 