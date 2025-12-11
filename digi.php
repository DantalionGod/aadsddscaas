<?php
// DEBUG: mostrar erros na tela (temporário)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Testa se a classe PDO existe
if (!class_exists('PDO')) {
    die("ERRO: A classe PDO não está disponível no PHP do servidor (web).");
}

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
    // Se falhar conexão, mostra o erro (por enquanto) em vez de 500 mudo
    die("Erro na conexão com o banco: " . $e->getMessage());
}

// Nome do arquivo que será baixado
$backupName = "backup_" . $db . "_" . date("Y-m-d_H-i-s") . ".sql";

// Começa a montar o conteúdo SQL em memória
$conteudo  = "-- Backup gerado automaticamente\n";
$conteudo .= "-- Banco: $db\n";
$conteudo .= "-- Data: " . date("d/m/Y H:i:s") . "\n\n";

try {
    // Pega todas as tabelas do banco
    $tablesStmt = $pdo->query("SHOW TABLES");
    $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $tabela) {
        // Estrutura da tabela
        $res = $pdo->query("SHOW CREATE TABLE `$tabela`");
        $row = $res->fetch(PDO::FETCH_ASSOC);

        // Alguns MySQL retornam 'Create Table' com a chave diferente, então garantimos assim:
        if (isset($row['Create Table'])) {
            $createSql = $row['Create Table'];
        } else {
            // Pega o segundo valor do array (normalmente é o CREATE)
            $values = array_values($row);
            $createSql = isset($values[1]) ? $values[1] : $values[0];
        }

        $conteudo .= "\n\n-- -----------------------------------------------------\n";
        $conteudo .= "-- Estrutura da tabela `$tabela`\n";
        $conteudo .= "-- -----------------------------------------------------\n";
        $conteudo .= "DROP TABLE IF EXISTS `$tabela`;\n";
        $conteudo .= $createSql . ";\n\n";

        // Dados da tabela
        $conteudo .= "-- Dados da tabela `$tabela`\n";

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

            $conteudo .= "INSERT INTO `$tabela` (" . implode(", ", $colunas) . ") VALUES (" . implode(", ", $valores) . ");\n";
        }
    }
} catch (Exception $e) {
    die("Erro ao gerar backup: " . $e->getMessage());
}

// Agora manda os headers para forçar download
header("Content-Type: application/sql");
header("Content-Disposition: attachment; filename=\"" . $backupName . "\"");
header("Content-Length: " . strlen($conteudo));

// Envia conteúdo pro navegador
echo $conteudo;
exit;
