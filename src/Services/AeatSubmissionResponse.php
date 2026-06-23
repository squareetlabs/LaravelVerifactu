<?php

declare(strict_types=1);

namespace Squareetlabs\VeriFactu\Services;

/**
 * Typed view over the raw array returned by AeatClient::sendInvoice().
 *
 * Parses the AEAT RespuestaRegFactuSistemaFacturacion structure so consumers
 * don't have to dig through the SOAP stdClass themselves:
 *
 *     $response = AeatSubmissionResponse::fromClientResult(
 *         $client->sendInvoice($invoice, $previous, $record)
 *     );
 *
 *     if ($response->accepted()) { ... }
 *
 * EstadoEnvio values: Correcto | ParcialmenteCorrecto | Incorrecto.
 * Per-line EstadoRegistro values: Correcto | AceptadoConErrores | Incorrecto.
 */
final class AeatSubmissionResponse
{
    public const SEND_CORRECT = 'Correcto';
    public const SEND_PARTIALLY_CORRECT = 'ParcialmenteCorrecto';
    public const SEND_INCORRECT = 'Incorrecto';

    /**
     * @param list<array{invoice_number: ?string, status: ?string, error_code: ?string, error_description: ?string}> $lines
     */
    private function __construct(
        public readonly bool $transportSuccess,
        public readonly ?string $sendStatus,
        public readonly ?string $csv,
        public readonly array $lines,
        public readonly ?string $errorMessage,
        public readonly ?string $rawRequest,
        public readonly ?string $rawResponse,
    ) {
    }

    public static function fromClientResult(array $result): self
    {
        $transportSuccess = ($result['status'] ?? null) === 'success';

        $aeat = $result['aeat_response'] ?? null;
        // SoapClient returns nested stdClass; normalise to arrays once.
        $aeat = $aeat === null ? [] : (json_decode(json_encode($aeat), true) ?: []);

        return new self(
            transportSuccess: $transportSuccess,
            sendStatus: $aeat['EstadoEnvio'] ?? null,
            csv: $aeat['CSV'] ?? null,
            lines: self::parseLines($aeat['RespuestaLinea'] ?? null),
            errorMessage: $result['message'] ?? null,
            rawRequest: $result['request'] ?? null,
            rawResponse: $result['response'] ?? null,
        );
    }

    /** SOAP call succeeded and AEAT accepted every record. */
    public function accepted(): bool
    {
        return $this->transportSuccess && $this->sendStatus === self::SEND_CORRECT;
    }

    /** SOAP call succeeded but AEAT rejected some (not all) records. */
    public function partiallyAccepted(): bool
    {
        return $this->transportSuccess && $this->sendStatus === self::SEND_PARTIALLY_CORRECT;
    }

    public function rejected(): bool
    {
        return !$this->transportSuccess || $this->sendStatus === self::SEND_INCORRECT;
    }

    /** First error found (transport-level or per-line), for logging. */
    public function firstError(): ?string
    {
        if ($this->errorMessage !== null) {
            return $this->errorMessage;
        }

        foreach ($this->lines as $line) {
            if (!empty($line['error_description']) || !empty($line['error_code'])) {
                return trim(($line['error_code'] ?? '') . ' ' . ($line['error_description'] ?? ''));
            }
        }

        return null;
    }

    public function toArray(): array
    {
        return [
            'transport_success' => $this->transportSuccess,
            'send_status' => $this->sendStatus,
            'csv' => $this->csv,
            'lines' => $this->lines,
            'error_message' => $this->errorMessage,
        ];
    }

    /**
     * RespuestaLinea arrives as a single object or a list depending on how
     * many records were submitted.
     */
    private static function parseLines(mixed $respuestaLinea): array
    {
        if (!is_array($respuestaLinea)) {
            return [];
        }

        // Single line: associative array -> wrap into a list.
        if ($respuestaLinea !== [] && !array_is_list($respuestaLinea)) {
            $respuestaLinea = [$respuestaLinea];
        }

        return array_map(static fn (array $line) => [
            'invoice_number' => $line['IDFactura']['NumSerieFactura'] ?? null,
            'status' => $line['EstadoRegistro'] ?? null,
            'error_code' => isset($line['CodigoErrorRegistro']) ? (string) $line['CodigoErrorRegistro'] : null,
            'error_description' => $line['DescripcionErrorRegistro'] ?? null,
        ], $respuestaLinea);
    }
}
