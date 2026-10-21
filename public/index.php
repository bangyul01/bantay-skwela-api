<?php

header("Content-Type: application/json");

$method = $_SERVER["REQUEST_METHOD"];
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);

if ($path === "/" && $method === "GET") {
    http_response_code(200);

    echo json_encode([
        "success" => true,
        "message" => "Bantay Skwela API is running"
    ]);
    exit;
}

if ($path === "/api/test" && $method === "GET") {
    http_response_code(200);

    echo json_encode([
        "success" => true,
        "message" => "API test successful"
    ]);
    exit;
}

http_response_code(404);

echo json_encode([
    "success" => false,
    "message" => "Endpoint not found",
    "path" => $path
]);
