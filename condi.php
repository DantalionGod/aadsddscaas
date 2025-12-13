<?php
// curl-runner.php
@ini_set('display_errors', 1);
@error_reporting(E_ALL);
@set_time_limit(0);

// ====== CONFIG DE SEGURANÇA ======
$ALLOWED_IPS = ['127.0.0.1', '::1']; // adicione seu IP aqui
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';

if (!in_array($clientIp, $ALLOWED_IPS, true)) {
    http_response_code(403);
    exit('Forbidden');
}

// =================================

$output = '';
$error  = '';
$cmd    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cmd = trim($_POST['cmd'] ?? '');

    if ($cmd !== '') {

        // Segurança básica: só permitir comandos que comecem com curl
        if (!preg_match('/^\s*curl\s+/i', $cmd)) {
            $error = "Comando inválido. Apenas curl é permitido.";
        } else {
            // Executa capturando stdout + stderr
            $descriptorSpec = [
                1 => ['pipe', 'w'], // stdout
                2 => ['pipe', 'w'], // stderr
            ];

            $process = proc_open($cmd, $descriptorSpec, $pipes);

            if (is_resource($process)) {
                $output = stream_get_contents($pipes[1]);
                $error  = stream_get_contents($pipes[2]);

                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
            } else {
                $error = "Falha ao executar o comando.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Executar cURL</title>
<style>
body {
    background:#0f1220;
    color:#eaeaf0;
    font-family: monospace;
    padding:20px;
}
textarea {
    width:100%;
    height:220px;
    background:#090b14;
    color:#00ff9c;
    border:1px solid #333;
    padding:10px;
}
button {
    margin-top:10px;
    padding:10px 20px;
    background:#5a5cff;
    color:#fff;
    border:none;
    cursor:pointer;
}
pre {
    background:#090b14;
    border:1px solid #333;
    padding:10px;
    white-space: pre-wrap;
    margin-top:15px;
}
.error {
    color:#ff6b6b;
}
.success {
    color:#00ff9c;
}
</style>
</head>
<body>

<h2>Executar cURL</h2>

<form method="post">
<textarea name="cmd" placeholder="Cole seu comando curl aqui..."><?=htmlspecialchars($cmd)?></textarea>
<br>
<button type="submit">Executar</button>
</form>

<?php if ($output !== ''): ?>
<h3>STDOUT</h3>
<pre class="success"><?=htmlspecialchars($output)?></pre>
<?php endif; ?>

<?php if ($error !== ''): ?>
<h3>STDERR</h3>
<pre class="error"><?=htmlspecialchars($error)?></pre>
<?php endif; ?>

</body>
</html>
