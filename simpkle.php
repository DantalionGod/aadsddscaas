<?php
/**
 * backup_download_batch.php
 * - Seleciona subpastas diretas do diretório atual
 * - Compacta por lote (ex.: 3000 pastas)
 * - Força download no navegador
 * - Apaga o zip temporário ao finalizar
 *
 * Uso:
 *   backup_download_batch.php?start=0&count=3000
 *   backup_download_batch.php?start=3000&count=3000
 */

@ini_set('max_execution_time', '0');
@set_time_limit(0);
@ignore_user_abort(true);

$BASE_DIR = __DIR__;

function list_subdirs($baseDir) {
    $dirs = [];
    $it = new DirectoryIterator($baseDir);
    foreach ($it as $f) {
        if ($f->isDot()) continue;
        if ($f->isDir()) {
            $name = $f->getFilename();
            // evita zipar diretórios "especiais" se quiser adicionar aqui
            if ($name === 'zips') continue;
            $dirs[] = $name;
        }
    }
    sort($dirs, SORT_NATURAL | SORT_FLAG_CASE);
    return $dirs;
}

function add_dir_recursive(ZipArchive $zip, string $dirPath, string $baseDir) {
    if (is_link($dirPath)) return;

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dirPath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($it as $item) {
        $path = $item->getPathname();
        if (is_link($path)) continue;

        $localName = ltrim(str_replace($baseDir, '', $path), DIRECTORY_SEPARATOR);

        if ($item->isDir()) {
            $zip->addEmptyDir(rtrim($localName, '/') . '/');
        } else {
            if (@is_readable($path)) {
                $zip->addFile($path, $localName);
            }
        }
    }
}

function stream_file_and_delete($filePath, $downloadName) {
    if (!is_file($filePath)) {
        http_response_code(500);
        echo "ERRO: zip temporário não encontrado.\n";
        return;
    }

    // limpa buffers
    while (ob_get_level()) @ob_end_clean();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . addslashes($downloadName) . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $fp = fopen($filePath, 'rb');
    if ($fp === false) {
        http_response_code(500);
        echo "ERRO: não consegui abrir o zip temporário.\n";
        return;
    }

    // envia em chunks pra não estourar memória
    $chunk = 1024 * 1024; // 1MB
    while (!feof($fp)) {
        echo fread($fp, $chunk);
        flush();
    }
    fclose($fp);

    @unlink($filePath); // apaga depois de enviar
}

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    echo "ERRO: ZipArchive não disponível no PHP.\n";
    exit;
}

$dirs  = list_subdirs($BASE_DIR);
$total = count($dirs);

$start = isset($_GET['start']) ? max(0, (int)$_GET['start']) : 0;
$count = isset($_GET['count']) ? max(1, (int)$_GET['count']) : 3000;

if ($total === 0) {
    echo "Nenhuma subpasta encontrada em: {$BASE_DIR}\n";
    exit;
}
if ($start >= $total) {
    echo "Start fora do range. total={$total}\n";
    exit;
}

$end   = min($total, $start + $count);
$batch = array_slice($dirs, $start, $count);

$downloadName = sprintf("backup_%d_%d.zip", $start, $end - 1);

// zip temporário (não fica salvo no seu diretório)
$tmpDir = sys_get_temp_dir();
$tmpZip = tempnam($tmpDir, 'zipbatch_');
if ($tmpZip === false) {
    http_response_code(500);
    echo "ERRO: não consegui criar arquivo temporário em {$tmpDir}\n";
    exit;
}
// ZipArchive precisa de caminho com extensão .zip (em alguns ambientes ajuda)
$tmpZipZip = $tmpZip . '.zip';
@rename($tmpZip, $tmpZipZip);
$tmpZip = $tmpZipZip;

$zip = new ZipArchive();
$ok = $zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
if ($ok !== true) {
    http_response_code(500);
    echo "ERRO: não consegui criar zip temporário.\n";
    exit;
}

// adiciona cada subpasta do lote (recursivo)
foreach ($batch as $dirName) {
    $full = $BASE_DIR . DIRECTORY_SEPARATOR . $dirName;
    if (is_dir($full) && !is_link($full)) {
        add_dir_recursive($zip, $full, $BASE_DIR);
    }
}

$zip->close();

// força download e apaga no final
stream_file_and_delete($tmpZip, $downloadName);
exit;
