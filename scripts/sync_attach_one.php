<?php
const FS_FOLDER = __DIR__;
require_once __DIR__ . '/vendor/autoload.php';
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}
use FacturaScripts\Core\Model\AttachedFile;
use FacturaScripts\Core\Model\AttachedFileRelation;

$pdfPath = $argv[1] ?? null;
$modelClass = $argv[2] ?? null;
$modelId = (int)($argv[3] ?? 0);

if (!$pdfPath || !$modelClass || !$modelId) {
    echo "Usage: php sync_attach_one.php <pdf_path> <model_class> <model_id>\n";
    exit(1);
}

$filename = basename($pdfPath);
$myFilesDir = FS_FOLDER . '/MyFiles/';
if (!is_dir($myFilesDir)) {
    mkdir($myFilesDir, 0777, true);
}

$destName = $filename;
$destPath = $myFilesDir . $destName;
if (!copy($pdfPath, $destPath)) {
    echo "ERROR: Failed to copy $pdfPath to $destPath\n";
    exit(1);
}

$af = new AttachedFile();
$af->path = $destName;
if (!$af->save()) {
    echo "ERROR saving AttachedFile\n";
    @unlink($destPath);
    exit(1);
}

$afr = new AttachedFileRelation();
$afr->idfile = $af->idfile;
$afr->model = $modelClass;
$afr->modelid = $modelId;
$afr->modelcode = '';
$afr->nick = 'admin';

if (!$afr->save()) {
    echo "ERROR saving relation\n";
    $af->delete();
    @unlink($destPath);
    exit(1);
}

echo "OK: idfile={$af->idfile} path={$af->path} -> {$modelClass}#{$modelId}\n";
