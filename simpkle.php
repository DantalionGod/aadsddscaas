<?php
/**
 * backup_ui_zip_local.php (PHP 7.4)
 *
 * - Rodar dentro do diretório que contém milhares de subpastas
 * - Gera ZIP por lote de subpastas DIRETAS do diretório atual
 * - Mostra progresso em tempo real
 * - Salva o ZIP NO PRÓPRIO DIRETÓRIO (não usa /tmp)
 *
 * URL:
 *   backup_ui_zip_local.php              -> UI
 *   backup_ui_zip_local.php?action=make&start=0&count=3000
 */

@ini_set('max_execution_time', '0');
@set_time_limit(0);
@ignore_user_abort(true);

// MUITO importante para aparecer progresso no navegador:
@ini_set('output_buffering', '0');
@ini_set('zlib.output_compression', '0');

$BASE_DIR = __DIR__;
$EXCLUDE_DIRS = ['.', '..']; // se quiser excluir algum nome específico, adicione aqui (ex.: 'node_modules')

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

function human_bytes($bytes) {
    $units = ['B','KB','MB','GB','TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units)-1) {
        $bytes /= 1024;
        $i++;
    }
    return sprintf('%.2f %s', $bytes, $units[$i]);
}

/**
 * Adiciona recursivamente todos os arquivos de uma pasta ao ZIP.
 * Observação: o zip já "carrega" as pastas implicitamente via caminho dos arquivos,
 * então não precisa contabilizar dirs se você não quiser.
 */
function add_dir_recursive(ZipArchive $zip, string $dirPath, string $baseDir, array &$stats) {
    if (is_link($dirPath)) return;

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dirPath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($it as $item) {
        $path = $item->getPathname();
        if (is_link($path)) continue;

        // caminho relativo dentro do zip
        $localName = ltrim(str_replace($baseDir, '', $path), DIRECTORY_SEPARATOR);

        if ($item->isDir()) {
            // opcional: pode adicionar diretórios vazios explicitamente
            // $zip->addEmptyDir(rtrim($localName, '/') . '/');
            // $stats['dirs']++;
        } else {
            if (@is_readable($path)) {
                $zip->addFile($path, $localName);
                $stats['files']++;
                $size = @filesize($path);
                if ($size !== false) $stats['bytes'] += $size;
            } else {
                $stats['skipped']++;
            }
        }
    }
}

function flush_now() {
    echo str_repeat(' ', 2048);
    @ob_flush();
    @flush();
}

$action = $_GET['action'] ?? 'ui';

