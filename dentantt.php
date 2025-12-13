<?php
/**
 * backup_ui_zip.php (PHP 7.4)
 * UI + progresso (flush) + download automático
 *
 * - Lista subpastas diretas do diretório atual
 * - Gera zip por lote (ex.: 3000 pastas por parte)
 * - Mostra progresso em tempo real (barra + contadores)
 * - No final, dispara download e apaga o zip (armazenado temporário em /tmp)
 */

@ini_set('max_execution_time', '0');
@set_time_limit(0);
@ignore_user_abort(true);

$BASE_DIR = __DIR__;
$EXCLUDE_DIRS = ['.', '..', 'zips']; // se quiser excluir mais nomes, adicione aqui

function list_subdirs($baseDir, $excludeNames) {
    $dirs = [];
    $it = new DirectoryIterator($baseDir);
    foreach ($it as $f) {
        if ($f->isDot()) continue;
        if ($f->isDir()) {
            $name = $f->getFilename();
            if (in_array($name, $excludeNames, true)) continue;
            $dirs[] = $name;
        }
    }
    sort($dirs, SORT_NATURAL | SORT_FLAG_CASE);
    return $dirs;
}

function add_dir_recursive(ZipArchive $zip, string $dirPath, string $baseDir, array &$stats) {
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
            $stats['dirs']++;
        } else {
            if (@is_readable($path)) {
                $zip->addFile($path, $localName);
                $stats['files']++;
                $size = @filesize($path);
                if ($size !== false) $stats['bytes'] += $size;
            }
        }
    }
}

function human_bytes($bytes) {
    $units = ['B','KB','MB','GB','TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units)-1) {
        $bytes /= 1024;
        $i++;
    }
    return sprintf('%.2f %s', $bytes, $units[$i]);
}

function temp_paths_for_job($jobId) {
    $tmp = sys_get_temp_dir();
    $zipPath  = $tmp . DIRECTORY_SEPARATOR . "zipbatch_{$jobId}.zip";
    $metaPath = $tmp . DIRECTORY_SEPARATOR . "zipbatch_{$jobId}.json";
    return [$zipPath, $metaPath];
}

$action = $_GET['action'] ?? 'ui';

/** =========================
 * DOWNLOAD endpoint
 * ========================= */
if ($action === 'download') {
    $job = preg_replace('/[^a-zA-Z0-9_-]/', '', ($_GET['job'] ?? ''));
    if (!$job) { http_response_code(400); echo "Job inválido.\n"; exit; }

    [$zipPath, $metaPath] = temp_paths_for_job($job);
    if (!is_file($metaPath) || !is_file($zipPath)) {
        http_response_code(404);
        echo "Arquivo não encontrado (talvez já foi baixado/apagado).\n";
        exit;
    }

    $meta = json_decode(@file_get_contents($metaPath), true) ?: [];
    $downloadName = $meta['download_name'] ?? ("backup_{$job}.zip");

    while (ob_get_level()) @ob_end_clean();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . addslashes($downloadName) . '"');
    header('Content-Length: ' . filesize($zipPath));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $fp = fopen($zipPath, 'rb');
    if (!$fp) { http_response_code(500); echo "Falha ao abrir zip.\n"; exit; }

    $chunk = 1024 * 1024; // 1MB
    while (!feof($fp)) {
        echo fread($fp, $chunk);
        flush();
    }
    fclose($fp);

    // apaga ao final
    @unlink($zipPath);
    @unlink($metaPath);
    exit;
}

/** =========================
 * MAKE endpoint (gera zip + mostra progresso + dispara download)
 * ========================= */
