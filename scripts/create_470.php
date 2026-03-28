<?php
const FS_FOLDER = __DIR__;
require_once __DIR__ . '/vendor/autoload.php';
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}
use FacturaScripts\Core\Model\Subcuenta;
use FacturaScripts\Core\Model\Cuenta;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;

$sub = new Subcuenta();
$exists = $sub->loadFromCode('', [
    new DataBaseWhere('codsubcuenta', '4700000100'),
    new DataBaseWhere('codejercicio', '2024')
]);

if ($exists) {
    echo "Already exists: id={$sub->idsubcuenta}\n";
} else {
    $cuenta = new Cuenta();
    if (!$cuenta->loadFromCode('', [
        new DataBaseWhere('codcuenta', '470'),
        new DataBaseWhere('codejercicio', '2024')
    ])) {
        echo "ERROR: Cuenta 470 not found\n";
        exit(1);
    }
    
    $sub->idcuenta = $cuenta->idcuenta;
    $sub->codcuenta = '470';
    $sub->codsubcuenta = '4700000100';
    $sub->codejercicio = '2024';
    $sub->descripcion = 'Hacienda Publica, deudora por IVA a compensar';
    $sub->debe = 0;
    $sub->haber = 0;
    $sub->saldo = 0;
    
    if ($sub->save()) {
        echo "Created: id={$sub->idsubcuenta} codsubcuenta={$sub->codsubcuenta}\n";
    } else {
        echo "ERROR saving subcuenta\n";
    }
}
