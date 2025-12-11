<?php
// DEBUG: mostrar erros (pode remover depois)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// (Opcional) tenta aumentar memória – se o host deixar
ini_set('memory_limit', '512M');

// CONFIG DO BANCO
$host = "databaserds-hcred.ce86bzeduyub.us-east-1.rds.amazonaws.com";
$db   = "hcredDB";
$user = "hcredUsr";
$pass = "Hadnet1324@";

try {
    // Conecta no MySQL via PDO
    $dsn = "mysql:host=$host;dbname=$db;charset=utf8";
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    // Não conseguimos conectar: mostra erro
    header('Content-Type: text/plain; charset=utf-8');
    die("Erro na conexão com o banco: " . $e->getMessage());
}

// Nome do arquivo que será baixado
$backupName = "backup_" . $db . "_" . date("Y-m-d_H-i-s") . ".sql";

// Headers de download (IMPORTANTE: nenhum echo antes disso)
header("Content-Type: application/sql");
header("Content-Disposition: attachment; filename=\"" . $backupName . "\"");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Início do arquivo SQL (já mandando direto para o navegador)
echo "-- Backup gerado automaticamente\n";
echo "-- Banco: {$db}\n";
echo "-- Data: " . date("d/m/Y H:i:s") . "\n\n";

try {
    // Pega todas as tabelas
    $tablesStmt = $pdo->query("SHOW TABLES");
    $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $tabela) {

        // ESTRUTURA DA TABELA
        $res = $pdo->query("SHOW CREATE TABLE `$tabela`");
        $row = $res->fetch(PDO::FETCH_ASSOC);

        if (isset($row['Create Table'])) {
            $createSql = $row['Create Table'];
        } else {
            $values = array_values($row);
            $createSql = isset($values[1]) ? $values[1] : $values[0];
        }

        echo "\n\n-- -----------------------------------------------------\n";
        echo "-- Estrutura da tabela `$tabela`\n";
        echo "-- -----------------------------------------------------\n";
        echo "DROP TABLE IF EXISTS `$tabela`;\n";
        echo $createSql . ";\n\n";

        // DADOS DA TABELA
        echo "-- Dados da tabela `$tabela`\n";

        $dadosStmt = $pdo->query("SELECT * FROM `$tabela`");
        while ($linha = $dadosStmt->fetch(PDO::FETCH_ASSOC)) {
            $colunas = array();
            $valores = array();

            foreach ($linha as $col => $val) {
                $colunas[] = "`" . $col . "`";

                if ($val === null) {
                    $valores[] = "NULL";
                } else {
                    $valores[] = $pdo->quote($val);
                }
            }

            echo "INSERT INTO `$tabela` (" . implode(", ", $colunas) . ") VALUES (" . implode(", ", $valores) . ");\n";
        }

        // Dá uma enxugada no buffer de saída (ajuda em alguns hosts)
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        flush();
    }
} catch (Exception $e) {
    // Se der erro no meio, ainda aparece no arquivo baixado
    echo "\n\n-- ERRO AO GERAR BACKUP: " . $e->getMessage() . "\n";
}

// Fim do script
exit;
