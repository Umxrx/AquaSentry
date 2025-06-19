<?php
date_default_timezone_set('Asia/Kuala_Lumpur');
require_once 'db.php';
header('Content-Type: application/json');

$type  = $_GET['type']  ?? 'water';
$range = $_GET['range'] ?? 'second';
$valid = ['water','ultrasonic'];
if (!in_array($type, $valid)) {
    http_response_code(400);
    echo json_encode(['error'=>'Invalid type']);
    exit;
}

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
    SELECT event_time, distance_cm, leak_status 
      FROM sensor_logs
     WHERE event_time >= :since
     ORDER BY event_time ASC
");
$stmt->execute([':since' => $since]);

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$data = [];
foreach ($rows as $r) {
    $value = $type === 'water'
           ? (float)$r['leak_status']
           : (float)$r['distance_cm'];
    $data[] = [
        'timestamp' => $r['event_time'],
        'value'     => $value
    ];
}

// If no readings, return one point at now with zero:
if (empty($data)) {
    $now = date('Y-m-d H:i:s');
    $data[] = [
        'timestamp' => $now,
        'value'     => 0.0
    ];
}

echo json_encode($data);
