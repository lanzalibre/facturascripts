<?php

const FS_FOLDER = __DIR__;

require_once __DIR__ . '/vendor/autoload.php';
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

use FacturaScripts\Core\Model\FacturaProveedor;
use FacturaScripts\Core\Model\FacturaCliente;
use FacturaScripts\Core\Model\Proveedor;
use FacturaScripts\Core\Model\Cliente;
use FacturaScripts\Core\Model\Ejercicio;
use FacturaScripts\Core\Model\Empresa;
use FacturaScripts\Core\Model\Almacen;
use FacturaScripts\Core\Model\User;
use FacturaScripts\Core\Model\Serie;
use FacturaScripts\Core\Model\FormaPago;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Base\DataBase;

$opts = [
    'dataDir' => './sync_data',
    'ejercicio' => null,
    'type' => 'both',
    'clearInvoices' => false,
    'dryRun' => false,
    'limit' => 0,
    'output' => null,
];

for ($i = 1; $i < $argc; $i++) {
    switch ($argv[$i]) {
        case '--data-dir':
            $opts['dataDir'] = $argv[++$i];
            break;
        case '--ejercicio':
            $opts['ejercicio'] = $argv[++$i];
            break;
        case '--type':
            $opts['type'] = $argv[++$i];
            break;
        case '--clear-invoices':
            $opts['clearInvoices'] = true;
            break;
        case '--dry-run':
            $opts['dryRun'] = true;
            break;
        case '--limit':
            $opts['limit'] = (int)$argv[++$i];
            break;
        case '--output':
            $opts['output'] = $argv[++$i];
            break;
    }
}

if (!$opts['ejercicio']) {
    echo "Error: --ejercicio is required\n";
    exit(1);
}

if (!in_array($opts['type'], ['purchase', 'sale', 'both'])) {
    echo "Error: --type must be purchase, sale, or both\n";
    exit(1);
}

$dataDir = rtrim($opts['dataDir'], '/');
if (!is_dir($dataDir)) {
    echo "Error: data-dir '$dataDir' not found\n";
    exit(1);
}

echo "=== SYNC CREATE INVOICES ===\n";
echo "Data dir: $dataDir\n";
echo "Ejercicio: {$opts['ejercicio']}\n";
echo "Type: {$opts['type']}\n";
echo "Clear invoices: " . ($opts['clearInvoices'] ? 'yes' : 'no') . "\n";
echo "Dry-run: " . ($opts['dryRun'] ? 'yes' : 'no') . "\n";
echo "\n";

function log_msg($msg) {
    $ts = date('Y-m-d H:i:s');
    echo "[$ts] $msg\n";
}

function log_error($msg) {
    $ts = date('Y-m-d H:i:s');
    echo "[$ts] ERROR: $msg\n";
}

if (!file_exists("$dataDir/matched_entries.json")) {
    log_error("Missing: $dataDir/matched_entries.json");
    exit(1);
}

if (!file_exists("$dataDir/entry_results.json")) {
    log_error("Missing: $dataDir/entry_results.json");
    exit(1);
}

$matched = json_decode(file_get_contents("$dataDir/matched_entries.json"), true);
$entryResults = json_decode(file_get_contents("$dataDir/entry_results.json"), true);

$proveedorCache = [];
$clienteCache = [];

function findOrCreateProveedor($contactName) {
    global $proveedorCache, $opts;

    if (isset($proveedorCache[$contactName])) {
        return $proveedorCache[$contactName];
    }

    $prov = new Proveedor();
    $found = $prov->loadFromCode('', [
        new DataBaseWhere('nombre', mb_strtolower($contactName, 'UTF8'), 'LIKE')
    ]);

    if (!$found) {
        if ($opts['dryRun']) {
            $prov->codproveedor = 'NEW';
            $prov->nombre = $contactName;
            $proveedorCache[$contactName] = $prov;
            return $prov;
        }
        $prov->clear();
        $prov->nombre = $contactName;
        $prov->razonsocial = $contactName;
        $prov->cifnif = '';
        if (!$prov->save()) {
            log_error("Failed to create Proveedor: $contactName");
            return null;
        }
        log_msg("  Created Proveedor: {$prov->codproveedor} - $contactName");
    }

    $proveedorCache[$contactName] = $prov;
    return $prov;
}

