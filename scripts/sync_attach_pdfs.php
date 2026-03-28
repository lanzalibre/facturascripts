<?php

const FS_FOLDER = __DIR__;

require_once __DIR__ . '/vendor/autoload.php';
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

use FacturaScripts\Core\Model\AttachedFile;
use FacturaScripts\Core\Model\AttachedFileRelation;

$pdfDir = './sync_data/pdfs';
$entryResultsPath = null;
$invoiceResultsPath = null;

for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--pdf-dir') {
        $pdfDir = $argv[++$i];
    } elseif ($argv[$i] === '--entry-results') {
        $entryResultsPath = $argv[++$i];
    } elseif ($argv[$i] === '--invoice-results') {
        $invoiceResultsPath = $argv[++$i];
    }
}

$input = file_get_contents('php://stdin');
$mapping = json_decode($input, true);
if (!is_array($mapping) || empty($mapping)) {
    echo json_encode(['error' => 'No mapping provided or invalid JSON']);
    exit(1);
}

$entryResults = [];
if ($entryResultsPath && file_exists($entryResultsPath)) {
    $entryResults = json_decode(file_get_contents($entryResultsPath), true) ?: [];
}

$invoiceResults = [];
if ($invoiceResultsPath && file_exists($invoiceResultsPath)) {
    $invoiceResults = json_decode(file_get_contents($invoiceResultsPath), true) ?: [];
}

$results = [];
$attached = 0;
$failed = 0;
$myFilesDir = FS_FOLDER . '/MyFiles/';

foreach ($mapping as $entryNumber => $pdfFilename) {
    $safeFilename = trim($pdfFilename);
    $safeFilename = str_replace('#', '_', $safeFilename);
    $safeFilename = preg_replace('/[^\w\.\-]/', '_', $safeFilename);

    $pdfPath = rtrim($pdfDir, '/') . '/' . $safeFilename;
    if (!file_exists($pdfPath)) {
        $pdfPath = rtrim($pdfDir, '/') . '/' . $pdfFilename;
    }
    if (!file_exists($pdfPath)) {
        $results[] = ['entryNumber' => $entryNumber, 'error' => "PDF not found: $pdfPath (original: $pdfFilename)"];
        $failed++;
        continue;
    }

    $destName = $safeFilename;
    if (file_exists($myFilesDir . $destName)) {
        $destName = mt_rand(1, 999999) . '_' . $safeFilename;
    }
    $destPath = $myFilesDir . $destName;

    if (!copy($pdfPath, $destPath)) {
        $results[] = ['entryNumber' => $entryNumber, 'error' => 'Failed to copy PDF'];
        $failed++;
        continue;
    }

    $af = new AttachedFile();
    $af->path = $destName;
    if (!$af->save()) {
        @unlink($destPath);
        $results[] = ['entryNumber' => $entryNumber, 'error' => 'Failed to save AttachedFile'];
        $failed++;
        continue;
    }

    $idasiento = $entryResults[$entryNumber] ?? null;

    if ($idasiento) {
        $rel = new AttachedFileRelation();
        $rel->idfile = $af->idfile;
        $rel->model = 'Asiento';
        $rel->modelid = (int)$idasiento;
        $rel->nick = 'admin';
        $rel->save();
    }

    $invInfo = $invoiceResults[$entryNumber] ?? null;

    if ($invInfo && !empty($invInfo['idfactura'])) {
        $model = ($invInfo['docType'] ?? '') === 'cliente' ? 'FacturaCliente' : 'FacturaProveedor';
        $rel2 = new AttachedFileRelation();
        $rel2->idfile = $af->idfile;
        $rel2->model = $model;
        $rel2->modelid = (int)$invInfo['idfactura'];
        $rel2->nick = 'admin';
        $rel2->save();
    }

    $results[] = [
        'entryNumber' => $entryNumber,
        'idfile' => $af->idfile,
        'filename' => $pdfFilename,
        'idasiento' => $idasiento ? (int)$idasiento : null,
        'idfactura' => $invInfo['idfactura'] ?? null,
    ];
    $attached++;
}

echo json_encode([
    'attached' => $attached,
    'failed' => $failed,
    'results' => $results,
]);
