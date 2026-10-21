
<?php

header("Content-Type: application/json");

$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);

if ($path === "/" || $path === "/api/health") {
    http_response_code(200);

    echo json_encode([
        "success" => true,
        "message" => "Bantay Skwela API is running"
    ]);
} else {
    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "Endpoint not found"
    ]);
}