function findOrCreateCliente($contactName) {
    global $clienteCache, $opts;

    if (isset($clienteCache[$contactName])) {
        return $clienteCache[$contactName];
    }

    $cli = new Cliente();
    $found = $cli->loadFromCode('', [
        new DataBaseWhere('nombre', mb_strtolower($contactName, 'UTF8'), 'LIKE')
    ]);

    if (!$found) {
        if ($opts['dryRun']) {
            $cli->codcliente = 'NEW';
            $cli->nombre = $contactName;
            $clienteCache[$contactName] = $cli;
            return $cli;
        }
        $cli->clear();
        $cli->nombre = $contactName;
        $cli->razonsocial = $contactName;
        $cli->cifnif = '';
        if (!$cli->save()) {
            log_error("Failed to create Cliente: $contactName");
            return null;
        }
        log_msg("  Created Cliente: {$cli->codcliente} - $contactName");
    }

    $clienteCache[$contactName] = $cli;
    return $cli;
}

function ensureDefaults(&$factura) {
    if (empty($factura->idempresa)) {
        $empresa = new Empresa();
        $empresas = $empresa->all([], ['idempresa' => 'ASC'], 0, 1);
        if (count($empresas) > 0) {
            $factura->idempresa = $empresas[0]->idempresa;
        }
    }

    if (empty($factura->codalmacen)) {
        $almacen = new Almacen();
        $almacenes = $almacen->all([], ['codalmacen' => 'ASC'], 0, 1);
        if (count($almacenes) > 0) {
            $factura->codalmacen = $almacenes[0]->codalmacen;
        }
    }

    if (empty($factura->nick)) {
        $user = new User();
        $users = $user->all([], ['nick' => 'ASC'], 0, 1);
        if (count($users) > 0) {
            $factura->nick = $users[0]->nick;
        }
    }

    if (empty($factura->codserie)) {
        $serie = new Serie();
        $series = $serie->all([], ['codserie' => 'ASC'], 0, 1);
        $factura->codserie = count($series) > 0 ? $series[0]->codserie : 'A';
    }

    if (empty($factura->codpago)) {
        $pago = new FormaPago();
        $pagos = $pago->all([], ['codigo' => 'ASC'], 0, 1);
        $factura->codpago = count($pagos) > 0 ? $pagos[0]->codigo : 'CONT';
    }
}

$created = 0;
$failed = 0;
$skipped = 0;
$processed = 0;
$results = [];

uasort($matched, function ($a, $b) {
    $dateA = $a['doc']['date'] ?? 0;
    $dateB = $b['doc']['date'] ?? 0;
    return $dateA <=> $dateB;
});

