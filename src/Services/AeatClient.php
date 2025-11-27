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

    public function __construct(string $certPath, ?string $certPassword = null, bool $production = false)
    {
        $this->certPath = $certPath;
        $this->certPassword = $certPassword;
        $this->production = $production;
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
                'NombreSistemaInformatico' => env('APP_NAME', 'LaravelVerifactu'),
                'IdSistemaInformatico' => config('verifactu.system_id', '01'),
                'Version' => '1.0',
                'NumeroInstalacion' => '001',
                'TipoUsoPosibleSoloVerifactu' => 'S',
                'TipoUsoPosibleMultiOT' => 'N',
                'IndicadorMultiplesOT' => 'N',
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

        if ($invoice->getCorrectionType()) {
            $registroAlta['TipoRectificativa'] = $invoice->getCorrectionType();
        }

        if ($invoice->getExternalReference()) {
            $registroAlta['RefExterna'] = $invoice->getExternalReference();
        }

        if ($destinatarios) {
            $registroAlta['Destinatarios'] = $destinatarios;
        }

        return $registroAlta;
    }

    protected function getSoapClient(): \SoapClient
    {
        $wsdl = $this->production
            ? 'https://www1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP?wsdl'
            : 'https://prewww2.aeat.es/static_files/common/internet/dep/aplicaciones/es/aeat/tikeV1.0/cont/ws/SistemaFacturacion.wsdl';

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
        $location = $this->production
            ? 'https://www1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP'
            : 'https://prewww1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP';

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