/** =========================
 * MAKE: gera zip + progresso
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

    if ($total === 0) {
        echo "Nenhuma subpasta encontrada em: {$BASE_DIR}\n";
        exit;
    }
    if ($start >= $total) {
        echo "Start fora do range. total={$total}\n";
        exit;
    }

    $end   = min($total, $start + $count);     // end é exclusivo
    $batch = array_slice($dirs, $start, $count);

    // ZIP salvo NO PRÓPRIO DIRETÓRIO
    $zipFileName = sprintf("backup_%d_%d.zip", $start, $end - 1);
    $zipPath = $BASE_DIR . DIRECTORY_SEPARATOR . $zipFileName;

    // recomeçar limpo se já existir
    if (file_exists($zipPath)) {
        @unlink($zipPath);
    }

    // HTML + UI
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $self = htmlspecialchars(basename($_SERVER['PHP_SELF']), ENT_QUOTES);

    ?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Gerando backup…</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 24px; }
    .barwrap { width: 100%; max-width: 900px; background: #eee; border-radius: 10px; overflow: hidden; }
    .bar { height: 18px; width: 0%; background: #3b82f6; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    .box { background: #f6f6f6; padding: 12px; border-radius: 10px; max-width: 900px; }
    .ok { color: #166534; }
    .warn { color: #92400e; }
    .muted { color: #555; }
    a { text-decoration: none; }
    button { padding: 8px 12px; cursor:pointer; }
  </style>
</head>
<body>
  <h2>Gerando ZIP do lote…</h2>

  <div class="box">
    <div><b>Base:</b> <span class="mono"><?= htmlspecialchars($BASE_DIR) ?></span></div>
    <div><b>Lote:</b> <span class="mono"><?= (int)$start ?> → <?= (int)($end - 1) ?></span> (<?= (int)count($batch) ?> pastas)</div>
    <div><b>Arquivo ZIP:</b> <span class="mono"><?= htmlspecialchars($zipFileName) ?></span></div>
    <hr>
    <div><b>Pastas processadas:</b> <span class="mono" id="done">0</span>/<span class="mono" id="tot"><?= (int)count($batch) ?></span></div>
    <div><b>Arquivos adicionados:</b> <span class="mono" id="files">0</span></div>
    <div><b>Ignorados (sem permissão):</b> <span class="mono" id="skipped">0</span></div>
    <div><b>Bytes somados (não comprimido):</b> <span class="mono" id="bytes">0</span></div>
    <div class="muted"><b>Pasta atual:</b> <span class="mono" id="current">-</span></div>
  </div>

  <br>
  <div class="barwrap"><div class="bar" id="bar"></div></div>
  <p><b>Progresso:</b> <span class="mono" id="pct">0%</span></p>

  <div id="final" class="box" style="display:none;"></div>

  <script>
    function updateProgress(done, total, currentName, files, skipped, bytes) {
      const pct = total > 0 ? Math.floor((done / total) * 100) : 0;
      document.getElementById('pct').textContent = pct + '%';
      document.getElementById('done').textContent = done;
      document.getElementById('tot').textContent = total;
      document.getElementById('current').textContent = currentName || '-';
      document.getElementById('files').textContent = files;
      document.getElementById('skipped').textContent = skipped;
      document.getElementById('bytes').textContent = bytes;
      document.getElementById('bar').style.width = pct + '%';
    }

    function finish(zipHref, nextHref, sizeText, timeText, note) {
      const f = document.getElementById('final');
      f.style.display = 'block';
      f.innerHTML =
        `<div class="ok"><b>OK!</b> ZIP finalizado e salvo no diretório.</div>
         <div class="mono">Tamanho somado (não comprimido): ${sizeText}</div>
         <div class="mono">Tempo: ${timeText}</div>
         ${note ? `<div class="warn" style="margin-top:8px;">${note}</div>` : ``}
         <hr>
         <div><b>Baixar:</b> <a class="mono" href="${zipHref}" download>${zipHref}</a></div>
         <div style="margin-top:10px;"><b>Próximo lote:</b> <a class="mono" href="${nextHref}">${nextHref}</a></div>`;
    }

    function fail(msg) {
      const f = document.getElementById('final');
      f.style.display = 'block';
      f.innerHTML = `<div class="warn"><b>ERRO:</b> ${msg}</div>`;
    }
  </script>
</body>
</html>
<?php
    // força enviar a UI antes do trabalho pesado
    flush_now();

    $zip = new ZipArchive();
    $ok = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($ok !== true) {
        echo "<script>fail('Não consegui criar o ZIP em: " . htmlspecialchars($zipPath, ENT_QUOTES) . "');</script>";
        flush_now();
        exit;
    }

    $stats = ['files' => 0, 'bytes' => 0, 'skipped' => 0];
    $t0 = microtime(true);

    $totalBatch = count($batch);
    $done = 0;

    foreach ($batch as $dirName) {
        $full = $BASE_DIR . DIRECTORY_SEPARATOR . $dirName;

        // atualiza "pasta atual" antes de entrar (pra você ver onde está)
        $safeName = htmlspecialchars($dirName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo "<script>updateProgress($done,$totalBatch,'$safeName',{$stats['files']},{$stats['skipped']},{$stats['bytes']});</script>\n";
        flush_now();

        if (is_dir($full) && !is_link($full)) {
            add_dir_recursive($zip, $full, $BASE_DIR, $stats);
        }

        $done++;

        // atualiza contadores no fim da pasta
        echo "<script>updateProgress($done,$totalBatch,'$safeName',{$stats['files']},{$stats['skipped']},{$stats['bytes']});</script>\n";
        flush_now();
    }

    // ponto onde costuma "parecer travado": close()
    echo "<script>document.getElementById('current').textContent = 'Finalizando ZIP (close)… pode demorar';</script>\n";
    flush_now();

    $zip->close();

    $t1 = microtime(true);

    $zipHref = htmlspecialchars($zipFileName, ENT_QUOTES);
    $nextStart = $end;
    $nextHref = $self . "?action=make&start={$nextStart}&count={$count}";
    $nextHrefJs = htmlspecialchars($nextHref, ENT_QUOTES);

    $sizeText = htmlspecialchars(human_bytes($stats['bytes']), ENT_QUOTES);
    $timeText = htmlspecialchars(round($t1 - $t0, 2) . "s", ENT_QUOTES);

    $note = "";
    if ($nextStart >= $total) {
        $note = "Último lote concluído. Não há mais pastas a processar.";
        $nextHrefJs = $self; // volta pra UI
    }

    echo "<script>finish('$zipHref','$nextHrefJs','$sizeText','$timeText'," . json_encode($note) . ");</script>";
    flush_now();
    exit;
}

/** =========================
 * UI: tela inicial
 * ========================= */
$dirs  = list_subdirs($BASE_DIR, $EXCLUDE_DIRS);
$total = count($dirs);
$self = htmlspecialchars(basename($_SERVER['PHP_SELF']), ENT_QUOTES);
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
    .box { background: #f6f6f6; padding: 12px; border-radius: 10px; max-width: 900px; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    .muted { color: #555; }
    .small { font-size: 12px; color: #444; }
  </style>
</head>
<body>
  <h2>Backup por partes (ZIP salvo na pasta atual)</h2>

  <div class="box">
    <div><b>Pasta base:</b> <span class="mono"><?= htmlspecialchars($BASE_DIR) ?></span></div>
    <div><b>Total de subpastas diretas encontradas:</b> <span class="mono"><?= (int)$total ?></span></div>
    <div class="muted small">
      Dica: se ficar “travado” em 99%, normalmente é o <span class="mono">ZipArchive->close()</span> finalizando.
      Agora o ZIP fica salvo aqui mesmo, então não perde o arquivo.
    </div>
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
    <button type="submit">Gerar com progresso</button>
  </form>

  <div class="box" style="margin-top:16px;">
    <b>Exemplos:</b>
    <div class="mono" style="margin-top:8px;">
      <?= $self ?>?action=make&start=0&count=3000<br>
      <?= $self ?>?action=make&start=3000&count=3000<br>
      <?= $self ?>?action=make&start=6000&count=3000
    </div>
  </div>

</body>
</html>
