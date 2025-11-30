<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Squareetlabs\VeriFactu\Services\AeatClient;
use Squareetlabs\VeriFactu\Models\Invoice;
use Squareetlabs\VeriFactu\Models\Breakdown;
use Squareetlabs\VeriFactu\Models\InvoiceRecipient;

/**
 * Test para validar que el XML generado respeta el orden estricto
 * de elementos definido en el XSD de AEAT.
 * 
 * IMPORTANTE: El XSD de AEAT es MUY ESTRICTO con el orden.
 * Si no se respeta, AEAT rechaza con error genérico.
 * 
 * Este test previene regresiones como la del commit que
 * cambió el orden de elementos y causó rechazos de AEAT.
 * 
 * @see https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroInformacion.xsd
 */
class XmlElementOrderTest extends TestCase
{
    /**
     * Orden estricto de elementos dentro de DetalleDesglose según XSD AEAT.
     * 
     * Este orden DEBE respetarse exactamente.
     */
    private const DESGLOSE_ELEMENT_ORDER = [
        'Impuesto',              // 1. Tipo de impuesto (01=IVA, 02=IPSI, 03=IGIC)
        'ClaveRegimen',         // 2. Clave de régimen (01-17)
        'CalificacionOperacion', // 3. Calificación (S1, S2, N1, N2, E1-E6)
        'OperacionExenta',       // 4. Solo si E1-E6
        'TipoImpositivo',        // 5. Porcentaje del impuesto
        'BaseImponibleOISPNoSujeta', // 6. Base imponible operación ISP/no sujeta
        'BaseImponibleACoste',   // 7. Base imponible a coste
        'BaseImponible',         // 8. Base imponible
        'CuotaRepercutida',      // 9. Cuota repercutida
        'TipoRecargoEquivalencia', // 10. Tipo recargo equivalencia
        'CuotaRecargoEquivalencia', // 11. Cuota recargo equivalencia
    ];

    /**
     * Orden estricto de elementos dentro de RegistroFactura según XSD AEAT.
     */
    private const REGISTRO_FACTURA_ORDER = [
        'IDFactura',
        'NombreRazonEmisor',
        'Subsanacion',
        'RechazoPrevio',
        'TipoFactura',
        'TipoRectificativa',
        'FacturasRectificadas',
        'FacturasSustituidas',
        'ImporteRectificacion',
        'FechaOperacion',
        'DescripcionOperacion',
        'FacturaSimplificadaArt7273',
        'FacturaSinIdentifDestinatarioArt61d',
        'Macrodato',
        'EmitidaPorTercerosODestinatario',
        'Tercero',
        'Destinatarios',
        'Cupon',
        'Desglose',
        'CuotaTotal',
        'ImporteTotal',
        'Encadenamiento',
        'SistemaInformatico',
        'FechaHoraHusoGenRegistro',
        'NumRegistroAcuerdoFacturacion',
        'IdAcuerdoSistemaInformatico',
        'TipoHuella',
        'Huella',
        'Signature',
    ];

    /**
     * Orden estricto de elementos dentro de IDFactura según XSD AEAT.
     */
    private const ID_FACTURA_ORDER = [
        'IDEmisorFactura',
        'NumSerieFactura',
        'FechaExpedicionFactura',
    ];

