<?php

declare(strict_types=1);

namespace Squareetlabs\VeriFactu\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Squareetlabs\VeriFactu\Contracts\VeriFactuInvoice;
use Squareetlabs\VeriFactu\Models\Invoice;
use Illuminate\Support\Facades\Log;

class AeatClient
{
    private string $baseUri;
    private string $certPath;
    private ?string $certPassword;
    private Client $client;
    private bool $production;
    private bool $verifactuMode;

    public function __construct(string $certPath, ?string $certPassword = null, bool $production = false, ?bool $verifactuMode = null)
    {
        $this->certPath = $certPath;
        $this->certPassword = $certPassword;
        $this->production = $production;
        $this->verifactuMode = $verifactuMode ?? config('verifactu.verifactu_mode', true);
        $this->baseUri = $production
            ? 'https://www1.aeat.es'
            : 'https://prewww1.aeat.es';
        $this->client = new Client([
            'cert' => ($certPassword === null) ? $certPath : [$certPath, $certPassword],
            'base_uri' => $this->baseUri,
            'headers' => [
                'User-Agent' => 'LaravelVerifactu/1.0',
            ],
        ]);
    }



    /**
     * Build fingerprint/hash for invoice chaining
     *
     * @param string $issuerVat
     * @param string $numSerie
     * @param string $fechaExp
     * @param string $tipoFactura
     * @param string $cuotaTotal
     * @param string $importeTotal
     * @param string $ts
     * @param string $prevHash
     * @return string
     */
    private function buildFingerprint(
        string $issuerVat,
        string $numSerie,
        string $fechaExp,
        string $tipoFactura,
        string $cuotaTotal,
        string $importeTotal,
        string $ts,
        string $prevHash = ''
    ): string {
        $raw = 'IDEmisorFactura=' . $issuerVat
            . '&NumSerieFactura=' . $numSerie
            . '&FechaExpedicionFactura=' . $fechaExp
            . '&TipoFactura=' . $tipoFactura
            . '&CuotaTotal=' . $cuotaTotal
            . '&ImporteTotal=' . $importeTotal
            . '&Huella=' . $prevHash
            . '&FechaHoraHusoGenRegistro=' . $ts;
        return strtoupper(hash('sha256', $raw));
    }

    /**
     * Send invoice registration to AEAT with support for invoice chaining
     *
     * @param Invoice $invoice
     * @param array|null $previous Previous invoice data for chaining (hash, number, date)
     * @return array
     */
    /**
     * Send invoice registration to AEAT with support for invoice chaining
     *
     * @param VeriFactuInvoice $invoice
     * @param array|null $previous Previous invoice data for chaining (hash, number, date)
     * @return array
     */
    /**
     * Send invoice registration to AEAT with support for invoice chaining
     *
     * @param VeriFactuInvoice $invoice
     * @param array|null $previous Previous invoice data for chaining (hash, number, date)
     * @return array
     */
    public function sendInvoice(VeriFactuInvoice $invoice, ?array $previous = null): array
    {
        // 1. Obtener datos del emisor
        $issuer = config('verifactu.issuer');
        $issuerName = $issuer['name'] ?? '';
        $issuerVat = $issuer['vat'] ?? '';

        // 2. Preparar datos comunes
        $ts = \Carbon\Carbon::now('UTC')->format('c');
        $numSerie = (string) $invoice->getInvoiceNumber();
        $fechaExp = $invoice->getIssueDate()->format('d-m-Y');
        $tipoFactura = $invoice->getInvoiceType();
        $cuotaTotal = sprintf('%.2f', (float) $invoice->getTaxAmount());
        $importeTotal = sprintf('%.2f', (float) $invoice->getTotalAmount());
        $prevHash = $previous['hash'] ?? $invoice->getPreviousHash() ?? '';

        // 3. Generar huella
        $huella = $this->buildFingerprint(
            $issuerVat,
            $numSerie,
            $fechaExp,
            $tipoFactura,
            $cuotaTotal,
            $importeTotal,
            $ts,
            $prevHash
        );

        // 4. Construir partes del mensaje
        $cabecera = $this->buildHeader($issuerName, $issuerVat);
        $detalle = $this->buildBreakdowns($invoice);
        $encadenamiento = $this->buildChaining($previous, $issuerVat);
        $destinatarios = $this->buildRecipients($invoice);

        // 5. Construir RegistroAlta
        $registroAlta = $this->buildRegistration(
            $invoice,
            $issuerName,
            $issuerVat,
            $numSerie,
            $fechaExp,
            $tipoFactura,
            $cuotaTotal,
            $importeTotal,
            $ts,
            $huella,
            $detalle,
            $encadenamiento,
            $destinatarios
        );

        $body = [
            'Cabecera' => $cabecera,
            'RegistroFactura' => [
                ['RegistroAlta' => $registroAlta]
            ],
        ];

        // 6. Enviar
        return $this->performSoapCall($body, $huella, $numSerie, $fechaExp, $ts, $previous);
    }

