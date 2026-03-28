<?php
/**
 * Fast Holded → FacturaScripts sync via PHP CLI
 * Direct model usage (no MCP overhead)
 *
 * Usage:
 *   php sync_upload_fast.php --data-dir sync_data --ejercicio 2024 [--start-date YYYY-MM-DD] [--end-date YYYY-MM-DD] [--clear] [--dry-run] [--limit N]
 */

const FS_FOLDER = __DIR__;

require_once __DIR__ . '/vendor/autoload.php';
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

use FacturaScripts\Core\Model\Asiento;
use FacturaScripts\Core\Model\Partida;
use FacturaScripts\Core\Model\Subcuenta;
use FacturaScripts\Core\Model\Cuenta;
use FacturaScripts\Core\Model\Ejercicio;
use FacturaScripts\Core\Model\AttachedFile;
use FacturaScripts\Core\Model\AttachedFileRelation;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;

$opts = [
    'dataDir' => './sync_data',
    'ejercicio' => null,
    'clear' => false,
    'dryRun' => false,
    'limit' => 0,
    'startDate' => null,
    'endDate' => null,
];

for ($i = 1; $i < $argc; $i++) {
    switch ($argv[$i]) {
        case '--data-dir':
            $opts['dataDir'] = $argv[++$i];
            break;
        case '--ejercicio':
            $opts['ejercicio'] = $argv[++$i];
            break;
        case '--clear':
            $opts['clear'] = true;
            break;
        case '--dry-run':
            $opts['dryRun'] = true;
            break;
        case '--limit':
            $opts['limit'] = (int)$argv[++$i];
            break;
        case '--start-date':
            $opts['startDate'] = $argv[++$i];
            break;
        case '--end-date':
            $opts['endDate'] = $argv[++$i];
            break;
    }
}

if (!$opts['ejercicio']) {
    echo "Error: --ejercicio is required\n";
    exit(1);
}

$dataDir = rtrim($opts['dataDir'], '/');
if (!is_dir($dataDir)) {
    echo "Error: data-dir '$dataDir' not found\n";
    exit(1);
}

if ($opts['startDate']) {
    $startTs = strtotime($opts['startDate'] . ' 00:00:00');
    if ($startTs === false) {
        echo "Error: invalid --start-date format\n";
        exit(1);
    }
}
if ($opts['endDate']) {
    $endTs = strtotime($opts['endDate'] . ' 23:59:59');
    if ($endTs === false) {
        echo "Error: invalid --end-date format\n";
        exit(1);
    }
}

$myFilesDir = FS_FOLDER . '/MyFiles';
if (!is_dir($myFilesDir)) {
    mkdir($myFilesDir, 0755, true);
}

echo "=== SYNC UPLOAD (PHP) ===\n";
echo "Data dir: $dataDir\n";
echo "Ejercicio: {$opts['ejercicio']}\n";
echo "Clear: " . ($opts['clear'] ? 'yes' : 'no') . "\n";
echo "Dry-run: " . ($opts['dryRun'] ? 'yes' : 'no') . "\n";
if ($opts['startDate']) {
    echo "Start date: {$opts['startDate']}\n";
}
if ($opts['endDate']) {
    echo "End date: {$opts['endDate']}\n";
}
echo "\n";

function log_msg($msg) {
    $ts = date('Y-m-d H:i:s');
    echo "[$ts] $msg\n";
}

function log_error($msg) {
    $ts = date('Y-m-d H:i:s');
    echo "[$ts] ERROR: $msg\n";
}

$subcuentaCache = [];

function ensure_subcuenta($code, $ejercicio, $desc) {
    global $subcuentaCache, $opts;

    $key = "$ejercicio:$code";
    if (isset($subcuentaCache[$key])) {
        return;
    }

    $sub = new Subcuenta();
    if ($sub->loadFromCode('', [
        new DataBaseWhere('codsubcuenta', $code),
        new DataBaseWhere('codejercicio', $ejercicio)
    ])) {
        $subcuentaCache[$key] = true;
        return;
    }

    $codcuenta = substr($code, 0, 3);
    $cuenta = new Cuenta();
    if (!$cuenta->loadFromCode('', [
        new DataBaseWhere('codcuenta', $codcuenta),
        new DataBaseWhere('codejercicio', $ejercicio)
    ])) {
        if (!$opts['dryRun']) {
            $cuenta->codejercicio = $ejercicio;
            $cuenta->codcuenta = $codcuenta;
            $cuenta->descripcion = $desc ?? $code;
            $cuenta->save();
        }
    }

    if (!$opts['dryRun']) {
        $sub->codejercicio = $ejercicio;
        $sub->codsubcuenta = $code;
        $sub->descripcion = $desc ?? $code;
        $sub->codcuenta = $codcuenta;
        if (isset($cuenta->idcuenta)) {
            $sub->idcuenta = $cuenta->idcuenta;
        }
        $sub->save();
    }

    $subcuentaCache[$key] = true;
}

