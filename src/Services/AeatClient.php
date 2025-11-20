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
        // 1. Obtener datos del certificado (Representante) desde config
        $certificateOwner = config('verifactu.issuer');
        $certificateName = $certificateOwner['name'] ?? '';
        $certificateVat = $certificateOwner['vat'] ?? '';

        // 2. ObligadoEmision: Datos del cliente (quien emite la factura)
        // El issuer_name e issuer_tax_id de la factura corresponden al cliente final
        $issuerName = $invoice->issuer_name;
        $issuerVat = $invoice->issuer_tax_id;

        // 3. Construir Cabecera con Representante (modelo SaaS/Asesoría)
        $cabecera = [
            'ObligadoEmision' => [
                'NombreRazon' => $issuerName,  // Cliente (quien emite)
                'NIF' => $issuerVat,            // NIF del cliente
            ],
            // Representante: Tu empresa (quien presenta en nombre del cliente)
            // Solo incluir si el NIF del cliente es diferente al del certificado
            ...($issuerVat !== $certificateVat ? [
                'Representante' => [
                    'NombreRazon' => $certificateName,
                    'NIF' => $certificateVat,
                ]
            ] : []),
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

        // 4. Mapear desgloses (Breakdown) - Estructura correcta según XSD
        // DesgloseType requiere elementos DetalleDesglose (hasta 12)
        $detallesDesglose = [];
        foreach ($invoice->breakdowns as $breakdown) {
            $detallesDesglose[] = [
                'Impuesto' => '01',  // 01=IVA, 02=IPSI, 03=IGIC, 05=Otros
                'ClaveRegimen' => '01',  // Clave régimen IVA
                'CalificacionOperacion' => 'S1',  // S1: Sujeta sin inversión
                'TipoImpositivo' => $breakdown->tax_rate,
                'BaseImponibleOimporteNoSujeto' => $breakdown->base_amount,
                'CuotaRepercutida' => $breakdown->tax_amount,
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
                'FechaExpedicionFactura' => $invoice->date->format('d-m-Y'),  // Formato dd-mm-yyyy según XSD
            ],
            'NombreRazonEmisor' => $issuerName,
            'TipoFactura' => $invoice->type->value ?? (string)$invoice->type,
            'DescripcionOperacion' => 'Invoice issued',
            // Destinatarios: Opcional (minOccurs=0 según XSD)
            // Solo incluir si hay destinatarios válidos
            ...(!empty($destinatarios) ? ['Destinatarios' => ['IDDestinatario' => $destinatarios]] : []),
            'Desglose' => [
                'DetalleDesglose' => $detallesDesglose,  // Estructura correcta según XSD
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
                [ 'sf:RegistroAlta' => $registroAlta ] // Prefijo sf: para RegistroAlta
            ],
        ];

        // 7. Convertir array a XML con DOMDocument (mejor control de namespaces)
        $xml = $this->buildAeatXml($body);

        // Guardar XML para debug
        $debugPath = storage_path('logs/debug_xml_' . time() . '.xml');
        file_put_contents($debugPath, $xml);
        
        // Log XML ANTES de firmar (debug)
        Log::info('[AEAT] XML antes de firmar guardado en: ' . basename($debugPath), [
            'length' => strlen($xml),
        ]);
        
        // 8. 🔐 FIRMAR XML CON XADES-EPES (CRÍTICO para AEAT)
        try {
            $xmlFirmado = $this->xadesService->signXml($xml);
            
            // Log XML DESPUÉS de firmar (debug)
            Log::info('[AEAT] XML después de firmar', [
                'xml' => substr($xmlFirmado, 0, 500), // Primeros 500 caracteres
                'length' => strlen($xmlFirmado),
            ]);
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
        // Determinar WSDL: local (si está configurado) o remoto
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
        
        // Configurar opciones SSL con certificado de cliente
        $sslOptions = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'local_cert' => $this->certPath,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
        ];
        
        // Solo añadir passphrase si existe
        if (!empty($this->certPassword)) {
            $sslOptions['passphrase'] = $this->certPassword;
        }
        
        $options = [
            'trace' => true,
            'exceptions' => true,
            'cache_wsdl' => 0,
            'soap_version' => SOAP_1_1,
            'stream_context' => stream_context_create([
                'ssl' => $sslOptions,
            ]),
        ];
        try {
            // Log configuración para debug
            \Log::info('[AEAT] Intentando conectar', [
                'wsdl' => basename($wsdl),
                'location' => $location,
                'cert_path' => $this->certPath,
                'cert_exists' => file_exists($this->certPath),
                'has_password' => !empty($this->certPassword),
            ]);
            
            // Extraer el XML sin la declaración
            $dom = new \DOMDocument();
            $dom->loadXML($xmlFirmado);
            $xmlBody = $dom->saveXML($dom->documentElement);
            
            // Construir el SOAP Envelope manualmente
            $soapEnvelope = '<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
    <soap:Body>
        ' . $xmlBody . '
    </soap:Body>
</soap:Envelope>';
            
            // Log para debug
            \Log::info('[AEAT] SOAP Envelope construido', [
                'length' => strlen($soapEnvelope),
                'preview' => substr($soapEnvelope, 0, 1000),
            ]);
            
            // Enviar con CURL
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
                \Log::error('[AEAT] Error CURL', ['error' => $curlError]);
                return [
                    'status' => 'error',
                    'message' => 'CURL Error: ' . $curlError,
                ];
            }
            
            \Log::info('[AEAT] Respuesta recibida', [
                'http_code' => $httpCode,
                'response_length' => strlen($response),
                'response_preview' => substr($response, 0, 500),
            ]);
            
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
            
            return [
                'status' => 'success',
                'request' => $soapEnvelope,
                'response' => $response,
                'aeat_response' => $this->parseSoapResponse($response),
            ];
        } catch (\SoapFault $e) {
            // Capturar más detalles del error
            \Log::error('[AEAT] Error SOAP', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'faultcode' => $e->faultcode ?? null,
                'faultstring' => $e->faultstring ?? null,
                'detail' => $e->detail ?? null,
            ]);
            
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
     * Construir XML específico para AEAT con estructura correcta de namespaces.
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
        
        // Elemento raíz con namespace sfLR
        $root = $dom->createElementNS($nsSuministroLR, 'sfLR:RegFactuSistemaFacturacion');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:sf', $nsSuministroInfo);
        $dom->appendChild($root);
        
        // Cabecera SIN prefijo (pertenece al namespace raíz sfLR)
        // pero sus hijos deben usar el namespace sf: porque son del tipo sf:CabeceraType
        $cabecera = $dom->createElementNS($nsSuministroLR, 'Cabecera');
        $this->buildDomElement($dom, $cabecera, $data['Cabecera'], $nsSuministroInfo);
        $root->appendChild($cabecera);
        
        // RegistroFactura SIN prefijo (pertenece al namespace raíz sfLR)
        foreach ($data['RegistroFactura'] as $registroData) {
            $registroFactura = $dom->createElementNS($nsSuministroLR, 'RegistroFactura');
            
            // RegistroAlta con namespace sf: (es una referencia a elemento definido)
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
     * Construir elementos DOM recursivamente.
     */
    private function buildDomElement(\DOMDocument $dom, \DOMElement $parent, array $data, ?string $namespace = null): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if (isset($value[0])) {
                    // Array numérico
                    foreach ($value as $item) {
                        $element = $namespace 
                            ? $dom->createElementNS($namespace, $key)
                            : $dom->createElement($key);
                        if (is_array($item)) {
                            $this->buildDomElement($dom, $element, $item, $namespace);
                        } else {
                            $element->nodeValue = htmlspecialchars((string)$item);
                        }
                        $parent->appendChild($element);
                    }
                } else {
                    // Array asociativo
                    $element = $namespace 
                        ? $dom->createElementNS($namespace, $key)
                        : $dom->createElement($key);
                    $this->buildDomElement($dom, $element, $value, $namespace);
                    $parent->appendChild($element);
                }
            } else {
                // Valor escalar
                $element = $namespace 
                    ? $dom->createElementNS($namespace, $key, htmlspecialchars((string)$value))
                    : $dom->createElement($key, htmlspecialchars((string)$value));
                $parent->appendChild($element);
            }
        }
    }
    
    /**
     * Convertir array PHP a XML string.
     *
     * @param array $data Datos a convertir
     * @param \SimpleXMLElement|null $xmlData Elemento XML padre (recursión)
     * @return string XML como string
     */
    private function arrayToXml(array $data, ?\SimpleXMLElement $xmlData = null, ?string $parentNs = null, bool $isRoot = true): string
    {
        if ($xmlData === null) {
            // Namespaces correctos de AEAT
            $nsSuministroLR = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroLR.xsd';
            $nsSuministroInfo = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroInformacion.xsd';
            
            // Elemento raíz: RegFactuSistemaFacturacion del namespace SuministroLR
            $xmlData = new \SimpleXMLElement(
                '<?xml version="1.0" encoding="UTF-8"?>' .
                '<sfLR:RegFactuSistemaFacturacion ' .
                'xmlns:sfLR="' . $nsSuministroLR . '" ' .
                'xmlns:sf="' . $nsSuministroInfo . '"/>',
                0,
                false,
                $nsSuministroLR
            );
            
            $parentNs = 'root'; // Marcar que estamos en la raíz
        }

        foreach ($data as $key => $value) {
            // Detectar si la clave tiene prefijo de namespace explícito (ej: "sf:RegistroAlta")
            $useNamespace = null;
            $elementName = $key;
            if (str_contains($key, ':')) {
                [$prefix, $elementName] = explode(':', $key, 2);
                if ($prefix === 'sf') {
                    $useNamespace = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroInformacion.xsd';
                }
            }
            
            if (is_array($value)) {
                // Si es array numérico, crear múltiples elementos con el mismo nombre
                if (isset($value[0])) {
                    foreach ($value as $item) {
                        $subnode = $useNamespace 
                            ? $xmlData->addChild($elementName, null, $useNamespace)
                            : $xmlData->addChild($elementName);
                        if (is_array($item)) {
                            $this->arrayToXml($item, $subnode, $elementName, false);
                        } else {
                            $subnode[0] = htmlspecialchars((string)$item);
                        }
                    }
                } else {
                    // Array asociativo: crear subelemento
                    $subnode = $useNamespace 
                        ? $xmlData->addChild($elementName, null, $useNamespace)
                        : $xmlData->addChild($elementName);
                    $this->arrayToXml($value, $subnode, $elementName, false);
                }
            } else {
                // Valor escalar
                if ($useNamespace) {
                    $xmlData->addChild($elementName, htmlspecialchars((string)$value), $useNamespace);
                } else {
                    $xmlData->addChild($elementName, htmlspecialchars((string)$value));
                }
            }
        }

        return $xmlData->asXML();
    }

    /**
     * Extraer mensaje de error de SOAP Fault.
     */
    private function extractSoapFaultMessage(string $soapResponse): string
    {
        try {
            $dom = new \DOMDocument();
            $dom->loadXML($soapResponse);
            $faultString = $dom->getElementsByTagName('faultstring')->item(0);
            return $faultString ? $faultString->nodeValue : 'Error desconocido';
        } catch (\Exception $e) {
            return 'Error al parsear respuesta SOAP';
        }
    }
    
    /**
     * Parsear respuesta SOAP exitosa.
     */
    private function parseSoapResponse(string $soapResponse): array
    {
        try {
            $dom = new \DOMDocument();
            $dom->loadXML($soapResponse);
            // Extraer datos relevantes de la respuesta
            return [
                'raw' => $soapResponse,
                'parsed' => true,
            ];
        } catch (\Exception $e) {
            return ['raw' => $soapResponse, 'parsed' => false];
        }
    }
    
    // Métodos adicionales para anulación, consulta, etc. pueden añadirse aquí
}