    private function buildHeader(string $issuerName, string $issuerVat): array
    {
        return [
            'ObligadoEmision' => [
                'NombreRazon' => $issuerName,
                'NIF' => $issuerVat,
            ],
        ];
    }

    private function buildBreakdowns(VeriFactuInvoice $invoice): array
    {
        $breakdowns = $invoice->getBreakdowns();
        $detalle = [];

        foreach ($breakdowns as $breakdown) {
            $detalle[] = [
                'ClaveRegimen' => $breakdown->getRegimeType(),
                'CalificacionOperacion' => $breakdown->getOperationType(),
                'TipoImpositivo' => (float) $breakdown->getTaxRate(),
                'BaseImponibleOimporteNoSujeto' => sprintf('%.2f', (float) $breakdown->getBaseAmount()),
                'CuotaRepercutida' => sprintf('%.2f', (float) $breakdown->getTaxAmount()),
            ];
        }

        if (count($detalle) === 0) {
            $base = sprintf('%.2f', (float) $invoice->getTotalAmount() - $invoice->getTaxAmount());
            $detalle[] = [
                'ClaveRegimen' => '01',
                'CalificacionOperacion' => 'S1',
                'TipoImpositivo' => 0.0,
                'BaseImponibleOimporteNoSujeto' => $base,
                'CuotaRepercutida' => sprintf('%.2f', 0.0),
            ];
        }

        return $detalle;
    }

    private function buildChaining(?array $previous, string $issuerVat): array
    {
        if ($previous) {
            return [
                'RegistroAnterior' => [
                    'IDEmisorFactura' => $issuerVat,
                    'NumSerieFactura' => $previous['number'],
                    'FechaExpedicionFactura' => $previous['date'],
                    'Huella' => $previous['hash'],
                ],
            ];
        }
        return ['PrimerRegistro' => 'S'];
    }

    private function buildRecipients(VeriFactuInvoice $invoice): ?array
    {
        $recipients = $invoice->getRecipients();
        if ($recipients->count() > 0) {
            $destinatarios = [];
            foreach ($recipients as $recipient) {
                $r = ['NombreRazon' => $recipient->getName()];
                $taxId = $recipient->getTaxId();
                if (!empty($taxId)) {
                    $r['NIF'] = $taxId;
                }
                $destinatarios[] = $r;
            }
            return ['IDDestinatario' => $destinatarios];
        }
        return null;
    }

