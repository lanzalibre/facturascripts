<?php
const FS_FOLDER = __DIR__;
require_once __DIR__ . '/vendor/autoload.php';
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}
use FacturaScripts\Core\Model\Cuenta;
use FacturaScripts\Core\Model\Subcuenta;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;

$items = [
    ['codcuenta' => '120', 'desc' => 'Remuneraciones pendientes de pago'],
    ['codcuenta' => '526', 'desc' => 'Dividendos pasivos'],
];

foreach ($items as $item) {
    $c = new Cuenta();
    if ($c->loadFromCode('', [
        new DataBaseWhere('codcuenta', $item['codcuenta']),
        new DataBaseWhere('codejercicio', '2025')
    ])) {
        echo "Cuenta {$item['codcuenta']}: already exists (id={$c->idcuenta})\n";
        continue;
    }
    $c->codejercicio = '2025';
    $c->codcuenta = $item['codcuenta'];
    $c->descripcion = $item['desc'];
    if ($c->save()) {
        echo "Cuenta {$item['codcuenta']}: created (id={$c->idcuenta})\n";
    } else {
        echo "Cuenta {$item['codcuenta']}: FAILED\n";
    }
}

$subcuentas = [
    '1200000000' => ['codcuenta' => '120', 'desc' => 'Remuneraciones pendientes de pago'],
    '5260000000' => ['codcuenta' => '526', 'desc' => 'Dividendos pasivos'],
    '4751000400' => ['codcuenta' => '475', 'desc' => 'Cuenta 4751000400'],
];

foreach ($subcuentas as $code => $info) {
    $s = new Subcuenta();
    if ($s->loadFromCode('', [
        new DataBaseWhere('codsubcuenta', $code),
        new DataBaseWhere('codejercicio', '2025')
    ])) {
        echo "Subcuenta $code: already exists\n";
        continue;
    }
    $c = new Cuenta();
    $c->loadFromCode('', [
        new DataBaseWhere('codcuenta', $info['codcuenta']),
        new DataBaseWhere('codejercicio', '2025')
    ]);
    $s->codejercicio = '2025';
    $s->codcuenta = $info['codcuenta'];
    $s->codsubcuenta = $code;
    $s->descripcion = $info['desc'];
    if (isset($c->idcuenta)) $s->idcuenta = $c->idcuenta;
    if ($s->save()) {
        echo "Subcuenta $code: created\n";
    } else {
        echo "Subcuenta $code: FAILED\n";
    }
}
