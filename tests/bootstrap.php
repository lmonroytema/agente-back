<?php

require __DIR__.'/../vendor/autoload.php';

$host = getenv('TEST_DB_HOST') ?: getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('TEST_DB_PORT') ?: getenv('DB_PORT') ?: '3306';
$database = getenv('TEST_DB_DATABASE') ?: getenv('DB_DATABASE') ?: 'tema_litoclean_app_test';
$username = getenv('TEST_DB_USERNAME') ?: getenv('DB_USERNAME') ?: 'root';
$password = getenv('TEST_DB_PASSWORD');

if ($password === false) {
    $password = getenv('DB_PASSWORD');
}

if ($password === false) {
    $password = '';
}

$safeDatabase = str_replace('`', '', (string) $database);

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};charset=utf8mb4",
        (string) $username,
        (string) $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]
    );

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    putenv('TEST_DB_AVAILABLE=1');
} catch (Throwable $exception) {
    fwrite(STDERR, 'Aviso: no se pudo preparar la base de datos de pruebas MySQL. Se omitiran las pruebas que dependan de la API y la persistencia real. '.$exception->getMessage().PHP_EOL);
    putenv('TEST_DB_AVAILABLE=0');
}
