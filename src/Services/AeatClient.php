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
     * Send invoice registration to AEAT.
     *
     * @param Invoice $invoice
     * @return array
     */
    public function sendInvoice(Invoice $invoice): array
    {
        // 1. Certificate owner data (Representative) from config
        $certificateOwner = config('verifactu.issuer');
        $certificateName = $certificateOwner['name'] ?? '';
        $certificateVat = $certificateOwner['vat'] ?? '';

        // 2. Issuer data (ObligadoEmision - actual invoice issuer)
        $issuerName = $invoice->issuer_name;
        $issuerVat = $invoice->issuer_tax_id;

        // 3. Build header with Representative (SaaS model)
        $cabecera = [
            'ObligadoEmision' => [
                'NombreRazon' => $issuerName,
                'NIF' => $issuerVat,
            ],
            // Representative: Only include if different from issuer
            ...($issuerVat !== $certificateVat ? [
                'Representante' => [
                    'NombreRazon' => $certificateName,
                    'NIF' => $certificateVat,
                ]
            ] : []),
        ];

        // 4. Map recipients
        $destinatarios = [];
        foreach ($invoice->recipients as $recipient) {
            $destinatarios[] = [
                'NombreRazon' => $recipient->name,
                'NIF' => $recipient->tax_id,
            ];
        }

        // 5. Map tax breakdowns
        $detallesDesglose = [];
        foreach ($invoice->breakdowns as $breakdown) {
            $detallesDesglose[] = [
                'Impuesto' => '01',
                'ClaveRegimen' => '01',
                'CalificacionOperacion' => 'S1',
                'TipoImpositivo' => $breakdown->tax_rate,
                'BaseImponibleOimporteNoSujeto' => $breakdown->base_amount,
                'CuotaRepercutida' => $breakdown->tax_amount,
            ];
        }

        // 6. Generate invoice hash
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

        // 7. Build RegistroAlta
        $registroAlta = [
            'IDVersion' => '1.0',
            'IDFactura' => [
                'IDEmisorFactura' => $issuerVat,
                'NumSerieFactura' => $invoice->number,
                'FechaExpedicionFactura' => $invoice->date->format('d-m-Y'),
            ],
            'NombreRazonEmisor' => $issuerName,
            'TipoFactura' => $invoice->type->value ?? (string)$invoice->type,
            'DescripcionOperacion' => 'Invoice issued',
            ...(!empty($destinatarios) ? ['Destinatarios' => ['IDDestinatario' => $destinatarios]] : []),
            'Desglose' => [
                'DetalleDesglose' => $detallesDesglose,
            ],
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
                [ 'sf:RegistroAlta' => $registroAlta ]
            ],
        ];

        // 8. Convert array to XML
        $xml = $this->buildAeatXml($body);
        
        // 9. Sign XML with XAdES-EPES (required by AEAT)
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

        // 10. Configure SOAP client and send request
        $useLocalWsdl = config('verifactu.aeat.use_local_wsdl', false);
        $wsdlLocal = storage_path('wsdl/SistemaFacturacion.wsdl');
        
        if ($useLocalWsdl && file_exists($wsdlLocal)) {
            $wsdl = $wsdlLocal;
        } else {
            $wsdl = $this->production
                ? 'https://www1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP?wsdl'
                : 'https://prewww2.aeat.es/static_files/common/internet/dep/aplicaciones/es/aeat/tikeV1.0/cont/ws/SistemaFacturacion.wsdl';
        }
        
        $location = $this->production
            ? 'https://www1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP'
            : 'https://prewww1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP';
        
        try {
            // Extract XML without declaration
            $dom = new \DOMDocument();
            $dom->loadXML($xmlFirmado);
            $xmlBody = $dom->saveXML($dom->documentElement);
            
            // Build SOAP Envelope
            $soapEnvelope = sprintf(
                '<?xml version="1.0" encoding="UTF-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>%s</soap:Body></soap:Envelope>',
                $xmlBody
            );
            
            // Send with CURL
            $ch = curl_init($location);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $soapEnvelope,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: text/xml; charset=utf-8',
                    'SOAPAction: ""',
                    'Content-Length: ' . strlen($soapEnvelope),
                ],
                CURLOPT_SSLCERT => $this->certPath,
                CURLOPT_SSLCERTPASSWD => $this->certPassword,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                return [
                    'status' => 'error',
                    'message' => 'CURL Error: ' . $curlError,
                ];
            }
            
            if ($httpCode != 200) {
                // Parsear error del SOAP Fault
                $errorMessage = $this->extractSoapFaultMessage($response);
                return [
                    'status' => 'error',
                    'message' => $errorMessage,
                    'http_code' => $httpCode,
                    'response' => $response,
                ];
            }
            
            // ✅ VALIDAR RESPUESTA DE AEAT (no solo HTTP 200)
            // Verificar si contiene SOAP Fault o error de validación
            $validationResult = $this->validateAeatResponse($response);
            
            if (!$validationResult['success']) {
                return [
                    'status' => 'error',
                    'message' => $validationResult['message'],
                    'codigo_error' => $validationResult['codigo'] ?? null,
                    'response' => $response,
                ];
            }
            
            // ✅ ÉXITO REAL: AEAT aceptó la factura
            return [
                'status' => 'success',
                'request' => $soapEnvelope,
                'response' => $response,
                'aeat_response' => $this->parseSoapResponse($response),
                'csv' => $validationResult['csv'] ?? null,
            ];
        } catch (\SoapFault $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'faultcode' => $e->faultcode ?? null,
                'request' => isset($client) ? $client->__getLastRequest() : null,
                'response' => isset($client) ? $client->__getLastResponse() : null,
            ];
        }
    }

    /**
     * Build AEAT-specific XML with correct namespace structure.
     *
     * @param array $data
     * @return string
     */
    private function buildAeatXml(array $data): string
    {
        $nsSuministroLR = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroLR.xsd';
        $nsSuministroInfo = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroInformacion.xsd';
        
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;
        
        $root = $dom->createElementNS($nsSuministroLR, 'sfLR:RegFactuSistemaFacturacion');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:sf', $nsSuministroInfo);
        $dom->appendChild($root);
        
        $cabecera = $dom->createElementNS($nsSuministroLR, 'Cabecera');
        $this->buildDomElement($dom, $cabecera, $data['Cabecera'], $nsSuministroInfo);
        $root->appendChild($cabecera);
        
        foreach ($data['RegistroFactura'] as $registroData) {
            $registroFactura = $dom->createElementNS($nsSuministroLR, 'RegistroFactura');
            
            if (isset($registroData['sf:RegistroAlta'])) {
                $registroAlta = $dom->createElementNS($nsSuministroInfo, 'sf:RegistroAlta');
                $this->buildDomElement($dom, $registroAlta, $registroData['sf:RegistroAlta'], $nsSuministroInfo);
                $registroFactura->appendChild($registroAlta);
            }
            
            $root->appendChild($registroFactura);
        }
        
        return $dom->saveXML();
    }
    
    /**
     * Build DOM elements recursively.
     */
    private function buildDomElement(\DOMDocument $dom, \DOMElement $parent, array $data, ?string $namespace = null): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if (isset($value[0])) {
                    foreach ($value as $item) {
                        $element = $namespace ? $dom->createElementNS($namespace, $key) : $dom->createElement($key);
                        if (is_array($item)) {
                            $this->buildDomElement($dom, $element, $item, $namespace);
                        } else {
                            $element->nodeValue = htmlspecialchars((string)$item);
                        }
                        $parent->appendChild($element);
                    }
                } else {
                    $element = $namespace ? $dom->createElementNS($namespace, $key) : $dom->createElement($key);
                    $this->buildDomElement($dom, $element, $value, $namespace);
                    $parent->appendChild($element);
                }
            } else {
                $element = $namespace 
                    ? $dom->createElementNS($namespace, $key, htmlspecialchars((string)$value))
                    : $dom->createElement($key, htmlspecialchars((string)$value));
                $parent->appendChild($element);
            }
        }
    }

    /**
     * Validate AEAT response and extract CSV.
     * 
     * @param string $soapResponse
     * @return array ['success' => bool, 'message' => string, 'codigo' => string|null, 'csv' => string|null]
     */
    private function validateAeatResponse(string $soapResponse): array
    {
        try {
            $dom = new \DOMDocument();
            $dom->loadXML($soapResponse);
            
            $faultString = $dom->getElementsByTagName('faultstring')->item(0);
            if ($faultString) {
                return [
                    'success' => false,
                    'message' => $faultString->nodeValue,
                    'codigo' => null,
                ];
            }
            
            $estadoEnvio = $dom->getElementsByTagName('EstadoEnvio')->item(0);
            if (!$estadoEnvio || $estadoEnvio->nodeValue !== 'Correcto') {
                $descripcionErrorEnvio = $dom->getElementsByTagName('DescripcionErrorEnvio')->item(0);
                $codigoErrorEnvio = $dom->getElementsByTagName('CodigoErrorEnvio')->item(0);
                
                return [
                    'success' => false,
                    'message' => $descripcionErrorEnvio ? $descripcionErrorEnvio->nodeValue : 'AEAT submission error',
                    'codigo' => $codigoErrorEnvio ? $codigoErrorEnvio->nodeValue : null,
                ];
            }
            
            $estadoRegistro = $dom->getElementsByTagName('EstadoRegistro')->item(0);
            if (!$estadoRegistro || $estadoRegistro->nodeValue !== 'Correcto') {
                $descripcionError = $dom->getElementsByTagName('DescripcionError')->item(0);
                $codigoError = $dom->getElementsByTagName('CodigoError')->item(0);
                
                return [
                    'success' => false,
                    'message' => $descripcionError ? $descripcionError->nodeValue : 'Invoice registration error',
                    'codigo' => $codigoError ? $codigoError->nodeValue : null,
                ];
            }
            
            $csv = $dom->getElementsByTagName('CSV')->item(0);
            $csvValue = $csv ? $csv->nodeValue : null;
            
            return [
                'success' => true,
                'message' => 'Invoice accepted by AEAT',
                'codigo' => null,
                'csv' => $csvValue,
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Error validating AEAT response: ' . $e->getMessage(),
                'codigo' => null,
            ];
        }
    }
    
    /**
     * Extract SOAP Fault error message.
     */
    private function extractSoapFaultMessage(string $soapResponse): string
    {
        try {
            $dom = new \DOMDocument();
            $dom->loadXML($soapResponse);
            $faultString = $dom->getElementsByTagName('faultstring')->item(0);
            return $faultString ? $faultString->nodeValue : 'Unknown error';
        } catch (\Exception $e) {
            return 'Error parsing SOAP response';
        }
    }
    
    /**
     * Parse successful SOAP response.
     */
    private function parseSoapResponse(string $soapResponse): array
    {
        try {
            $dom = new \DOMDocument();
            $dom->loadXML($soapResponse);
            return [
                'raw' => $soapResponse,
                'parsed' => true,
            ];
        } catch (\Exception $e) {
            return ['raw' => $soapResponse, 'parsed' => false];
        }
    }
}