function attach_pdf($pdfPath, $filename, $modelid, $model) {
    global $opts;

    if (!file_exists($pdfPath)) {
        log_error("PDF file not found: $pdfPath");
        return;
    }

    if ($opts['dryRun']) {
        log_msg("  [DRY] Would attach PDF: $filename to $model id=$modelid");
        return;
    }

    $dest = FS_FOLDER . '/MyFiles/' . basename($filename);
    if (!file_exists($dest)) {
        if (!@copy($pdfPath, $dest)) {
            log_error("Failed to copy PDF: $pdfPath → $dest");
            return;
        }
    }

    $af = new AttachedFile();
    $af->path = 'MyFiles/' . basename($filename);
    $af->filename = basename($filename);
    $af->mimetype = 'application/pdf';
    $af->size = filesize($pdfPath);
    if (!$af->save()) {
        log_error("Failed to save AttachedFile: {$af->filename}");
        return;
    }

    $rel = new AttachedFileRelation();
    $rel->idfile = $af->idfile;
    $rel->model = $model;
    $rel->modelid = $modelid;
    if (!$rel->save()) {
        log_error("Failed to create AttachedFileRelation");
        return;
    }

    log_msg("  PDF attached: {$af->idfile} → $model id=$modelid");
}

log_msg("--- Ensure ejercicio exists ---");
$ej = new Ejercicio();
if (!$ej->loadFromCode($opts['ejercicio'])) {
    if (!$opts['dryRun']) {
        $ej->codejercicio = $opts['ejercicio'];
        $ej->nombre = 'Ejercicio ' . $opts['ejercicio'];
        $ej->fechainicio = $opts['ejercicio'] . '-01-01';
        $ej->fechafin = $opts['ejercicio'] . '-12-31';
        $ej->estado = 'A';
        if (!$ej->save()) {
            log_error("Failed to create ejercicio {$opts['ejercicio']}");
            exit(1);
        }
    }
    log_msg("Created ejercicio {$opts['ejercicio']}");
} else {
    log_msg("Ejercicio {$opts['ejercicio']} exists");
}

if ($opts['clear']) {
    log_msg("--- Clearing existing data ---");

    if (!$opts['dryRun']) {
        $asiento = new Asiento();
        $where = [new DataBaseWhere('codejercicio', $opts['ejercicio'])];
        $count = 0;
        foreach ($asiento->all($where, [], 0, 0) as $a) {
            if ($a->delete()) {
                $count++;
            }
        }
        log_msg("  Deleted $count Asientos");
    } else {
        log_msg("  [DRY] Would delete all Asientos");
    }
}

if (!file_exists("$dataDir/ledger_lines.json")) {
    log_error("Missing: $dataDir/ledger_lines.json");
    exit(1);
}

log_msg("--- Loading ledger_lines.json ---");
$ledgerLines = json_decode(file_get_contents("$dataDir/ledger_lines.json"), true);
if (!is_array($ledgerLines)) {
    log_error("Failed to parse ledger_lines.json");
    exit(1);
}

$entries = [];
foreach ($ledgerLines as $line) {
    $entryNum = $line['entryNumber'];
    if (!isset($entries[$entryNum])) {
        $entries[$entryNum] = [];
    }
    $entries[$entryNum][] = $line;
}
log_msg("  " . count($entries) . " entries from " . count($ledgerLines) . " lines");

$matchedEntries = null;
if (file_exists("$dataDir/matched_entries.json")) {
    log_msg("--- Loading matched_entries.json for enrichment ---");
    $matchedEntries = json_decode(file_get_contents("$dataDir/matched_entries.json"), true);
    if (!is_array($matchedEntries)) {
        $matchedEntries = null;
        log_error("  Failed to parse matched_entries.json, skipping enrichment");
    } else {
        log_msg("  " . count($matchedEntries) . " matched entries loaded");
    }
}

