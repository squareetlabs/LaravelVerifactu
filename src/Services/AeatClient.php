<?php

declare(strict_types=1);

namespace Squareetlabs\VeriFactu\Services;

use Squareetlabs\VeriFactu\Models\Invoice;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * Cliente SOAP para comunicación con AEAT Verifactu.
 * 
 * IMPORTANTE - ORDEN DE ELEMENTOS XML (XSD AEAT):
 * ================================================
 * El XSD de AEAT es MUY ESTRICTO con el orden de los elementos.
 * Si el orden no es correcto, AEAT devuelve error 4102:
 * "El XML no cumple el esquema. Falta informar campo obligatorio.: DetalleDesglose"
 * 
 * Orden correcto de DetalleDesglose según XSD:
 *   1. Impuesto (01=IVA, 02=IPSI, 03=IGIC)
 *   2. ClaveRegimen (01=General, 02=Exportación, etc.)
 *   3. CalificacionOperacion (S1, S2, N1, N2) - SOLO para sujetas/no sujetas
 *   4. OperacionExenta (E1-E6) - SOLO para exentas, EXCLUYENTE con CalificacionOperacion
 *   5. TipoImpositivo (SOLO para S1/S2)
 *   6. BaseImponibleOimporteNoSujeto
 *   7. CuotaRepercutida (SOLO para S1/S2)
 *   8. TipoRecargoEquivalencia (opcional)
 *   9. CuotaRecargoEquivalencia (opcional)
 * 
 * NOTA CRÍTICA: CalificacionOperacion y OperacionExenta son MUTUAMENTE EXCLUYENTES
 * - Para S1/S2/N1/N2: usar CalificacionOperacion
 * - Para E1-E6: usar OperacionExenta (NO CalificacionOperacion)
 * 
 * Issue resuelta 2025-11-30: El orden incorrecto (BaseImponible antes de TipoImpositivo)
 * causaba rechazo de AEAT aunque el XML parecía correcto visualmente.
 * 
 * @see https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroInformacion.xsd
 */
class AeatClient
{
    private string $certPath;
    private string $certPassword;
    private bool $production;

