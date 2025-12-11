<?php

$host = "databaserds-hcred.ce86bzeduyub.us-east-1.rds.amazonaws.com";
$user = "hcredUsr";
$pass = "Hadnet1324@";
$db   = "hcredDB";

// Conecta
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Erro ao conectar: " . $conn->connect_error);
}

// Nome do arquivo
$arquivo = "backup_" . date("Y-m-d_H-i-s") . ".sql";

// Começa a montar o SQL
$conteudo = "-- Backup gerado automatizado\n";
$conteudo .= "-- Data: " . date("d/m/Y H:i:s") . "\n\n";

// Busca todas as tabelas
$tables = $conn->query("SHOW TABLES");

while ($row = $tables->fetch_array()) {
    $tabela = $row[0];

    // Estrutura
    $res = $conn->query("SHOW CREATE TABLE `$tabela`");
    $row2 = $res->fetch_assoc();
    $conteudo .= "\n\n-- Tabela $tabela\n";
    $conteudo .= "DROP TABLE IF EXISTS `$tabela`;\n";
    $conteudo .= $row2["Create Table"] . ";\n\n";

    // Dados
    $dados = $conn->query("SELECT * FROM `$tabela`");

    while ($d = $dados->fetch_assoc()) {
        $valores = array_map(
            fn($v) => "'" . $conn->real_escape_string($v) . "'",
            $d
        );

        $conteudo .= "INSERT INTO `$tabela` VALUES(" . implode(",", $valores) . ");\n";
    }
}

$conn->close();

// Força download
header("Content-Type: application/sql");
header("Content-Disposition: attachment; filename=\"$arquivo\"");
header("Content-Length: " . strlen($conteudo));

echo $conteudo;
exit;

?>