    private function buildRegistration(
        VeriFactuInvoice $invoice,
        string $issuerName,
        string $issuerVat,
        string $numSerie,
        string $fechaExp,
        string $tipoFactura,
        string $cuotaTotal,
        string $importeTotal,
        string $ts,
        string $huella,
        array $detalle,
        array $encadenamiento,
        ?array $destinatarios
    ): array {
        $registroAlta = [
            'IDVersion' => '1.0',
            'IDFactura' => [
                'IDEmisorFactura' => $issuerVat,
                'NumSerieFactura' => $numSerie,
                'FechaExpedicionFactura' => $fechaExp,
            ],
            'NombreRazonEmisor' => $issuerName,
            'TipoFactura' => $tipoFactura,
            'DescripcionOperacion' => $invoice->getOperationDescription(),
            'Desglose' => ['DetalleDesglose' => $detalle],
            'CuotaTotal' => $cuotaTotal,
            'ImporteTotal' => $importeTotal,
            'Encadenamiento' => $encadenamiento,
            'SistemaInformatico' => [
                'NombreRazon' => $issuerName,
                'NIF' => $issuerVat,
                'NombreSistemaInformatico' => config('verifactu.sistema_informatico.name', 'LaravelVerifactu'),
                'IdSistemaInformatico' => config('verifactu.sistema_informatico.id', 'LV'),
                'Version' => config('verifactu.sistema_informatico.version', '1.0'),
                'NumeroInstalacion' => config('verifactu.sistema_informatico.installation_number', '001'),
                'TipoUsoPosibleSoloVerifactu' => config('verifactu.sistema_informatico.only_verifactu_capable', 'S'),
                'TipoUsoPosibleMultiOT' => config('verifactu.sistema_informatico.multi_obligated_entities_capable', 'N'),
                'IndicadorMultiplesOT' => config('verifactu.sistema_informatico.has_multiple_obligated_entities', 'N'),
            ],
            'FechaHoraHusoGenRegistro' => $ts,
            'TipoHuella' => '01',
            'Huella' => $huella,
        ];

        // Campos opcionales nuevos
        if ($invoice->getOperationDate()) {
            $registroAlta['FechaOperacion'] = $invoice->getOperationDate()->format('d-m-Y');
        }

        if ($invoice->getTaxPeriod()) {
            $registroAlta['PeriodoImpositivo'] = [
                'Ejercicio' => $invoice->getIssueDate()->format('Y'),
                'Periodo' => $invoice->getTaxPeriod(),
            ];
        }

        $rectificativeType = $invoice->getCorrectionType();
        if ($rectificativeType) {
            $rectificativeType = strtoupper($rectificativeType);
            $registroAlta['TipoRectificativa'] = $rectificativeType;
        }

        $rectifiedInvoices = $this->resolveRectifiedInvoices($invoice, $issuerVat);
        if ($rectifiedInvoices) {
            $isSubstitute = strtoupper($tipoFactura) === 'F3';
            $block = $isSubstitute ? 'FacturasSustituidas' : 'FacturasRectificadas';
            $itemKey = $isSubstitute ? 'IDFacturaSustituida' : 'IDFacturaRectificada';
            $registroAlta[$block] = [$itemKey => $rectifiedInvoices];
        }

        $rectificationAmount = $this->resolveRectificationAmount($invoice);
        if ($rectificationAmount) {
            $registroAlta['ImporteRectificacion'] = $rectificationAmount;
        }

        if ($invoice->getExternalReference()) {
            $registroAlta['RefExterna'] = $invoice->getExternalReference();
        }

        if ($destinatarios) {
            $registroAlta['Destinatarios'] = $destinatarios;
        }

        return $registroAlta;
    }

    private function resolveRectifiedInvoices(VeriFactuInvoice $invoice, string $issuerVat): ?array
    {
        $rectified = null;

        if (method_exists($invoice, 'getRectifiedInvoices')) {
            $rectified = $invoice->getRectifiedInvoices();
        }

        if ($rectified === null && $invoice instanceof \Illuminate\Database\Eloquent\Model) {
            $rectified = $invoice->getAttribute('rectified_invoices');
        }

        if (is_string($rectified)) {
            $decoded = json_decode($rectified, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $rectified = $decoded;
            }
        }

        if (!is_array($rectified) || $rectified === []) {
            return null;
        }

        if (array_key_exists('number', $rectified) && array_key_exists('date', $rectified)) {
            $rectified = [$rectified];
        }

        $items = [];
        foreach ($rectified as $item) {
            $normalized = $this->normalizeRectifiedInvoice($item, $issuerVat);
            if ($normalized) {
                $items[] = $normalized;
            }
        }

        return $items === [] ? null : $items;
    }

    private function normalizeRectifiedInvoice(mixed $item, string $issuerVat): ?array
    {
        if ($item instanceof \Illuminate\Database\Eloquent\Model) {
            $item = $item->getAttributes();
        }

        if (!is_array($item)) {
            return null;
        }

        $number = $item['number'] ?? null;
        $date = $item['date'] ?? null;
        $issuer = $item['issuer_tax_id'] ?? $item['issuer_vat'] ?? $issuerVat;

        if (empty($number) || empty($date)) {
            return null;
        }

        $date = $this->normalizeIssueDate($date);

        if ($date === null) {
            return null;
        }

        return [
            'IDEmisorFactura' => $issuer,
            'NumSerieFactura' => (string) $number,
            'FechaExpedicionFactura' => $date,
        ];
    }

