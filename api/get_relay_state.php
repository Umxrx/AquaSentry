<?php
require_once 'db.php';
header('Content-Type: application/json');

$stmt = $pdo->query("SELECT relay_state FROM relay_logs ORDER BY event_time DESC LIMIT 1");
$row = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode(['state' => $row ? $row['relay_state'] : 'OFF']);
?>
