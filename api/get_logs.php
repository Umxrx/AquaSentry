<?php
require_once 'db.php';
header('Content-Type: application/json');

$filters = $_GET['filters'] ?? 'water,ultrasonic,temperature,humidity,relay';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = max(1, intval($_GET['per_page'] ?? 10));

$filterList = array_filter(explode(',', $filters));
$placeholders = implode(',', array_fill(0, count($filterList), '?'));

// Count total rows
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM event_logs WHERE sensor_type IN ($placeholders)");
$countStmt->execute($filterList);
$total = $countStmt->fetchColumn();

// Fetch logs
$offset = ($page - 1) * $perPage;
$dataStmt = $pdo->prepare("SELECT timestamp, message, sensor_type FROM event_logs
                           WHERE sensor_type IN ($placeholders)
                           ORDER BY timestamp DESC
                           LIMIT $perPage OFFSET $offset");
$dataStmt->execute($filterList);
$logs = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

$response = [
    'logs' => $logs,
    'page' => $page,
    'per_page' => $perPage,
    'total' => (int)$total,
    'total_pages' => ceil($total / $perPage)
];

echo json_encode($response);
?>