    private function normalizeIssueDate(mixed $date): ?string
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->format('d-m-Y');
        }

        if (!is_string($date)) {
            return null;
        }

        $date = trim($date);
        if ($date === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            $parsed = \Carbon\Carbon::createFromFormat('Y-m-d', $date);
            return $parsed->format('d-m-Y');
        }

        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $date) === 1) {
            $parsed = \Carbon\Carbon::createFromFormat('d/m/Y', $date);
            return $parsed->format('d-m-Y');
        }

        if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $date) === 1) {
            return $date;
        }

        try {
            return \Carbon\Carbon::parse($date)->format('d-m-Y');
        } catch (\Exception $e) {
            return null;
        }
    }

    private function resolveRectificationAmount(VeriFactuInvoice $invoice): ?array
    {
        $amount = null;

        if (method_exists($invoice, 'getRectificationAmount')) {
            $amount = $invoice->getRectificationAmount();
        }

        if ($amount === null && $invoice instanceof \Illuminate\Database\Eloquent\Model) {
            $amount = $invoice->getAttribute('rectification_amount');
        }

        if (is_string($amount)) {
            $decoded = json_decode($amount, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $amount = $decoded;
            }
        }

        if (!is_array($amount) || $amount === []) {
            return null;
        }

        $base = $amount['base'] ?? null;
        $tax = $amount['tax'] ?? null;
        $surcharge = $amount['surcharge'] ?? $amount['recargo'] ?? null;

        if ($base === null || $tax === null) {
            return null;
        }

        $rectification = [
            'BaseRectificada' => sprintf('%.2f', (float) $base),
            'CuotaRectificada' => sprintf('%.2f', (float) $tax),
        ];

        if ($surcharge !== null) {
            $rectification['CuotaRecargoRectificado'] = sprintf('%.2f', (float) $surcharge);
        }

        return $rectification;
    }

    protected function getSoapClient(): \SoapClient
    {
        if ($this->production) {
            $wsdl = $this->verifactuMode
                ? 'https://www1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP?wsdl'
                : 'https://www1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/RequerimientoSOAP?wsdl';
        } else {
            $wsdl = 'https://prewww2.aeat.es/static_files/common/internet/dep/aplicaciones/es/aeat/tikeV1.0/cont/ws/SistemaFacturacion.wsdl';
        }

        $options = [
            'local_cert' => $this->certPath,
            'passphrase' => $this->certPassword,
            'trace' => true,
            'exceptions' => true,
            'cache_wsdl' => 0,
            'soap_version' => SOAP_1_1,
            'connection_timeout' => 30,
            'stream_context' => stream_context_create([
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                    'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
                ],
                'http' => [
                    'user_agent' => 'LaravelVerifactu/1.0',
                ],
            ]),
        ];

        return new \SoapClient($wsdl, $options);
    }

    private function performSoapCall(array $body, string $huella, string $numSerie, string $fechaExp, string $ts, ?array $previous): array
    {
        if ($this->production) {
            $location = $this->verifactuMode
                ? 'https://www1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP'
                : 'https://www1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/RequerimientoSOAP';
        } else {
            $location = $this->verifactuMode
                ? 'https://prewww1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP'
                : 'https://prewww1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/RequerimientoSOAP';
        }

        try {
            $client = $this->getSoapClient();
            $client->__setLocation($location);
            $response = $client->__soapCall('RegFactuSistemaFacturacion', [$body]);
            return [
                'status' => 'success',
                'request' => $client->__getLastRequest(),
                'response' => $client->__getLastResponse(),
                'aeat_response' => $response,
                'hash' => $huella,
                'number' => $numSerie,
                'date' => $fechaExp,
                'timestamp' => $ts,
                'first' => $previous ? false : true,
            ];
        } catch (\SoapFault $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'request' => isset($client) ? $client->__getLastRequest() : null,
                'response' => isset($client) ? $client->__getLastResponse() : null,
            ];
        }
    }
}
