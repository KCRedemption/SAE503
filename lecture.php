<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$scenarios = [
    'sunny',
    'cloudy',
    'static',
    'night',
];

$scenario = $_GET['scenario'] ?? 'sunny';
if (!in_array($scenario, $scenarios, true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Scenario invalide.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$file = __DIR__ . DIRECTORY_SEPARATOR . $scenario . '.json';
$content = @file_get_contents($file);

if ($content === false) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'Fichier de simulation introuvable.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($content, true);
if (!is_array($data)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Donnees JSON invalides.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;
