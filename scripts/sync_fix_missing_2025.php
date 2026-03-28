<?php
const FS_FOLDER = __DIR__;
require_once __DIR__ . '/vendor/autoload.php';
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}
use FacturaScripts\Core\Model\Asiento;
use FacturaScripts\Core\Model\Partida;
use FacturaScripts\Core\Model\Subcuenta;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;

function createAsiento($fecha, $concepto, $partidas_data) {
    $year = substr($fecha, 0, 4);
    $ejercicio = new \FacturaScripts\Core\Model\Ejercicio();
    if (!$ejercicio->loadFromCode($year)) {
        echo "  ERROR: Ejercicio $year not found\n";
        return false;
    }

    $asiento = new Asiento();
    $asiento->codejercicio = $ejercicio->codejercicio;
    $asiento->fecha = $fecha;
    $asiento->concepto = $concepto;
    $asiento->editable = true;
    $asiento->idempresa = 1;

    if (!$asiento->save()) {
        echo "  ERROR: Failed to save asiento\n";
        return false;
    }

    $totalDebe = 0;
    $totalHaber = 0;

    foreach ($partidas_data as $pd) {
        $subcuenta = new Subcuenta();
        if (!$subcuenta->loadFromCode('', [
            new DataBaseWhere('codsubcuenta', $pd['codsubcuenta']),
            new DataBaseWhere('codejercicio', $ejercicio->codejercicio)
        ])) {
            echo "  ERROR: Subcuenta {$pd['codsubcuenta']} not found\n";
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
    echo "  OK: #{$asiento->numero} D=$totalDebe H=$totalHaber | $concepto\n";
    return $asiento->idasiento;
}

echo "=== Fixing missing 2025 asientos ===\n\n";

echo "1. Spinnaker F250001 (H#24):\n";
createAsiento('2025-01-01', 'Factura, F250001, spinnaker (spinnaker SCA), 01/01/2025', [
    ['codsubcuenta' => '4300000100', 'debe' => 22727.27, 'haber' => 0, 'concepto' => 'Factura, F250001, spinnaker (spinnaker SCA), 01/01/2025'],
    ['codsubcuenta' => '7050000100', 'debe' => 0, 'haber' => 22727.27, 'concepto' => 'software, services, consulting'],
]);

echo "\n2. Salary 02/02 (H#6):\n";
createAsiento('2025-02-02', 'Salario', [
    ['codsubcuenta' => '6400000100', 'debe' => 10306.21, 'haber' => 0, 'concepto' => 'Salario'],
    ['codsubcuenta' => '4760000000', 'debe' => 0, 'haber' => 0, 'concepto' => 'Total S.S.'],
    ['codsubcuenta' => '6420000000', 'debe' => 0, 'haber' => 0, 'concepto' => 'Gasto S.S. Empresa'],
    ['codsubcuenta' => '4751000000', 'debe' => 0, 'haber' => 3774.13, 'concepto' => 'IRPF'],
    ['codsubcuenta' => '4751000100', 'debe' => 0, 'haber' => 32.08, 'concepto' => 'IRPF de pago en especie'],
    ['codsubcuenta' => '6401000100', 'debe' => 87.61, 'haber' => 0, 'concepto' => 'Especie'],
    ['codsubcuenta' => '4650000100', 'debe' => 0, 'haber' => 6587.61, 'concepto' => 'Remuneraciones pendientes de pago - 02/02/2025'],
]);

echo "\n3. Sanitas Nov (H#6b):\n";
createAsiento('2025-11-01', 'Compra, 1/2025/238661162, SANITAS SOCIEDAD ANONIMA DE SEGURO', [
    ['codsubcuenta' => '4000004700', 'debe' => 0, 'haber' => 232.90, 'concepto' => 'Compra, 1/2025/238661162, SANITAS SOCIEDAD ANONIMA DE SEGURO'],
    ['codsubcuenta' => '6250000100', 'debe' => 232.90, 'haber' => 0, 'concepto' => 'SANITAS SOCIEDAD ANONIMA DE SEGUROS'],
]);

echo "\n4. PayPal metaplatfor (H#53):\n";
createAsiento('2025-01-01', 'Paypal *metaplatfor', [
    ['codsubcuenta' => '5510000100', 'debe' => 7.99, 'haber' => 0, 'concepto' => 'Paypal *metaplatfor'],
    ['codsubcuenta' => '5720000100', 'debe' => 0, 'haber' => 7.99, 'concepto' => 'Paypal *metaplatfor'],
]);

echo "\n5. PayPal metaplatfor (H#54):\n";
createAsiento('2025-01-01', 'Paypal *metaplatfor', [
    ['codsubcuenta' => '5510000100', 'debe' => 7.99, 'haber' => 0, 'concepto' => 'Paypal *metaplatfor'],
    ['codsubcuenta' => '5720000100', 'debe' => 0, 'haber' => 7.99, 'concepto' => 'Paypal *metaplatfor'],
]);

echo "\n6. Ballenoil (H#482):\n";
createAsiento('2025-01-01', 'Ballenoil Sl', [
    ['codsubcuenta' => '5700000100', 'debe' => 70.00, 'haber' => 0, 'concepto' => 'Ballenoil Sl'],
    ['codsubcuenta' => '5720000100', 'debe' => 0, 'haber' => 70.00, 'concepto' => 'Ballenoil Sl'],
]);

echo "\n7. Revolut fee Oct (H#8):\n";
createAsiento('2025-10-13', 'Compra, revolut (Revolut Bank UAB), 14/10/2025', [
    ['codsubcuenta' => '4000001000', 'debe' => 0, 'haber' => 125.00, 'concepto' => 'Compra, revolut (Revolut Bank UAB), 14/10/2025'],
    ['codsubcuenta' => '6260000000', 'debe' => 125.00, 'haber' => 0, 'concepto' => 'Servicios bancarios y similares'],
]);

echo "\n8. Revolut fee Nov (H#9):\n";
createAsiento('2025-11-14', 'Compra, revolut (Revolut Bank UAB), 14/11/2025', [
    ['codsubcuenta' => '4000001000', 'debe' => 0, 'haber' => 125.00, 'concepto' => 'Compra, revolut (Revolut Bank UAB), 14/11/2025'],
    ['codsubcuenta' => '6260000000', 'debe' => 125.00, 'haber' => 0, 'concepto' => 'Servicios bancarios y similares'],
]);

echo "\n9. Revolut fee Dec (H#10):\n";
createAsiento('2025-12-14', 'Compra, revolut (Revolut Bank UAB), 14/12/2025', [
    ['codsubcuenta' => '4000001000', 'debe' => 0, 'haber' => 125.00, 'concepto' => 'Compra, revolut (Revolut Bank UAB), 14/12/2025'],
    ['codsubcuenta' => '6260000000', 'debe' => 125.00, 'haber' => 0, 'concepto' => 'Servicios bancarios y similares'],
]);

echo "\n=== Done ===\n";