foreach ($matched as $entryNum => $entry) {
    if ($opts['limit'] && $created >= $opts['limit']) {
        break;
    }

    $doc = $entry['doc'] ?? null;
    if (!$doc) {
        $skipped++;
        continue;
    }

    $docType = $doc['docType'] ?? '';
    $isPurchase = in_array($docType, ['purchase']);
    $isSale = in_array($docType, ['sale', 'invoice']);
    if ($isPurchase && $opts['type'] === 'sale') {
        $skipped++;
        continue;
    }
    if ($isSale && $opts['type'] === 'purchase') {
        $skipped++;
        continue;
    }
    if (!$isPurchase && !$isSale) {
        $skipped++;
        continue;
    }

    $processed++;
    if ($processed % 50 === 0 || count($matched) <= 50) {
        log_msg("  Progress: $processed/" . count($matched) . " (created=$created, failed=$failed, skipped=$skipped)");
    }

    $contactName = $doc['contactName'] ?? 'Desconocido';
    $total = floatval($doc['total'] ?? 0);
    $rawDate = $doc['date'] ?? null;
    $fecha = (is_int($rawDate) || is_numeric($rawDate)) ? date('Y-m-d', (int)$rawDate) : ($rawDate ?? date('Y-m-d'));
    $desc = $doc['desc'] ?? $doc['docNumber'] ?? ('Entry ' . $entryNum);
    $idasiento = $entryResults[$entryNum] ?? null;

    try {
        if ($docType === 'purchase') {
            $contact = findOrCreateProveedor($contactName);
            if (!$contact) {
                $failed++;
                continue;
            }

            $factura = new FacturaProveedor();
            $factura->clear();
            $factura->skipAccounting = true;
            $factura->setSubject($contact);
            $factura->codproveedor = $contact->codproveedor;
            $factura->nombre = $contact->razonsocial ?? $contact->nombre;
            $factura->cifnif = $contact->cifnif ?? '';
            $factura->fecha = $fecha;
            $factura->codejercicio = $opts['ejercicio'];
            $factura->numproveedor = $doc['docNumber'] ?? '';
            $factura->observaciones = $desc;
            ensureDefaults($factura);

            $tableName = 'facturasprov';
        } else {
            $contact = findOrCreateCliente($contactName);
            if (!$contact) {
                $failed++;
                continue;
            }

            $factura = new FacturaCliente();
            $factura->clear();
            $factura->skipAccounting = true;
            $factura->setSubject($contact);
            $factura->codcliente = $contact->codcliente;
            $factura->nombrecliente = $contact->razonsocial ?? $contact->nombre;
            $factura->cifnif = $contact->cifnif ?? '';
            $factura->fecha = $fecha;
            $factura->codejercicio = $opts['ejercicio'];
            $factura->numero2 = $doc['docNumber'] ?? '';
            $factura->observaciones = $desc;
            ensureDefaults($factura);

            $tableName = 'facturascli';
        }

        $neto = round($total / 1.21, 2);
        $ivaAmount = round($total - $neto, 2);

        if ($opts['dryRun']) {
            log_msg("  [DRY] Invoice: type=$docType, contact=$contactName, neto=$neto, iva=$ivaAmount, total=$total");
            $results[$entryNum] = [
                'idfactura' => 0,
                'idasiento' => $idasiento,
                'docType' => $docType,
            ];
            $created++;
            continue;
        }

        if (!$factura->save()) {
            log_error("  Failed first save for entry $entryNum: $contactName (fecha=$fecha)");
            $failed++;
            continue;
        }

        $linea = $factura->getNewLine();
        $linea->descripcion = $desc;
        $linea->cantidad = 1;
        $linea->pvpunitario = $neto;
        $linea->iva = 21.0;
        $linea->recargo = 0.0;
        $linea->irpf = 0.0;
        $linea->dtopor = 0.0;
        $linea->dtopor2 = 0.0;
        $linea->pvpsindto = $neto;
        $linea->pvptotal = $neto;

        if (!$linea->save()) {
            log_error("  Failed to save line for entry $entryNum");
            $factura->delete();
            $failed++;
            continue;
        }

        $factura->neto = $neto;
        $factura->totaliva = $ivaAmount;
        $factura->totalrecargo = 0.0;
        $factura->totalirpf = 0.0;
        $factura->total = $total;
        $factura->skipAccounting = true;

        if (!$factura->save()) {
            log_error("  Failed second save for entry $entryNum");
            $factura->delete();
            $failed++;
            continue;
        }

        if ($idasiento) {
            $db = new DataBase();
            $db->connect();
            $sql = "UPDATE " . $tableName . " SET idasiento = " . $db->var2str($idasiento)
                . " WHERE idfactura = " . $db->var2str($factura->idfactura) . ";";
            if (!$db->exec($sql)) {
                log_error("  Failed to set idasiento=$idasiento for factura={$factura->idfactura}");
            }
        }

        $results[$entryNum] = [
            'idfactura' => $factura->idfactura,
            'idasiento' => $idasiento,
            'docType' => $docType,
        ];

        $created++;
        log_msg("  Created: idfactura={$factura->idfactura}, $docType, $contactName, total=$total");
    } catch (Exception $e) {
        log_error("  Entry $entryNum: " . $e->getMessage());
        $failed++;
    }
}

$outputFile = $opts['output']
    ? "$dataDir/{$opts['output']}"
    : "$dataDir/invoice_results.json";
file_put_contents($outputFile, json_encode($results, JSON_PRETTY_PRINT));
log_msg("Wrote $outputFile");

log_msg("=== COMPLETE: $created invoices created, $failed failed, $skipped skipped ===");
exit($failed > 0 ? 1 : 0);
