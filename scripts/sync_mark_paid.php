<?php

const FS_FOLDER = __DIR__;

require_once __DIR__ . '/vendor/autoload.php';
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

use FacturaScripts\Core\Model\FacturaProveedor;
use FacturaScripts\Core\Model\FacturaCliente;

$ejercicio = $argv[1] ?? '2025';
$limit = (int)($argv[2] ?? 0);
$verbose = in_array('--verbose', $argv);

echo "=== Marking all {$ejercicio} invoices as paid ===\n\n";

// FacturaProveedor
$fp = new FacturaProveedor();
$where = [new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('codejercicio', $ejercicio)];
$fps = $fp->all($where, [], 0, $limit);
$fpCount = count($fps);
$fpUpdated = 0;

echo "FacturaProveedor: found " . count($fps) . " invoices\n";
foreach ($fps as $f) {
    if ($f->pagada) {
        if ($verbose) echo "  FP {$f->codigo}: already paid\n";
        continue;
    }
    $f->pagada = true;
    $f->observaciones = $f->observaciones; // dirty-field workaround
    if ($f->save()) {
        $fpUpdated++;
        if ($verbose) echo "  FP {$f->codigo}: marked paid\n";
    } else {
        echo "  FP {$f->codigo}: FAILED to save\n";
    }
}
echo "  Updated: {$fpUpdated}\n\n";

// FacturaCliente
$fc = new FacturaCliente();
$fcList = $fc->all($where, [], 0, $limit);
$fcCount = count($fcList);
$fcUpdated = 0;

echo "FacturaCliente: found " . count($fcList) . " invoices\n";
foreach ($fcList as $f) {
    if ($f->pagada) {
        if ($verbose) echo "  FC {$f->codigo}: already paid\n";
        continue;
    }
    $f->pagada = true;
    $f->observaciones = $f->observaciones; // dirty-field workaround
    if ($f->save()) {
        $fcUpdated++;
        if ($verbose) echo "  FC {$f->codigo}: marked paid\n";
    } else {
        echo "  FC {$f->codigo}: FAILED to save\n";
    }
}
echo "  Updated: {$fcUpdated}\n\n";

// Verify
echo "=== Verification ===\n";
$fp2 = new FacturaProveedor();
$allFp = $fp2->all($where);
$paidFp = 0;
$unpaidFp = 0;
foreach ($allFp as $f) {
    if ($f->pagada) $paidFp++;
    else $unpaidFp++;
}
echo "FP: {$paidFp} paid, {$unpaidFp} unpaid out of " . count($allFp) . " total\n";

$fc2 = new FacturaCliente();
$allFc = $fc2->all($where);
$paidFc = 0;
$unpaidFc = 0;
foreach ($allFc as $f) {
    if ($f->pagada) $paidFc++;
    else $unpaidFc++;
}
echo "FC: {$paidFc} paid, {$unpaidFc} unpaid out of " . count($allFc) . " total\n";

echo "\nDone.\n";
