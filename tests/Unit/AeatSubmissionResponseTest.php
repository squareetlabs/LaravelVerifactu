<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Squareetlabs\VeriFactu\Services\AeatSubmissionResponse;

class AeatSubmissionResponseTest extends TestCase
{
    public function testParsesAcceptedResponseWithCsvAndSingleLine(): void
    {
        // SoapClient devuelve stdClass anidado; RespuestaLinea única (no lista).
        $aeat = json_decode(json_encode([
            'EstadoEnvio' => 'Correcto',
            'CSV' => 'ABC123DEF456GHI7',
            'RespuestaLinea' => [
                'IDFactura' => ['NumSerieFactura' => 'FAC-2026-000001'],
                'EstadoRegistro' => 'Correcto',
            ],
        ]));

        $response = AeatSubmissionResponse::fromClientResult([
            'status' => 'success',
            'aeat_response' => $aeat,
            'request' => '<xml/>',
            'response' => '<xml/>',
        ]);

        $this->assertTrue($response->accepted());
        $this->assertFalse($response->partiallyAccepted());
        $this->assertFalse($response->rejected());
        $this->assertEquals('ABC123DEF456GHI7', $response->csv);
        $this->assertCount(1, $response->lines);
        $this->assertEquals('FAC-2026-000001', $response->lines[0]['invoice_number']);
        $this->assertEquals('Correcto', $response->lines[0]['status']);
        $this->assertNull($response->firstError());
    }

    public function testParsesPartiallyCorrectResponseWithLineErrors(): void
    {
        $aeat = json_decode(json_encode([
            'EstadoEnvio' => 'ParcialmenteCorrecto',
            'RespuestaLinea' => [
                [
                    'IDFactura' => ['NumSerieFactura' => 'FAC-2026-000001'],
                    'EstadoRegistro' => 'Correcto',
                ],
                [
                    'IDFactura' => ['NumSerieFactura' => 'FAC-2026-000002'],
                    'EstadoRegistro' => 'Incorrecto',
                    'CodigoErrorRegistro' => 1117,
                    'DescripcionErrorRegistro' => 'Huella no coincide con la cadena',
                ],
            ],
        ]));

        $response = AeatSubmissionResponse::fromClientResult([
            'status' => 'success',
            'aeat_response' => $aeat,
        ]);

        $this->assertFalse($response->accepted());
        $this->assertTrue($response->partiallyAccepted());
        $this->assertCount(2, $response->lines);
        $this->assertEquals('1117 Huella no coincide con la cadena', $response->firstError());
    }

    public function testTransportErrorIsRejectedWithMessage(): void
    {
        $response = AeatSubmissionResponse::fromClientResult([
            'status' => 'error',
            'message' => 'SoapFault: Could not connect to host',
        ]);

        $this->assertFalse($response->accepted());
        $this->assertTrue($response->rejected());
        $this->assertNull($response->sendStatus);
        $this->assertEquals('SoapFault: Could not connect to host', $response->firstError());
    }

    public function testToArrayIsSerializableForAuditStorage(): void
    {
        $response = AeatSubmissionResponse::fromClientResult([
            'status' => 'success',
            'aeat_response' => json_decode(json_encode(['EstadoEnvio' => 'Correcto'])),
        ]);

        $array = $response->toArray();

        $this->assertTrue($array['transport_success']);
        $this->assertEquals('Correcto', $array['send_status']);
        $this->assertIsString(json_encode($array));
    }
}
