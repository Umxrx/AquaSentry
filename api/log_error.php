<?php
// log_error.php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    $msg = $data['message'] ?? 'Unknown error';
    $url = $data['url'] ?? 'N/A';
    $line = $data['line'] ?? 'N/A';
    $time = date('Y-m-d H:i:s');

    $log = "[$time] $msg at $url:$line" . PHP_EOL;

    file_put_contents(__DIR__ . '/error_log.txt', $log, FILE_APPEND);
    echo json_encode(['status' => 'ok']);
} else {
    http_response_code(405);
    echo 'Method not allowed';
}