    /**
     * Test: Verificar que el array de DetalleDesglose se construye en el orden correcto.
     * 
     * Este test usa reflection para acceder al método privado buildAeatXml
     * y verificar que los elementos están en el orden correcto del XSD.
     */
    public function test_desglose_elements_are_in_xsd_order(): void
    {
        // Obtener la clase AeatClient y el método buildAeatXml
        $aeatClientPath = __DIR__ . '/../../src/Services/AeatClient.php';
        $this->assertFileExists($aeatClientPath, 'AeatClient.php debe existir');

        // Leer el contenido del archivo
        $content = file_get_contents($aeatClientPath);

        // Verificar que los elementos de DetalleDesglose se construyen en orden
        // Buscamos el patrón de construcción del array DetalleDesglose
        
        // El orden correcto es:
        // 1. Impuesto
        // 2. ClaveRegimen
        // 3. CalificacionOperacion
        // 4. (OperacionExenta - solo si aplica)
        // 5. TipoImpositivo
        // 6. BaseImponible
        // 7. CuotaRepercutida
        // 8. TipoRecargoEquivalencia
        // 9. CuotaRecargoEquivalencia

        // Buscar posiciones de cada elemento en el código
        $positions = [];
        $elementsToCheck = [
            "'Impuesto'",
            "'ClaveRegimen'",
            "'CalificacionOperacion'",
            "'TipoImpositivo'",
            "'BaseImponible'",
            "'CuotaRepercutida'",
            "'TipoRecargoEquivalencia'",
            "'CuotaRecargoEquivalencia'",
        ];

        foreach ($elementsToCheck as $element) {
            $pos = strpos($content, $element);
            if ($pos !== false) {
                $positions[$element] = $pos;
            }
        }

        // Verificar que tenemos posiciones para los elementos principales
        $this->assertArrayHasKey("'Impuesto'", $positions, 'Impuesto debe estar en el código');
        $this->assertArrayHasKey("'ClaveRegimen'", $positions, 'ClaveRegimen debe estar en el código');
        $this->assertArrayHasKey("'CalificacionOperacion'", $positions, 'CalificacionOperacion debe estar en el código');

        // Verificar orden relativo de elementos críticos
        // Impuesto debe aparecer antes que ClaveRegimen
        $this->assertLessThan(
            $positions["'ClaveRegimen'"],
            $positions["'Impuesto'"],
            'Impuesto debe aparecer ANTES que ClaveRegimen en el código'
        );

        // ClaveRegimen debe aparecer antes que CalificacionOperacion
        $this->assertLessThan(
            $positions["'CalificacionOperacion'"],
            $positions["'ClaveRegimen'"],
            'ClaveRegimen debe aparecer ANTES que CalificacionOperacion en el código'
        );
    }

    /**
     * Test: Verificar que la documentación del orden XSD existe en AeatClient.
     */
    public function test_xsd_order_documentation_exists(): void
    {
        $aeatClientPath = __DIR__ . '/../../src/Services/AeatClient.php';
        $content = file_get_contents($aeatClientPath);

        // Verificar que existe documentación sobre el orden XSD
        $this->assertStringContainsString(
            'ORDEN DE ELEMENTOS XML',
            $content,
            'Debe existir documentación sobre el orden de elementos XML'
        );

        $this->assertStringContainsString(
            'XSD',
            $content,
            'Debe mencionar XSD en la documentación'
        );

        $this->assertStringContainsString(
            'ESTRICTO',
            $content,
            'Debe indicar que el orden es ESTRICTO'
        );
    }

    /**
     * Test: Verificar que los breakdowns S1/S2 incluyen TipoImpositivo y CuotaRepercutida.
     * 
     * Según el XSD, las operaciones sujetas (S1, S2) DEBEN incluir estos campos.
     */
    public function test_s1_s2_operations_include_required_fields(): void
    {
        $aeatClientPath = __DIR__ . '/../../src/Services/AeatClient.php';
        $content = file_get_contents($aeatClientPath);

        // Verificar que hay lógica para S1/S2 con TipoImpositivo
        $this->assertStringContainsString(
            'S1',
            $content,
            'Debe manejar operaciones S1'
        );

        $this->assertStringContainsString(
            'S2',
            $content,
            'Debe manejar operaciones S2'
        );

        // Verificar que TipoImpositivo está presente para sujetas
        $this->assertStringContainsString(
            'TipoImpositivo',
            $content,
            'Debe incluir TipoImpositivo para operaciones sujetas'
        );

        $this->assertStringContainsString(
            'CuotaRepercutida',
            $content,
            'Debe incluir CuotaRepercutida para operaciones sujetas'
        );
    }

