<?php

require_once 'config.php';
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    } catch (PDOException $e) {
        die("Datubāzes savienojuma kļūda: " . $e->getMessage());
    }

try {
    $host = 'localhost'; // Database host (usually 'localhost')
    $dbname = 'omnivox1'; // Database name
    $username = 'root'; // MySQL username
    $password = ''; // MySQL password

    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}


?>