    public function __construct(
        string $certPath,
        string $certPassword,
        bool $production = false
    ) {
        $this->certPath = $certPath;
        $this->certPassword = $certPassword;
        $this->production = $production;
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
        // IMPORTANTE: Para destinatarios extranjeros (no ES) se usa IDOtro en lugar de NIF
        $destinatarios = [];
        foreach ($invoice->recipients as $recipient) {
            $country = $recipient->country ?? 'ES';
            
            if ($country === 'ES') {
                // Destinatario español: usar NIF
                $destinatarios[] = [
                    'NombreRazon' => $recipient->name,
                    'NIF' => $recipient->tax_id,
                ];
            } else {
                // Destinatario extranjero: usar IDOtro
                // El tax_id puede venir con prefijo de país (DE999999999) o sin él
                $taxId = $recipient->tax_id;
                // Quitar prefijo del país si existe
                if (strlen($taxId) > 2 && strtoupper(substr($taxId, 0, 2)) === strtoupper($country)) {
                    $taxId = substr($taxId, 2);
                }
                
                // IDType: 02=NIF-IVA, 03=Pasaporte, 04=Doc. oficial, 05=Certificado residencia, 06=Otro, 07=No censado
                $idType = $recipient->id_type ?? '02';
                
                $destinatarios[] = [
                    'NombreRazon' => $recipient->name,
                    'IDOtro' => [
                        'CodigoPais' => $country,
                        'IDType' => $idType,
                        'ID' => $taxId,
                    ],
                ];
            }
        }

        // 5. Map tax breakdowns (ver documentación de clase para orden XSD)
        // IMPORTANTE: CalificacionOperacion solo acepta S1, S2, N1, N2
        // Para exentas (E1-E6) se usa el campo OperacionExenta
        $detallesDesglose = [];
        foreach ($invoice->breakdowns as $breakdown) {
            $operationTypeValue = $breakdown->operation_type->value ?? $breakdown->operation_type ?? 'S1';
            $isNotSubject = in_array($operationTypeValue, ['N1', 'N2']);
            $isExempt = in_array($operationTypeValue, ['E1', 'E2', 'E3', 'E4', 'E5', 'E6']);
            
            if ($isNotSubject) {
                // N1/N2 (no sujetas): CalificacionOperacion = N1/N2, SIN TipoImpositivo ni CuotaRepercutida
                $detallesDesglose[] = [
                    'Impuesto' => $breakdown->tax_type->value ?? $breakdown->tax_type ?? '01',
                    'ClaveRegimen' => $breakdown->regime_type->value ?? $breakdown->regime_type ?? '01',
                    'CalificacionOperacion' => $operationTypeValue,
                    'BaseImponibleOimporteNoSujeto' => $breakdown->base_amount,
                ];
            } elseif ($isExempt) {
                // E1-E6 (exentas): OperacionExenta = E1-E6, SIN CalificacionOperacion, TipoImpositivo ni CuotaRepercutida
                $detallesDesglose[] = [
                    'Impuesto' => $breakdown->tax_type->value ?? $breakdown->tax_type ?? '01',
                    'ClaveRegimen' => $breakdown->regime_type->value ?? $breakdown->regime_type ?? '01',
                    'OperacionExenta' => $operationTypeValue,
                    'BaseImponibleOimporteNoSujeto' => $breakdown->base_amount,
                ];
            } else {
                // S1/S2 (sujetas): CON TipoImpositivo y CuotaRepercutida (orden XSD crítico)
                $desglose = [
                    'Impuesto' => $breakdown->tax_type->value ?? $breakdown->tax_type ?? '01',
                    'ClaveRegimen' => $breakdown->regime_type->value ?? $breakdown->regime_type ?? '01',
                    'CalificacionOperacion' => $operationTypeValue,
                    'TipoImpositivo' => $breakdown->tax_rate,
                    'BaseImponibleOimporteNoSujeto' => $breakdown->base_amount,
                    'CuotaRepercutida' => $breakdown->tax_amount,
                ];
                
                // Recargo de Equivalencia (opcional, dentro del mismo desglose según XSD)
                if (!empty($breakdown->equivalence_surcharge_rate) && $breakdown->equivalence_surcharge_rate > 0) {
                    $desglose['TipoRecargoEquivalencia'] = $breakdown->equivalence_surcharge_rate;
                    $desglose['CuotaRecargoEquivalencia'] = $breakdown->equivalence_surcharge_amount ?? 0;
                }
                
                $detallesDesglose[] = $desglose;
            }
        }

        // 6. Generate invoice hash
        $hashData = [
            'issuer_tax_id' => $issuerVat,
            'invoice_number' => $invoice->number,
            'issue_date' => $invoice->date->format('d-m-Y'),
            'invoice_type' => $invoice->type->value ?? (string)$invoice->type,
            'total_tax' => (string)$invoice->tax,
            'total_amount' => (string)$invoice->total,
            'previous_hash' => $invoice->previous_invoice_hash ?? '',
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
            ...($invoice->external_reference ? ['RefExterna' => $invoice->external_reference] : []),
            'NombreRazonEmisor' => $issuerName,
            ...($invoice->is_subsanacion ? [
                'Subsanacion' => 'S',
                'RechazoPrevio' => 'S',
            ] : []),
            'TipoFactura' => $invoice->type->value ?? (string)$invoice->type,
            // TipoRectificativa (solo si aplica)
            ...($invoice->rectificative_type ? ['TipoRectificativa' => $invoice->rectificative_type] : []),
            // FacturasRectificadas (solo si aplica)
            ...($invoice->rectified_invoices && !empty($invoice->rectified_invoices) ? [
                'FacturasRectificadas' => [
                    'IDFacturaRectificada' => array_map(function($rectified) {
                        return [
                            'IDEmisorFactura' => $rectified['issuer_tax_id'] ?? $rectified['IDEmisorFactura'],
                            'NumSerieFactura' => $rectified['number'] ?? $rectified['NumSerieFactura'],
                            'FechaExpedicionFactura' => $rectified['date'] ?? $rectified['FechaExpedicionFactura'],
                        ];
                    }, $invoice->rectified_invoices)
                ]
            ] : []),
            ...($invoice->rectification_amount ? [
                'ImporteRectificacion' => [
                    'BaseRectificada' => (string)($invoice->rectification_amount['base'] ?? 0),
                    'CuotaRectificada' => (string)($invoice->rectification_amount['tax'] ?? 0),
                    'ImporteRectificacion' => (string)($invoice->rectification_amount['total'] ?? 0),
                ]
            ] : []),
            ...($invoice->operation_date ? ['FechaOperacion' => $invoice->operation_date->format('d-m-Y')] : []),
            'DescripcionOperacion' => $invoice->description ?? 'Operación de facturación',
            ...(!empty($destinatarios) ? ['Destinatarios' => ['IDDestinatario' => $destinatarios]] : []),
            'Desglose' => [
                'DetalleDesglose' => $detallesDesglose,
            ],
            'CuotaTotal' => (string)$invoice->tax,
            'ImporteTotal' => (string)$invoice->total,
            // Encadenamiento: primera factura vs factura encadenada
            'Encadenamiento' => $invoice->is_first_invoice 
                ? ['PrimerRegistro' => 'S']
                : [
                    'RegistroAnterior' => [
                        'IDEmisorFactura' => $issuerVat,
                        'NumSerieFactura' => $invoice->previous_invoice_number,
                        'FechaExpedicionFactura' => $invoice->previous_invoice_date->format('d-m-Y'),
                        'Huella' => $invoice->previous_invoice_hash,
                    ]
            ],
            'SistemaInformatico' => [
                'NombreRazon' => $issuerName,
                'NIF' => $issuerVat,
                'NombreSistemaInformatico' => config('verifactu.sistema_informatico.nombre', 'OrbilaiVerifactu'),
                'IdSistemaInformatico' => config('verifactu.sistema_informatico.id', 'OV'),
                'Version' => config('verifactu.sistema_informatico.version', '1.0'),
                'NumeroInstalacion' => $invoice->numero_instalacion,
                'TipoUsoPosibleSoloVerifactu' => config('verifactu.sistema_informatico.solo_verifactu', true) ? 'S' : 'N',
                'TipoUsoPosibleMultiOT' => config('verifactu.sistema_informatico.multi_ot', true) ? 'S' : 'N',
                'IndicadorMultiplesOT' => config('verifactu.sistema_informatico.indicador_multiples_ot', false) ? 'S' : 'N',
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

        // 8. Convert array to XML and send to AEAT
        $xml = $this->buildAeatXml($body);
        $location = $this->production
            ? 'https://www1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP'
            : 'https://prewww1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP';
        
        try {
            $dom = new \DOMDocument();
            $dom->loadXML($xml);
            $xmlBody = $dom->saveXML($dom->documentElement);
            
            $soapEnvelope = sprintf(
                '<?xml version="1.0" encoding="UTF-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>%s</soap:Body></soap:Envelope>',
                $xmlBody
            );
            
            $response = Http::withOptions([
                'cert' => [$this->certPath, $this->certPassword],
                'verify' => true,
            ])
            ->connectTimeout(10)
            ->timeout(30)
            ->retry(2, 500, throw: false)
            ->withHeaders([
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPAction' => '""',
                'User-Agent' => 'LaravelVerifactu/1.0',
            ])
            ->withBody($soapEnvelope, 'text/xml')
            ->post($location);
            
            // First check: HTTP transport level errors (4xx, 5xx)
            if (!$response->successful()) {
                $errorMessage = $this->extractSoapFaultMessage($response->body());
                return [
                    'status' => 'error',
                    'message' => $errorMessage,
                    'http_code' => $response->status(),
                    'response' => $response->body(),
                ];
            }
            
            // Second check: AEAT business logic validation
            // IMPORTANT: HTTP 200 doesn't mean AEAT accepted the invoice
            // AEAT can return HTTP 200 with EstadoEnvio=Incorrecto or EstadoRegistro=Incorrecto
            $validationResult = $this->validateAeatResponse($response->body());
            
            if (!$validationResult['success']) {                                
                return [
                    'status' => 'error',
                    'message' => $validationResult['message'],
                    'codigo_error' => $validationResult['codigo'] ?? null,
                    'response' => $response->body(),
                ];
            }
            
            // Success: AEAT accepted the invoice
            return [
                'status' => 'success',
                'request' => $soapEnvelope,
                'response' => $response->body(),
                'aeat_response' => $this->parseSoapResponse($response->body()),
                'csv' => $validationResult['csv'] ?? null,
            ];
            
        } catch (ConnectionException $e) {
            return [
                'status' => 'error',
                'message' => 'Connection error: ' . $e->getMessage(),
            ];
        } catch (RequestException $e) {
            return [
                'status' => 'error',
                'message' => 'Request error: ' . $e->getMessage(),
                'http_code' => $e->response?->status(),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Unexpected error: ' . $e->getMessage(),
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
     * @return array
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
                    'message' => 'SOAP Fault: ' . $faultString->nodeValue,
                    'codigo' => null,
                    'csv' => null,
                ];
            }
            
            $estadoEnvio = $dom->getElementsByTagName('EstadoEnvio')->item(0);
            
            if (!$estadoEnvio) {
                return [
                    'success' => false,
                    'message' => 'EstadoEnvio not found in response',
                    'codigo' => null,
                    'csv' => null,
                ];
            }
            
            $estadoEnvioValue = $estadoEnvio->nodeValue;
            
            if (!in_array($estadoEnvioValue, ['Correcto', 'ParcialmenteCorrecto', 'Incorrecto'])) {
                return [
                    'success' => false,
                    'message' => "Unknown AEAT estado_envio value: {$estadoEnvioValue}. Please update the system.",
                    'codigo' => null,
                    'csv' => null,
                ];
            }
            
            if ($estadoEnvioValue === 'Incorrecto') {                
                $descripcionErrorEnvio = $dom->getElementsByTagName('DescripcionErrorEnvio')->item(0);
                $codigoErrorEnvio = $dom->getElementsByTagName('CodigoErrorEnvio')->item(0);
                
                if (!$descripcionErrorEnvio) {
                    $descripcionErrorEnvio = $dom->getElementsByTagName('DescripcionErrorRegistro')->item(0);
                    $codigoErrorEnvio = $dom->getElementsByTagName('CodigoErrorRegistro')->item(0);
                }
                
                return [
                    'success' => false,
                    'message' => $descripcionErrorEnvio 
                        ? 'AEAT submission error: ' . $descripcionErrorEnvio->nodeValue 
                        : 'AEAT submission error (no description provided)',
                    'codigo' => $codigoErrorEnvio ? $codigoErrorEnvio->nodeValue : null,
                    'csv' => null,
                ];
            }
            
            $estadoRegistro = $dom->getElementsByTagName('EstadoRegistro')->item(0);
            
            if (!$estadoRegistro) {
                return [
                    'success' => false,
                    'message' => 'EstadoRegistro not found in response',
                    'codigo' => null,
                    'csv' => null,
                    'estado_registro' => null,
                ];
            }
            
            $estadoValue = $estadoRegistro->nodeValue;
            
            if ($estadoValue === 'Incorrecto') {
                $descripcionError = $dom->getElementsByTagName('DescripcionErrorRegistro')->item(0);
                $codigoError = $dom->getElementsByTagName('CodigoErrorRegistro')->item(0);
                
                return [
                    'success' => false,
                    'message' => $descripcionError 
                        ? 'Invoice registration error: ' . $descripcionError->nodeValue 
                        : 'Invoice registration error (no description provided)',
                    'codigo' => $codigoError ? $codigoError->nodeValue : null,
                    'csv' => null,
                    'estado_registro' => 'Incorrecto',
                ];
            }
            
            if (!in_array($estadoValue, ['Correcto', 'AceptadoConErrores'])) {
                return [
                    'success' => false,
                    'message' => "Unknown AEAT estado_registro value: {$estadoValue}. Please update the system.",
                    'codigo' => null,
                    'csv' => null,
                    'estado_registro' => $estadoValue,
                ];
            }
            
            $csv = $dom->getElementsByTagName('CSV')->item(0);
            $csvValue = $csv ? $csv->nodeValue : null;
            
            if (!$csvValue) {
                return [
                    'success' => false,
                    'message' => 'Invoice accepted but CSV not found in response',
                    'codigo' => null,
                    'csv' => null,
                    'estado_registro' => $estadoValue,
                ];
            }
            
            $warnings = null;
            if ($estadoValue === 'AceptadoConErrores') {
                $descripcionError = $dom->getElementsByTagName('DescripcionErrorRegistro')->item(0);
                $codigoError = $dom->getElementsByTagName('CodigoErrorRegistro')->item(0);
                
                $warnings = [
                    'codigo' => $codigoError ? $codigoError->nodeValue : null,
                    'descripcion' => $descripcionError ? $descripcionError->nodeValue : null,
                ];
            }
            
            return [
                'success' => true,
                'message' => $estadoValue === 'Correcto' 
                    ? 'Invoice accepted by AEAT' 
                    : 'Invoice accepted by AEAT with warnings',
                'estado_registro' => $estadoValue,
                'warnings' => $warnings,
                'codigo' => null,
                'csv' => $csvValue,
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Error parsing AEAT response: ' . $e->getMessage(),
                'codigo' => null,
                'csv' => null,
            ];
        }
    }
    
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
