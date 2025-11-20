<?php

declare(strict_types=1);

namespace Squareetlabs\VeriFactu\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Squareetlabs\VeriFactu\Models\Invoice;
use Illuminate\Support\Facades\Log;
use OrbilaiConnect\Services\Internal\Squareetlabs_LaravelVerifactu\Contracts\XadesSignatureInterface;

class AeatClient
{
    private string $baseUri;
    private string $certPath;
    private ?string $certPassword;
    private Client $client;
    private bool $production;
    private ?XadesSignatureInterface $xadesService;

    public function __construct(
        string $certPath,
        ?string $certPassword = null,
        bool $production = false,
        ?XadesSignatureInterface $xadesService = null
    ) {
        $this->certPath = $certPath;
        $this->certPassword = $certPassword;
        $this->production = $production;
        
        // Inyectar servicio de firma XAdES (si no se proporciona, resolverlo del container)
        $this->xadesService = $xadesService ?? app(XadesSignatureInterface::class);
        
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
     * Send invoice registration to AEAT (dummy implementation, extend as needed)
     *
     * @param Invoice $invoice
     * @return array
     */
    public function sendInvoice(Invoice $invoice): array
    {
        // 1. Obtener datos del emisor desde config
        $issuer = config('verifactu.issuer');
        $issuerName = $issuer['name'] ?? '';
        $issuerVat = $issuer['vat'] ?? '';

        // 2. Mapear Invoice a estructura AEAT (solo campos mínimos para ejemplo)
        $cabecera = [
            'ObligadoEmision' => [
                'NombreRazon' => $issuerName,
                'NIF' => $issuerVat,
            ],
        ];

        // 3. Mapear destinatarios
        $destinatarios = [];
        foreach ($invoice->recipients as $recipient) {
            $destinatarios[] = [
                'NombreRazon' => $recipient->name,
                'NIF' => $recipient->tax_id,
                // 'IDOtro' => ... // Si aplica
            ];
        }

        // 4. Mapear desgloses (Breakdown)
        $desgloses = [];
        foreach ($invoice->breakdowns as $breakdown) {
            $desgloses[] = [
                'TipoImpositivo' => $breakdown->tax_rate,
                'CuotaRepercutida' => $breakdown->tax_amount,
                'BaseImponibleOimporteNoSujeto' => $breakdown->base_amount,
                'Impuesto' => '01',
                'ClaveRegimen' => '01',
                'CalificacionOperacion' => 'S1'
            ];
        }

        // 5. Generar huella (hash) usando HashHelper
        $hashData = [
            'issuer_tax_id' => $issuerVat,
            'invoice_number' => $invoice->number,
            'issue_date' => $invoice->date->format('d-m-Y'),
            'invoice_type' => $invoice->type->value ?? (string)$invoice->type,
            'total_tax' => (string)$invoice->tax,
            'total_amount' => (string)$invoice->total,
            'previous_hash' => '', // Si aplica, para encadenamiento
            'generated_at' => now()->format('c'),
        ];
        $hashResult = \Squareetlabs\VeriFactu\Helpers\HashHelper::generateInvoiceHash($hashData);

        // 6. Construir RegistroAlta
        $registroAlta = [
            'IDVersion' => '1.0',
            'IDFactura' => [
                'IDEmisorFactura' => $issuerVat,
                'NumSerieFactura' => $invoice->number,
                'FechaExpedicionFactura' => $invoice->date->format('Y-m-d'),
            ],
            'NombreRazonEmisor' => $issuerName,
            'TipoFactura' => $invoice->type->value ?? (string)$invoice->type,
            'DescripcionOperacion' => 'Invoice issued',
            'Destinatarios' => [
                'IDDestinatario' => $destinatarios,
            ],
            'Desglose' => $desgloses,
            'CuotaTotal' => (string)$invoice->tax,
            'ImporteTotal' => (string)$invoice->total,
            'Encadenamiento' => [
                'PrimerRegistro' => 'S',
            ],
            'SistemaInformatico' => [
                'NombreRazon' => $issuerName,
                'NIF' => $issuerVat,
                'NombreSistemaInformatico' => 'LaravelVerifactu',
                'IdSistemaInformatico' => '01',
                'Version' => '1.0',
                'NumeroInstalacion' => '001',
                'TipoUsoPosibleSoloVerifactu' => 'S',
                'TipoUsoPosibleMultiOT' => 'N',
                'IndicadorMultiplesOT' => 'N',
            ],
            'FechaHoraHusoGenRegistro' => now()->format('c'),
            'TipoHuella' => '01',
            'Huella' => $hashResult['hash'],
        ];

        $body = [
            'Cabecera' => $cabecera,
            'RegistroFactura' => [
                [ 'RegistroAlta' => $registroAlta ]
            ],
        ];

        // 7. Convertir array a XML
        $xml = $this->arrayToXml($body);

        // 8. 🔐 FIRMAR XML CON XADES-EPES (CRÍTICO para AEAT)
        try {
            $xmlFirmado = $this->xadesService->signXml($xml);
        } catch (\Exception $e) {
            Log::error('[AEAT] Error al firmar XML', [
                'error' => $e->getMessage(),
                'invoice_number' => $invoice->number,
            ]);
            return [
                'status' => 'error',
                'message' => 'Error al firmar XML: ' . $e->getMessage(),
            ];
        }

        // 9. Configurar SoapClient y enviar
        $wsdl = $this->production
            ? 'https://www1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP?wsdl'
            : 'https://prewww2.aeat.es/static_files/common/internet/dep/aplicaciones/es/aeat/tikeV1.0/cont/ws/SistemaFacturacion.wsdl';
        $location = $this->production
            ? 'https://www1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP'
            : 'https://prewww1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP';
        $options = [
            'local_cert' => $this->certPath,
            'passphrase' => $this->certPassword,
            'trace' => true,
            'exceptions' => true,
            'cache_wsdl' => 0,
            'soap_version' => SOAP_1_1,
            'stream_context' => stream_context_create([
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                    'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
                ],
            ]),
        ];
        try {
            $client = new \SoapClient($wsdl, $options);
            $client->__setLocation($location);
            
            // Enviar XML firmado (como string XML, no array)
            $response = $client->__soapCall('RegFactuSistemaFacturacion', [$xmlFirmado]);
            
            return [
                'status' => 'success',
                'request' => $client->__getLastRequest(),
                'response' => $client->__getLastResponse(),
                'aeat_response' => $response,
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

    /**
     * Convertir array PHP a XML string.
     *
     * @param array $data Datos a convertir
     * @param \SimpleXMLElement|null $xmlData Elemento XML padre (recursión)
     * @return string XML como string
     */
    private function arrayToXml(array $data, ?\SimpleXMLElement $xmlData = null): string
    {
        if ($xmlData === null) {
            $xmlData = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><RegistroFacturacion/>');
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                // Si es array numérico, crear múltiples elementos con el mismo nombre
                if (isset($value[0])) {
                    foreach ($value as $item) {
                        $subnode = $xmlData->addChild($key);
                        if (is_array($item)) {
                            $this->arrayToXml($item, $subnode);
                        } else {
                            $subnode[0] = htmlspecialchars((string)$item);
                        }
                    }
                } else {
                    // Array asociativo: crear subelemento
                    $subnode = $xmlData->addChild($key);
                    $this->arrayToXml($value, $subnode);
                }
            } else {
                // Valor escalar
                $xmlData->addChild($key, htmlspecialchars((string)$value));
            }
        }

        return $xmlData->asXML();
    }

    // Métodos adicionales para anulación, consulta, etc. pueden añadirse aquí
}
