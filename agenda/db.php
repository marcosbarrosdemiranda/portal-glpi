<?php
/**
 * Conexão PDO com o banco de dados do GLPI
 * Extrai as credenciais do config_db.php sem instanciar a classe GLPI
 */

$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'glpi2';

$pdo = new PDO(
    "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
    $db_user,
    $db_pass,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);
