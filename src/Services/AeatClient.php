<?php

declare(strict_types=1);

namespace Squareetlabs\VeriFactu\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Squareetlabs\VeriFactu\Contracts\VeriFactuInvoice;
use Squareetlabs\VeriFactu\Helpers\HashHelper;
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

    /** @var array{name: string, vat: string} */
    private array $issuer;

    /** @var array{name: string, vat: string}|null */
    private ?array $representative;

    /**
     * @param array{name: string, vat: string}|null $issuer Issuer (obligado emisión).
     *        Defaults to config('verifactu.issuer'). Pass it explicitly in
     *        multi-tenant applications where each company is a different issuer.
     * @param array{name: string, vat: string}|null $representative Social
     *        collaborator / representative (colaborador social AEAT). When set,
     *        a Representante block is added to the Cabecera and the TLS client
     *        certificate is expected to be the COLLABORATOR's, not the issuer's:
     *        the platform submits on behalf of its clients, who never have to
     *        provide their own certificate. Defaults to
     *        config('verifactu.representative') when it has a non-empty vat.
     */
    public function __construct(
        string $certPath,
        ?string $certPassword = null,
        bool $production = false,
        ?bool $verifactuMode = null,
        ?array $issuer = null,
        ?array $representative = null
    ) {
        $this->certPath = $certPath;
        $this->certPassword = $certPassword;
        $this->production = $production;
        $this->verifactuMode = $verifactuMode ?? config('verifactu.verifactu_mode', true);
        $this->issuer = $issuer ?? (array) config('verifactu.issuer', ['name' => '', 'vat' => '']);

        $configRepresentative = (array) config('verifactu.representative', []);
        $this->representative = $representative
            ?? (!empty($configRepresentative['vat']) ? $configRepresentative : null);
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
     * Build fingerprint/hash for invoice chaining.
     * Delegates to HashHelper: single source of truth for the AEAT hash spec.
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
        return HashHelper::generateInvoiceHash([
            'issuer_tax_id' => $issuerVat,
            'invoice_number' => $numSerie,
            'issue_date' => $fechaExp,
            'invoice_type' => $tipoFactura,
            'total_tax' => $cuotaTotal,
            'total_amount' => $importeTotal,
            'previous_hash' => $prevHash,
            'generated_at' => $ts,
        ])['hash'];
    }

    /**
     * Send invoice registration to AEAT with support for invoice chaining
     *
     * @param VeriFactuInvoice $invoice
     * @param array|null $previous Previous invoice data for chaining (hash, number, date)
     * @param array|null $record Precomputed registration record data:
     *        ['hash' => string, 'generated_at' => string (ISO 8601)].
     *        REQUIRED when the host application persists its own hash chain at
     *        issuance time: the submitted Huella and FechaHoraHusoGenRegistro
     *        must be exactly the ones stored in the chain, never recomputed at
     *        submission time (async submission would break chain integrity).
     * @return array
     */
    public function sendInvoice(VeriFactuInvoice $invoice, ?array $previous = null, ?array $record = null): array
    {
        // 1. Obtener datos del emisor (por instancia; multi-tenant friendly)
        $issuerName = $this->issuer['name'] ?? '';
        $issuerVat = $this->issuer['vat'] ?? '';

        // 2. Preparar datos comunes
        $ts = $record['generated_at'] ?? \Carbon\Carbon::now('UTC')->format('c');
        $numSerie = (string) $invoice->getInvoiceNumber();
        $fechaExp = $invoice->getIssueDate()->format('d-m-Y');
        $tipoFactura = $invoice->getInvoiceType();
        $cuotaTotal = sprintf('%.2f', (float) $invoice->getTaxAmount());
        $importeTotal = sprintf('%.2f', (float) $invoice->getTotalAmount());
        $prevHash = $previous['hash'] ?? $invoice->getPreviousHash() ?? '';

        // 3. Huella: la precalculada de la cadena del host si existe; si no, generarla
        $huella = $record['hash'] ?? $this->buildFingerprint(
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
        $cabecera = [
            'ObligadoEmision' => [
                'NombreRazon' => $issuerName,
                'NIF' => $issuerVat,
            ],
        ];

        // Colaborador social: la plataforma remite en nombre del obligado con
        // su propio certificado; AEAT exige identificar al representante.
        if ($this->representative !== null) {
            $cabecera['Representante'] = [
                'NombreRazon' => $this->representative['name'] ?? '',
                'NIF' => $this->representative['vat'] ?? '',
            ];
        }

        return $cabecera;
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

        if ($invoice->getCorrectionType()) {
            $registroAlta['TipoRectificativa'] = $invoice->getCorrectionType();

            // Add ImporteRectificacion block if required
            if ($invoice->getCorrectionType() === 'S' && $this->isCorrectiveInvoice($tipoFactura)) {
                $importeRectificacion = $this->buildImporteRectificacion($invoice);
                if ($importeRectificacion) {
                    $registroAlta['ImporteRectificacion'] = $importeRectificacion;
                }
            }
        }

        if ($invoice->getExternalReference()) {
            $registroAlta['RefExterna'] = $invoice->getExternalReference();
        }

        if ($destinatarios) {
            $registroAlta['Destinatarios'] = $destinatarios;
        }

        return $registroAlta;
    }

    /**
     * Build ImporteRectificacion block for substitution corrective invoices
     *
     * @param VeriFactuInvoice $invoice
     * @return array|null
     */
    private function buildImporteRectificacion(VeriFactuInvoice $invoice): ?array
    {
        $baseRectificada = $invoice->getCorrectedBaseAmount();
        $cuotaRectificada = $invoice->getCorrectedTaxAmount();

        // Both base and tax are required
        if ($baseRectificada === null || $cuotaRectificada === null) {
            return null;
        }

        $importe = [
            'BaseRectificada' => sprintf('%.2f', $baseRectificada),
            'CuotaRectificada' => sprintf('%.2f', $cuotaRectificada),
        ];

        // Add optional surcharge if present
        $cuotaRecargo = $invoice->getCorrectedSurchargeAmount();
        if ($cuotaRecargo !== null) {
            $importe['CuotaRecargoRectificado'] = sprintf('%.2f', $cuotaRecargo);
        }

        return $importe;
    }

    /**
     * Check if invoice type is corrective (R1-R5)
     *
     * @param string $tipoFactura
     * @return bool
     */
    private function isCorrectiveInvoice(string $tipoFactura): bool
    {
        return in_array($tipoFactura, ['R1', 'R2', 'R3', 'R4', 'R5']);
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