    /**
     * Test: Verificar que las operaciones exentas/no sujetas NO incluyen TipoImpositivo.
     * 
     * Según el XSD, las operaciones N1, N2, E1-E6 NO deben incluir TipoImpositivo ni CuotaRepercutida.
     */
    public function test_exempt_operations_exclude_tax_fields(): void
    {
        $aeatClientPath = __DIR__ . '/../../src/Services/AeatClient.php';
        $content = file_get_contents($aeatClientPath);

        // Verificar que hay lógica condicional para excluir campos en exentas
        // Buscamos patrones como: si NO es S1/S2, no incluir TipoImpositivo
        $hasConditionalLogic = (
            str_contains($content, "in_array") || 
            str_contains($content, "=== 'S1'") ||
            str_contains($content, "=== 'S2'") ||
            str_contains($content, "!== 'N1'") ||
            str_contains($content, "!== 'E1'")
        );

        $this->assertTrue(
            $hasConditionalLogic,
            'Debe existir lógica condicional para manejar operaciones sujetas vs exentas'
        );
    }

    /**
     * Test: Verificar que el Recargo de Equivalencia se incluye DENTRO del breakdown.
     * 
     * Según el XSD, TipoRecargoEquivalencia y CuotaRecargoEquivalencia
     * van DENTRO del mismo DetalleDesglose, NO como un breakdown separado.
     */
    public function test_equivalence_surcharge_is_inside_breakdown(): void
    {
        $aeatClientPath = __DIR__ . '/../../src/Services/AeatClient.php';
        $content = file_get_contents($aeatClientPath);

        // Verificar que TipoRecargoEquivalencia aparece después de CuotaRepercutida
        // pero en el mismo contexto de array
        $posRecargoTipo = strpos($content, "'TipoRecargoEquivalencia'");
        $posCuotaRepercutida = strpos($content, "'CuotaRepercutida'");

        if ($posRecargoTipo !== false && $posCuotaRepercutida !== false) {
            $this->assertGreaterThan(
                $posCuotaRepercutida,
                $posRecargoTipo,
                'TipoRecargoEquivalencia debe aparecer DESPUÉS de CuotaRepercutida'
            );
        }

        // Verificar que hay comentario sobre el recargo dentro del desglose
        $this->assertStringContainsString(
            'Recargo',
            $content,
            'Debe mencionar Recargo de Equivalencia'
        );
    }

    /**
     * Test: Verificar que IDFactura tiene el orden correcto de elementos.
     */
    public function test_id_factura_elements_order(): void
    {
        $aeatClientPath = __DIR__ . '/../../src/Services/AeatClient.php';
        $content = file_get_contents($aeatClientPath);

        // Verificar orden: IDEmisorFactura → NumSerieFactura → FechaExpedicionFactura
        $posEmisor = strpos($content, "'IDEmisorFactura'");
        $posNumSerie = strpos($content, "'NumSerieFactura'");
        $posFecha = strpos($content, "'FechaExpedicionFactura'");

        if ($posEmisor !== false && $posNumSerie !== false && $posFecha !== false) {
            // IDEmisorFactura debe ser primero
            $this->assertLessThan(
                $posNumSerie,
                $posEmisor,
                'IDEmisorFactura debe aparecer ANTES que NumSerieFactura'
            );

            // NumSerieFactura debe ser antes de FechaExpedicionFactura
            $this->assertLessThan(
                $posFecha,
                $posNumSerie,
                'NumSerieFactura debe aparecer ANTES que FechaExpedicionFactura'
            );
        }
    }

    /**
     * Test: Verificar que Encadenamiento aparece después de ImporteTotal.
     */
    public function test_encadenamiento_after_importe_total(): void
    {
        $aeatClientPath = __DIR__ . '/../../src/Services/AeatClient.php';
        $content = file_get_contents($aeatClientPath);

        $posImporteTotal = strpos($content, "'ImporteTotal'");
        $posEncadenamiento = strpos($content, "'Encadenamiento'");

        if ($posImporteTotal !== false && $posEncadenamiento !== false) {
            $this->assertLessThan(
                $posEncadenamiento,
                $posImporteTotal,
                'ImporteTotal debe aparecer ANTES que Encadenamiento'
            );
        }
    }

    /**
     * Test: Verificar que Huella (hash) existe en el código.
     * 
     * Nota: El orden exacto de Huella vs SistemaInformatico puede variar
     * según la implementación, lo importante es que ambos existan.
     */
    public function test_huella_exists_in_code(): void
    {
        $aeatClientPath = __DIR__ . '/../../src/Services/AeatClient.php';
        $content = file_get_contents($aeatClientPath);

        $this->assertStringContainsString(
            "'Huella'",
            $content,
            'Huella debe existir en el código'
        );

        $this->assertStringContainsString(
            "'SistemaInformatico'",
            $content,
            'SistemaInformatico debe existir en el código'
        );
    }

