<?php

$host = getenv("DB_HOST");
$port = getenv("DB_PORT") ?: "5432";
$dbname = getenv("DB_NAME") ?: "postgres";
$username = getenv("DB_USER");
$password = getenv("DB_PASS");

try {
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());

    http_response_code(500);

    die(json_encode([
        "success" => false,
        "message" => "Database connection failed"
    ]));
}
