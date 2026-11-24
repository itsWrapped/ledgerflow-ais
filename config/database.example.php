
<?php

$host = "localhost";
$dbname = "ledgerflow_local";
$username = "your_username";
$password = "your_password"; // Default XAMPP password is blank

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

$pdo->exec("SET NAMES utf8mb4");

} catch (PDOException $e) {
    error_log($e->getMessage());
    die("Database connection failed. Please check the configuration.");
}