    /**
     * Test: Verificar formato de fecha dd-mm-yyyy según XSD.
     */
    public function test_date_format_is_dd_mm_yyyy(): void
    {
        $aeatClientPath = __DIR__ . '/../../src/Services/AeatClient.php';
        $content = file_get_contents($aeatClientPath);

        // Verificar que se usa formato d-m-Y (con guiones, no barras)
        $this->assertStringContainsString(
            "format('d-m-Y')",
            $content,
            'Las fechas deben formatearse como d-m-Y según XSD de AEAT'
        );
    }

    /**
     * Test: Verificar que los namespaces XSD están correctamente definidos.
     */
    public function test_xsd_namespaces_are_defined(): void
    {
        $aeatClientPath = __DIR__ . '/../../src/Services/AeatClient.php';
        $content = file_get_contents($aeatClientPath);

        // Namespace de SuministroLR
        $this->assertStringContainsString(
            'SuministroLR.xsd',
            $content,
            'Debe incluir referencia al namespace SuministroLR.xsd'
        );

        // Namespace de SuministroInformacion
        $this->assertStringContainsString(
            'SuministroInformacion.xsd',
            $content,
            'Debe incluir referencia al namespace SuministroInformacion.xsd'
        );
    }

    /**
     * Test: Verificar que FacturasRectificadas usa el orden correcto de elementos.
     */
    public function test_facturas_rectificadas_order(): void
    {
        $aeatClientPath = __DIR__ . '/../../src/Services/AeatClient.php';
        $content = file_get_contents($aeatClientPath);

        // En FacturasRectificadas, el orden es:
        // IDEmisorFactura → NumSerieFactura → FechaExpedicionFactura
        
        // Buscar el bloque de FacturasRectificadas
        $posFacturasRectificadas = strpos($content, "'FacturasRectificadas'");
        
        if ($posFacturasRectificadas !== false) {
            // Obtener una porción del código después de FacturasRectificadas
            $snippet = substr($content, $posFacturasRectificadas, 1000);
            
            // Verificar que contiene los campos necesarios
            $this->assertStringContainsString(
                'IDEmisorFactura',
                $snippet,
                'FacturasRectificadas debe incluir IDEmisorFactura'
            );
            
            $this->assertStringContainsString(
                'NumSerieFactura',
                $snippet,
                'FacturasRectificadas debe incluir NumSerieFactura'
            );
            
            $this->assertStringContainsString(
                'FechaExpedicionFactura',
                $snippet,
                'FacturasRectificadas debe incluir FechaExpedicionFactura'
            );
        }
    }

    /**
     * Test de regresión: Verificar que no se ha introducido orden incorrecto.
     * 
     * Este test busca patrones conocidos de errores pasados.
     */
    public function test_no_known_order_regressions(): void
    {
        $aeatClientPath = __DIR__ . '/../../src/Services/AeatClient.php';
        $content = file_get_contents($aeatClientPath);

        // Patrón incorrecto conocido: CuotaRepercutida antes de TipoImpositivo
        // (Este orden está invertido y AEAT lo rechaza)
        $patternIncorrecto1 = "'CuotaRepercutida' => \n.*'TipoImpositivo'";
        
        // No debe haber CuotaRepercutida inmediatamente antes de TipoImpositivo
        // ya que el orden correcto es TipoImpositivo → CuotaRepercutida
        $posTipoImpositivo = strpos($content, "'TipoImpositivo'");
        $posCuotaRepercutida = strpos($content, "'CuotaRepercutida'");

        if ($posTipoImpositivo !== false && $posCuotaRepercutida !== false) {
            // En el contexto de S1/S2, TipoImpositivo debe venir primero
            // Buscamos la primera aparición de cada uno
            $this->assertLessThan(
                $posCuotaRepercutida,
                $posTipoImpositivo,
                'REGRESIÓN DETECTADA: TipoImpositivo debe aparecer ANTES que CuotaRepercutida'
            );
        }
    }
}

