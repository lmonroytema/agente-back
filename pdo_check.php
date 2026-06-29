<?php
try {
    $pdo = new PDO("mysql:host=127.0.0.1;port=3306;charset=utf8mb4", "root", "");
    echo "PDO_OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
