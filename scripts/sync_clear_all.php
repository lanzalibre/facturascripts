<?php

const FS_FOLDER = __DIR__;

require_once __DIR__ . '/vendor/autoload.php';
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

use FacturaScripts\Core\Model\FacturaProveedor;
use FacturaScripts\Core\Model\FacturaCliente;
use FacturaScripts\Core\Model\Asiento;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;

$opts = [
    'ejercicio' => null,
    'dryRun' => false,
];

for ($i = 1; $i < $argc; $i++) {
    switch ($argv[$i]) {
        case '--ejercicio':
            $opts['ejercicio'] = $argv[++$i];
            break;
        case '--dry-run':
            $opts['dryRun'] = true;
            break;
    }
}

if (!$opts['ejercicio']) {
    echo "Error: --ejercicio is required\n";
    exit(1);
}

$ts = fn() => date('Y-m-d H:i:s');

echo "[{$ts()}] === CLEAR ALL for ejercicio {$opts['ejercicio']} ===\n";

function execSql($sql) {
    global $ts;
    $db = new \mysqli(
        FS_DB_HOST ?? 'localhost',
        FS_DB_USER ?? 'root',
        defined('FS_DB_PASS') ? FS_DB_PASS : '',
        FS_DB_NAME ?? 'facturascripts',
        FS_DB_PORT ?? 3306,
        defined('FS_MYSQL_SOCKET') ? FS_MYSQL_SOCKET : ini_get('mysqli.default_socket')
    );
    if ($db->connect_error) {
        echo "[{$ts()}]   DB connection failed: " . $db->connect_error . "\n";
        return 0;
    }
    $result = $db->query($sql);
    $count = ($result === true) ? $db->affected_rows : 0;
    $db->close();
    return $count;
}

function deleteAll($modelClass, $ejercicio) {
    global $opts, $ts;
    $model = new $modelClass();
    $where = [new DataBaseWhere('codejercicio', $ejercicio)];
    $all = $model->all($where, [], 0, 0);
    echo "[{$ts()}   Found: " . count($all) . ' ' . (new \ReflectionClass($modelClass))->getShortName() . "\n";
    $deleted = 0;
    if (!$opts['dryRun']) {
        foreach ($all as $item) {
            $item->editable = true;
            if ($item->delete()) {
                $deleted++;
            } else {
                echo "[{$ts()}]   FAILED to delete id=" . $item->primaryColumnValue() . "\n";
            }
        }
    }
    echo "[{$ts()}   Deleted: $deleted\n";
    return $deleted;
}

echo "[{$ts()}] --- Deleting FacturasProveedor ---\n";
$deletedFP = deleteAll(FacturaProveedor::class, $opts['ejercicio']);

echo "[{$ts()}] --- Deleting FacturasCliente ---\n";
$deletedFC = deleteAll(FacturaCliente::class, $opts['ejercicio']);

echo "[{$ts()}] --- Deleting remaining Asientos ---\n";
$deletedA = deleteAll(Asiento::class, $opts['ejercicio']);

$deletedAFR = 0;
$deletedAF = 0;
if (!$opts['dryRun']) {
    $deletedAFR = execSql("DELETE FROM attached_files_rel WHERE NOT EXISTS ("
        . "SELECT 1 FROM facturasprov WHERE idfactura = attached_files_rel.modelid AND attached_files_rel.model = 'FacturaProveedor'"
        . ") AND NOT EXISTS ("
        . "SELECT 1 FROM facturascli WHERE idfactura = attached_files_rel.modelid AND attached_files_rel.model = 'FacturaCliente'"
        . ") AND NOT EXISTS ("
        . "SELECT 1 FROM asientos WHERE idasiento = attached_files_rel.modelid AND attached_files_rel.model = 'Asiento'"
        . ") AND attached_files_rel.model IN ('FacturaProveedor','FacturaCliente','Asiento')");

    $deletedAF = execSql("DELETE FROM attached_files WHERE idfile NOT IN (SELECT DISTINCT idfile FROM attached_files_rel)");
}
echo "[{$ts()}]   Deleted: $deletedAFR orphan AFR, $deletedAF orphan AF\n";

$total = $deletedFP + $deletedFC + $deletedA + $deletedAFR + $deletedAF;
echo "[{$ts()}] === CLEAR COMPLETE: $deletedFP FP + $deletedFC FC + $deletedA Asientos + $deletedAFR AFR + $deletedAF AF = $total total ===\n";
