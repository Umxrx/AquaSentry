<?php
date_default_timezone_set('Asia/Kuala_Lumpur');
require_once 'db.php';
header('Content-Type: application/json');

$range = $_GET['range'] ?? 'second';
switch ($range) {
    case 'second': $since = date('Y-m-d H:i:s', strtotime('-1 minute')); break;
    case 'minute':   $since = date('Y-m-d H:i:s', strtotime('-1 hour'));   break;
    case 'hour':    $since = date('Y-m-d H:i:s', strtotime('-1 day'));    break;
    case 'daily':   $since = date('Y-m-d H:i:s', strtotime('-1 week'));   break;
    case 'weekly':  $since = date('Y-m-d H:i:s', strtotime('-1 month'));  break;
    case 'monthly':   $since = date('Y-m-d H:i:s', strtotime('-1 year'));   break;
    default:
        http_response_code(400);
        echo json_encode(['error'=>'Invalid range']);
        exit;
}

$stmt = $pdo->prepare("
    SELECT event_time, temperature, humidity
      FROM environment_logs
     WHERE event_time >= :since
     ORDER BY event_time ASC
");
$stmt->execute([':since' => $since]);

$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// If no readings, return one point at now with zeros:
if (empty($data)) {
    $now = date('Y-m-d H:i:s');
    $data[] = [
        'timestamp'   => $now,
        'temperature' => 0.0,
        'humidity'    => 0.0
    ];
}

echo json_encode($data);