if ($action === 'make') {
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        echo "ERRO: ZipArchive não disponível no PHP.\n";
        exit;
    }

    $dirs  = list_subdirs($BASE_DIR, $EXCLUDE_DIRS);
    $total = count($dirs);

    $start = isset($_GET['start']) ? max(0, (int)$_GET['start']) : 0;
    $count = isset($_GET['count']) ? max(1, (int)$_GET['count']) : 3000;

    if ($total === 0) { echo "Nenhuma subpasta encontrada.\n"; exit; }
    if ($start >= $total) { echo "Start fora do range. total={$total}\n"; exit; }

    $end = min($total, $start + $count);
    $batch = array_slice($dirs, $start, $count);

    $jobId = bin2hex(random_bytes(8));
    [$zipPath, $metaPath] = temp_paths_for_job($jobId);

    $downloadName = sprintf("backup_%d_%d.zip", $start, $end - 1);
    @file_put_contents($metaPath, json_encode([
        'created_at' => time(),
        'download_name' => $downloadName,
        'start' => $start,
        'end' => $end - 1,
        'count' => $count,
        'total_dirs' => $total
    ], JSON_PRETTY_PRINT));

    // Cabeçalho HTML (stream + flush)
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    ?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Gerando backup…</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 24px; }
    .barwrap { width: 100%; max-width: 820px; background: #eee; border-radius: 10px; overflow: hidden; }
    .bar { height: 18px; width: 0%; background: #3b82f6; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    .box { background: #f6f6f6; padding: 12px; border-radius: 10px; max-width: 820px; }
    .ok { color: #166534; }
    .warn { color: #92400e; }
    .muted { color: #555; }
    a { text-decoration: none; }
    button { padding: 8px 12px; cursor: pointer; }
  </style>
</head>
<body>
  <h2>Gerando ZIP do lote…</h2>

  <div class="box">
    <div><b>Base:</b> <span class="mono"><?= htmlspecialchars($BASE_DIR) ?></span></div>
    <div><b>Lote:</b> <span class="mono"><?= (int)$start ?> → <?= (int)($end-1) ?></span> (<?= count($batch) ?> pastas)</div>
    <div><b>Job:</b> <span class="mono" id="job"><?= htmlspecialchars($jobId) ?></span></div>
    <div><b>Arquivos:</b> <span class="mono" id="files">0</span> | <b>Dirs:</b> <span class="mono" id="dirs">0</span> | <b>Bytes:</b> <span class="mono" id="bytes">0</span></div>
    <div class="muted"><b>Pasta atual:</b> <span class="mono" id="current">-</span></div>
  </div>

  <br>
  <div class="barwrap"><div class="bar" id="bar"></div></div>
  <p><b>Progresso:</b> <span class="mono" id="pct">0%</span> — <span class="mono" id="done">0</span>/<span class="mono" id="tot"><?= count($batch) ?></span> pastas</p>

  <div id="final" class="box" style="display:none;"></div>

  <iframe id="dl" style="display:none;"></iframe>

  <script>
    function updateProgress(done, total, currentName, files, dirs, bytes) {
      const pct = total > 0 ? Math.floor((done / total) * 100) : 0;
      document.getElementById('pct').textContent = pct + '%';
      document.getElementById('done').textContent = done;
      document.getElementById('tot').textContent = total;
      document.getElementById('current').textContent = currentName || '-';
      document.getElementById('files').textContent = files;
      document.getElementById('dirs').textContent = dirs;
      document.getElementById('bytes').textContent = bytes;
      document.getElementById('bar').style.width = pct + '%';
    }

    function finish(downloadUrl, nextUrl, sizeText, timeText) {
      const f = document.getElementById('final');
      f.style.display = 'block';
      f.innerHTML =
        `<div class="ok"><b>OK!</b> ZIP pronto. Iniciando download automático…</div>
         <div class="mono">Tamanho (não comprimido): ${sizeText}</div>
         <div class="mono">Tempo: ${timeText}</div>
         <br>
         <div><a class="mono" href="${downloadUrl}">Se não baixar, clique aqui</a></div>
         <div style="margin-top:10px;"><a class="mono" href="${nextUrl}">Próximo lote</a></div>`;

      // dispara download sem sair da página
      document.getElementById('dl').src = downloadUrl;
    }
  </script>
</body>
</html>
<?php
    // envia HTML inicial antes do trabalho pesado
    echo str_repeat(' ', 4096);
    flush();

    // cria zip em /tmp
    if (file_exists($zipPath)) @unlink($zipPath);

    $zip = new ZipArchive();
    $ok = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($ok !== true) {
        echo "<script>document.getElementById('final').style.display='block';document.getElementById('final').innerHTML='<span class=\"warn\"><b>ERRO:</b> não consegui criar o zip temporário.</span>';</script>";
        flush();
        exit;
    }

    $stats = ['files' => 0, 'dirs' => 0, 'bytes' => 0];
    $t0 = microtime(true);
    $totalBatch = count($batch);
    $done = 0;

    foreach ($batch as $dirName) {
        $full = $BASE_DIR . DIRECTORY_SEPARATOR . $dirName;
        if (is_dir($full) && !is_link($full)) {
            add_dir_recursive($zip, $full, $BASE_DIR, $stats);
        }
        $done++;

        // atualiza UI via script + flush
        $safeName = htmlspecialchars($dirName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo "<script>updateProgress($done,$totalBatch,'$safeName',{$stats['files']},{$stats['dirs']},{$stats['bytes']});</script>\n";
        echo str_repeat(' ', 1024);
        flush();
    }

    $zip->close();
    $t1 = microtime(true);

    // links
    $downloadUrl = htmlspecialchars($_SERVER['PHP_SELF'] . "?action=download&job={$jobId}", ENT_QUOTES);
    $nextStart = $end;
    $nextUrl = $_SERVER['PHP_SELF'] . "?action=make&start={$nextStart}&count={$count}";
    $nextUrl = htmlspecialchars($nextUrl, ENT_QUOTES);

    $sizeText = htmlspecialchars(human_bytes($stats['bytes']), ENT_QUOTES);
    $timeText = htmlspecialchars(round($t1 - $t0, 2) . "s", ENT_QUOTES);

    echo "<script>finish('$downloadUrl','$nextUrl','$sizeText','$timeText');</script>";
    flush();
    exit;
}

/** =========================
 * UI endpoint (página inicial)
 * ========================= */
$dirs  = list_subdirs($BASE_DIR, $EXCLUDE_DIRS);
$total = count($dirs);
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Backup por partes</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 24px; }
    input { padding: 8px; width: 260px; max-width: 100%; }
    button { padding: 10px 14px; cursor: pointer; }
    .row { margin: 10px 0; }
    .box { background: #f6f6f6; padding: 12px; border-radius: 10px; max-width: 820px; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    .muted { color: #555; }
  </style>
</head>
<body>
  <h2>Backup por partes (ZIP + download automático)</h2>

  <div class="box">
    <div><b>Pasta base:</b> <span class="mono"><?= htmlspecialchars($BASE_DIR) ?></span></div>
    <div><b>Total de subpastas diretas:</b> <span class="mono"><?= (int)$total ?></span></div>
    <div class="muted">Ele vai zipar cada subpasta do lote recursivamente (tudo dentro).</div>
  </div>

  <form method="get" class="box" style="margin-top:16px;">
    <input type="hidden" name="action" value="make">
    <div class="row">
      <label><b>Quantas pastas por parte (count)</b></label><br>
      <input name="count" value="<?= isset($_GET['count']) ? (int)$_GET['count'] : 3000 ?>">
    </div>
    <div class="row">
      <label><b>Índice inicial (start)</b></label><br>
      <input name="start" value="<?= isset($_GET['start']) ? (int)$_GET['start'] : 0 ?>">
    </div>
    <button type="submit">Gerar com progresso e baixar</button>
  </form>

  <div class="box" style="margin-top:16px;">
    <b>Exemplos rápidos:</b>
    <div class="mono" style="margin-top:8px;">
      <?= htmlspecialchars(basename($_SERVER['PHP_SELF'])) ?>?action=make&start=0&count=3000<br>
      <?= htmlspecialchars(basename($_SERVER['PHP_SELF'])) ?>?action=make&start=3000&count=3000<br>
      <?= htmlspecialchars(basename($_SERVER['PHP_SELF'])) ?>?action=make&start=6000&count=3000
    </div>
  </div>
</body>
</html>
