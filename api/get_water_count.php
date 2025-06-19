<?php
require_once 'db.php';
header('Content-Type: application/json');

$stmt = $pdo->query("SELECT COUNT(id) as counts FROM sensor_logs");
$row = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode(['count' => $row ? $row['counts'] : 'UNKNOWN']);
?>