if ($opts['startDate'] || $opts['endDate']) {
    $beforeFilter = count($entries);
    foreach ($entries as $entryNum => $lines) {
        $ts = $lines[0]['timestamp'] ?? 0;
        if (isset($startTs) && $ts < $startTs) {
            unset($entries[$entryNum]);
            continue;
        }
        if (isset($endTs) && $ts > $endTs) {
            unset($entries[$entryNum]);
            continue;
        }
    }
    log_msg("  Date filter: " . count($entries) . " entries remaining (was $beforeFilter)");
}

$created = 0;
$failed = 0;
$processed = 0;
$entryResults = [];
$total = count($entries);

log_msg("--- Processing $total entries ---");

foreach ($entries as $entryNum => $lines) {
    if ($opts['limit'] && $created >= $opts['limit']) {
        break;
    }

    $processed++;
    if ($processed % 50 === 0 || $total <= 50) {
        log_msg("  Progress: $processed/$total (created=$created, failed=$failed)");
    }

    try {
        foreach ($lines as $line) {
            $code = str_pad(strval($line['account']), 10, '0');
            ensure_subcuenta($code, $opts['ejercicio'], $line['description'] ?? $code);
        }

        $partidas = [];
        foreach ($lines as $line) {
            $debit = floatval($line['debit'] ?? 0);
            $credit = floatval($line['credit'] ?? 0);
            if ($debit == 0 && $credit == 0) {
                continue;
            }
            $partidas[] = [
                'account' => str_pad(strval($line['account']), 10, '0'),
                'debit' => $debit,
                'credit' => $credit,
                'description' => empty($line['description']) ? '-' : $line['description']
            ];
        }

        if (empty($partidas)) {
            continue;
        }

        $timestamp = $lines[0]['timestamp'] ?? time();
        $fecha = date('Y-m-d', $timestamp);

        if ($matchedEntries !== null && isset($matchedEntries[$entryNum])) {
            $doc = $matchedEntries[$entryNum]['doc'] ?? null;
            if ($doc) {
                $concepto = ($doc['docType'] ?? '') . ' ' .
                            ($doc['docNumber'] ?? $doc['id'] ?? '') . ' - ' .
                            ($doc['contactName'] ?? '');
            } else {
                $firstDesc = empty($lines[0]['description']) ? '-' : $lines[0]['description'];
                $concepto = 'Holded entry ' . $entryNum . ' (' . $firstDesc . ')';
            }
        } else {
            $firstDesc = empty($lines[0]['description']) ? '-' : $lines[0]['description'];
            $concepto = 'Holded entry ' . $entryNum . ' (' . $firstDesc . ')';
        }

        if ($opts['dryRun']) {
            log_msg("  [DRY] Asiento #$entryNum: fecha=$fecha, concepto='" . substr($concepto, 0, 60) . "' (" . count($partidas) . " lines)");
            $entryResults[$entryNum] = null;
            $created++;
        } else {
            $asiento = new Asiento();
            $asiento->codejercicio = $opts['ejercicio'];
            $asiento->fecha = $fecha;
            $asiento->concepto = $concepto;

            if (!$asiento->save()) {
                log_error("  Failed to save Asiento #$entryNum: $concepto");
                $failed++;
                continue;
            }

            $totalHaber = 0;
            foreach ($partidas as $p) {
                $partida = new Partida();
                $partida->idasiento = $asiento->idasiento;
                $partida->codsubcuenta = $p['account'];
                $partida->debe = $p['debit'];
                $partida->haber = $p['credit'];
                $partida->concepto = $p['description'];
                $partida->codejercicio = $opts['ejercicio'];
                $partida->disableAdditionalTest(true);
                if (!$partida->save()) {
                    log_error("  Failed to save Partida for Asiento {$asiento->idasiento} (entry #$entryNum)");
                    $failed++;
                    continue 2;
                }
                $totalHaber += $p['credit'];
            }

            $asiento->importe = round($totalHaber, 2);
            $asiento->save();

            $entryResults[$entryNum] = $asiento->idasiento;
            $created++;
        }
    } catch (Exception $e) {
        log_error("  Entry #$entryNum: " . $e->getMessage());
        $failed++;
    }
}

$resultsPath = "$dataDir/entry_results.json";
file_put_contents($resultsPath, json_encode($entryResults, JSON_PRETTY_PRINT));
log_msg("  Wrote " . count($entryResults) . " mappings to entry_results.json");

log_msg("=== SYNC COMPLETE: $created asientos created, $failed failed ===");
exit($failed > 0 ? 1 : 0);
