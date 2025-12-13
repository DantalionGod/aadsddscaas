<?php
/**
 * busca.php - buscador leve em PHP (streaming)
 *
 * Uso (GET):
 *   busca.php?q=client_secret
 *   busca.php?q=client_secret&root=/home/hcredserv/public_html
 *   busca.php?q=client_secret&ext=php,inc,env,txt
 *   busca.php?q=client_secret&max=200&maxsize=5242880
 *   busca.php?q=client_secret&save=1
 */

@ini_set('max_execution_time', '0');
@set_time_limit(0);
@ignore_user_abort(true);
@ini_set('output_buffering', '0');
@ini_set('zlib.output_compression', '0');

function flush_now() {
    echo str_repeat(' ', 2048);
    @ob_flush();
    @flush();
}

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
if ($q === '') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Uso:\n";
    echo "  ?q=client_secret\n";
    echo "  &root=/home/hcredserv/public_html\n";
    echo "  &ext=php,inc,env,txt\n";
    echo "  &max=200 (limite de resultados)\n";
    echo "  &maxsize=5242880 (bytes por arquivo; padrão 5MB)\n";
    echo "  &save=1 (salva em resultado_busca.txt no mesmo dir)\n";
    exit;
}

$root = isset($_GET['root']) ? (string)$_GET['root'] : '/home/hcredserv/public_html';
$root = rtrim($root, "/");

$extParam = isset($_GET['ext']) ? trim((string)$_GET['ext']) : 'php,inc,phtml,phps,env,ini,conf,config,txt,log';
$exts = array_filter(array_map('strtolower', array_map('trim', explode(',', $extParam))));

$maxResults = isset($_GET['max']) ? max(1, (int)$_GET['max']) : 200;
$maxSize = isset($_GET['maxsize']) ? max(1024, (int)$_GET['maxsize']) : 5 * 1024 * 1024; // 5MB padrão
$save = isset($_GET['save']) && $_GET['save'] == '1';

$ignoreDirs = [
    '.git', '.svn', '.hg',
    'node_modules', 'vendor',
    'cache', 'tmp', 'temp',
    'storage', 'logs',
    'sessions', 'session',
    'uploads', 'files', // se quiser varrer /files, remova daqui
];

header('Content-Type: text/plain; charset=utf-8');
echo "[INFO] root = {$root}\n";
echo "[INFO] q    = {$q}\n";
echo "[INFO] exts = " . implode(',', $exts) . "\n";
echo "[INFO] maxResults = {$maxResults}\n";
echo "[INFO] maxSize    = {$maxSize} bytes\n";
echo "[INFO] save       = " . ($save ? "sim" : "não") . "\n\n";
flush_now();

if (!is_dir($root) || !is_readable($root)) {
    echo "[ERRO] root inválido ou sem permissão: {$root}\n";
    exit;
}

$outFile = __DIR__ . "/resultado_busca.txt";
if ($save) {
    @file_put_contents($outFile, "Busca: {$q}\nRoot: {$root}\nExts: " . implode(',', $exts) . "\n\n");
    echo "[INFO] salvando em: {$outFile}\n\n";
    flush_now();
}

$rii = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        function ($current, $key, $iterator) use ($ignoreDirs) {
            if ($current->isDir()) {
                $name = $current->getFilename();
                if (in_array($name, $ignoreDirs, true)) return false;
            }
            return true;
        }
    ),
    RecursiveIteratorIterator::LEAVES_ONLY
);

$scanned = 0;
$found = 0;
$lastStatus = time();

foreach ($rii as $file) {
    /** @var SplFileInfo $file */
    if (!$file->isFile()) continue;
    if (!$file->isReadable()) continue;

    $path = $file->getPathname();

    // filtro por extensão
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, $exts, true)) {
        continue;
    }

    // ignora arquivos muito grandes
    $size = $file->getSize();
    if ($size !== false && $size > $maxSize) {
        continue;
    }

    $scanned++;

    // status a cada 2s
    if (time() - $lastStatus >= 2) {
        echo "[SCAN] arquivos varridos: {$scanned} | encontrados: {$found}\n";
        flush_now();
        $lastStatus = time();
    }

    // leitura por linha (não carrega tudo na memória)
    $handle = @fopen($path, 'rb');
    if (!$handle) continue;

    $lineNo = 0;
    $hitInFile = false;

    while (!feof($handle)) {
        $line = fgets($handle);
        if ($line === false) break;
        $lineNo++;

        // busca simples (case-sensitive). Se quiser ignorar case, use stripos
        if (strpos($line, $q) !== false) {
            $hitInFile = true;

            // recorta a linha pra não poluir demais
            $snippet = trim($line);
            if (strlen($snippet) > 300) {
                $snippet = substr($snippet, 0, 300) . "...";
            }

            $msg = "[HIT] {$path}:{$lineNo} :: {$snippet}\n";
            echo $msg;
            if ($save) @file_put_contents($outFile, $msg, FILE_APPEND);

            $found++;
            flush_now();

            if ($found >= $maxResults) {
                fclose($handle);
                echo "\n[LIMITE] atingiu maxResults={$maxResults}. Parando.\n";
                exit;
            }
        }
    }

    fclose($handle);
}

echo "\n[OK] Finalizado.\n";
echo "[OK] arquivos varridos: {$scanned}\n";
echo "[OK] encontrados: {$found}\n";
if ($save) echo "[OK] arquivo: {$outFile}\n";
