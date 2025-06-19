<?php
date_default_timezone_set('Asia/Kuala_Lumpur');
require_once 'db.php';

$eventType  = $_POST['event_type'] ?? null;
$distance   = $_POST['distance'] ?? null;
$leak       = $_POST['leak'] ?? null;
$temp       = $_POST['temperature'] ?? null;
$humidity   = $_POST['humidity'] ?? null;
$relayState = $_POST['relay']        ?? null;  // "ON"/"OFF"

// Helper: prevent duplicate log messages
function shouldLog(PDO $pdo, string $sensor, string $msg): bool {
    $stmt = $pdo->prepare("
      SELECT message
      FROM event_logs
      WHERE sensor_type = :sensor
      ORDER BY timestamp DESC
      LIMIT 1
    ");
    $stmt->execute([':sensor'=>$sensor]);
    $last = $stmt->fetchColumn();
    return $last !== $msg;
}

// Helper: insert into event_logs
function insertLog(PDO $pdo, string $sensor, string $msg) {
    $ts = date("Y-m-d H:i:s");
    $stmt = $pdo->prepare("
      INSERT INTO event_logs (timestamp, sensor_type, message)
      VALUES (:ts, :sensor, :msg)
    ");
    $stmt->execute([
      ':ts'     => $ts,
      ':sensor' => $sensor,
      ':msg'    => $msg
    ]);
}

$now = time();

try {
    // Insert to sensor_logs if relevant fields exist
    if ($eventType !== null && $distance !== null && $leak !== null) {
        $now = microtime(true);
        $rounded = round($now * 2) / 2; // Round to nearest 0.5 second
        $roundedDate = date("Y-m-d H:i:s", floor($rounded));

        $stmt = $pdo->prepare("INSERT INTO sensor_logs (event_time, event_type, distance_cm, leak_status)
                               VALUES (:event_time, :event_type, :distance_cm, :leak_status)");
        $stmt->execute([
            ':event_time' => $roundedDate,
            ':event_type' => $eventType,
            ':distance_cm' => $distance,
            ':leak_status' => $leak
        ]);
        
        // 1a) log events for water & ultrasonic
        if ($eventType === 'leak_detected') {
            $msg = "The water is leaking!";
            if (shouldLog($pdo,'water',$msg)) insertLog($pdo,'water',$msg);
        }
        elseif ($eventType === 'waste_alarm') {
            $msg = "There is no presence, please check to close your water.";
            if (shouldLog($pdo,'ultrasonic',$msg)) insertLog($pdo,'ultrasonic',$msg);
        }
        elseif ($eventType === 'presence') {
            $msg = "The water is being used.";
            if (shouldLog($pdo,'ultrasonic',$msg)) insertLog($pdo,'ultrasonic',$msg);
        }
    }

    // Insert to environment_logs if relevant fields exist
    if ($temp !== null && $humidity !== null) {
        $rounded = round($now); // round to nearest second
        $roundedDate = date("Y-m-d H:i:s", $rounded);

        $stmt = $pdo->prepare("INSERT INTO environment_logs (event_time, temperature, humidity)
                               VALUES (:event_time, :temperature, :humidity)");
        $stmt->execute([
            ':event_time' => $roundedDate,
            ':temperature' => $temp,
            ':humidity' => $humidity
        ]);
        
        // 2a) log temp/hum thresholds
        if ($temp > 35) {
            $msg = "The temperature is high!";
            if (shouldLog($pdo,'temperature',$msg)) insertLog($pdo,'temperature',$msg);
        }
        elseif ($temp < 15) {
            $msg = "The temperature is low!";
            if (shouldLog($pdo,'temperature',$msg)) insertLog($pdo,'temperature',$msg);
        }
        if ($humidity < 30) {
            $msg = "The humidity is low!";
            if (shouldLog($pdo,'humidity',$msg)) insertLog($pdo,'humidity',$msg);
        }
        elseif ($humidity > 70) {
            $msg = "The humidity has risen up.";
            if (shouldLog($pdo,'humidity',$msg)) insertLog($pdo,'humidity',$msg);
        }
    }
    
    // Insert to relay_logs if relevant fields exist
    if ($relayState === "ON" || $relayState === "OFF") {
        $ts = date("Y-m-d H:i:s", round(microtime(true)));
        $stmt = $pdo->prepare("
          INSERT INTO relay_logs (event_time, relay_state)
          VALUES (:ts, :state)
        ");
        $stmt->execute([
          ':ts'    => $ts,
          ':state'=> $relayState
        ]);

        // 3a) log relay changes
        $msg = ($relayState==="ON")
             ? "The relay is ON!"
             : "The relay is OFF.";
        if (shouldLog($pdo,'relay',$msg)) insertLog($pdo,'relay',$msg);
    }

    echo "OK";
} catch (Exception $e) {
    http_response_code(500);
    echo "Error: " . $e->getMessage();
}
