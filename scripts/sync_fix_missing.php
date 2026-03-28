<?php
const FS_FOLDER = __DIR__;
require_once __DIR__ . '/vendor/autoload.php';
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

use FacturaScripts\Core\Model\Asiento;
use FacturaScripts\Core\Model\Partida;
use FacturaScripts\Core\Model\Subcuenta;
use FacturaScripts\Core\Model\Ejercicio;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;

function createAsiento($fecha, $concepto, $partidas_data) {
    $year = substr($fecha, 0, 4);
    $ejercicio = new Ejercicio();
    if (!$ejercicio->loadFromCode($year)) {
        echo "  ERROR: Ejercicio $year not found\n";
        return false;
    }
    $ej = $ejercicio;
    if (!$ej) {
        echo "  ERROR: No ejercicio for $fecha\n";
        return false;
    }

    $asiento = new Asiento();
    $asiento->codejercicio = $ej->codejercicio;
    $asiento->fecha = $fecha;
    $asiento->concepto = $concepto;
    $asiento->editable = true;
    $asiento->idempresa = 1;

    if (!$asiento->save()) {
        echo "  ERROR: Failed to save asiento: " . implode(', ', $asiento->errors()) . "\n";
        return false;
    }

    $totalDebe = 0;
    $totalHaber = 0;

    foreach ($partidas_data as $pd) {
        $subcuenta = new Subcuenta();
        if (!$subcuenta->loadFromCode('', [
            new DataBaseWhere('codsubcuenta', $pd['codsubcuenta']),
            new DataBaseWhere('codejercicio', $ej->codejercicio)
        ])) {
            echo "  ERROR: Subcuenta {$pd['codsubcuenta']} not found in ejercicio {$ej->codejercicio}\n";
            $asiento->delete();
            return false;
        }

        $partida = new Partida();
        $partida->idasiento = $asiento->idasiento;
        $partida->idsubcuenta = $subcuenta->idsubcuenta;
        $partida->codsubcuenta = $subcuenta->codsubcuenta;
        $partida->debe = $pd['debe'];
        $partida->haber = $pd['haber'];
        $partida->concepto = $pd['concepto'];
        $partida->disableAdditionalTest(true);

        if (!$partida->save()) {
            echo "  ERROR: Failed to save partida for {$pd['codsubcuenta']}\n";
            $asiento->delete();
            return false;
        }

        $totalDebe += $pd['debe'];
        $totalHaber += $pd['haber'];
    }

    $asiento->importe = $totalDebe;
    $asiento->save();

    echo "  OK: idasiento={$asiento->idasiento}, numero={$asiento->numero}, D=$totalDebe, H=$totalHaber\n";
    return $asiento->idasiento;
}

echo "=== Fixing 3 missing asientos ===\n\n";

echo "1. Spinnaker Factura 004 (H#221):\n";
createAsiento('2024-03-31', 'Factura, 004, spinnaker (spinnaker SCA), 01/04/2024', [
    ['codsubcuenta' => '4300000100', 'debe' => 23067.58, 'haber' => 0, 'concepto' => 'Factura, 004, spinnaker (spinnaker SCA), 01/04/2024'],
    ['codsubcuenta' => '7050000100', 'debe' => 0, 'haber' => 23067.58, 'concepto' => 'AI solution services'],
]);

echo "\n2. MAURIZIO payment (H#132):\n";
createAsiento('2024-07-25', 'Compra 13/24 MAURIZIO ARGENTIN', [
    ['codsubcuenta' => '4000002800', 'debe' => 88.00, 'haber' => 0, 'concepto' => 'Compra 13/24 MAURIZIO ARGENTIN'],
    ['codsubcuenta' => '5510000100', 'debe' => 0, 'haber' => 88.00, 'concepto' => 'Compra 13/24 MAURIZIO ARGENTIN'],
]);

echo "\n3. IVA liquidation (H#483):\n";
createAsiento('2024-12-31', 'Liquidacion IVA', [
    ['codsubcuenta' => '4700000100', 'debe' => 697.35, 'haber' => 0, 'concepto' => 'Liquidacion IVA repercutido'],
    ['codsubcuenta' => '4000001400', 'debe' => 0, 'haber' => 44.47, 'concepto' => 'Regularizacion IVA Sage'],
    ['codsubcuenta' => '6290000600', 'debe' => 44.47, 'haber' => 0, 'concepto' => 'Regularizacion IVA Sage'],
    ['codsubcuenta' => '4720000000', 'debe' => 0, 'haber' => 697.35, 'concepto' => 'Liquidacion IVA soportado'],
]);

echo "\n=== Done ===\n";
