<?php
require_once __DIR__ . '/../app/db.php';

// সব ক্লায়েন্ট আইডি লোড করো
$stmt = db()->query("SELECT id FROM clients");
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($clients as $client) {
    $client_id = $client['id'];

    // API থেকে লাইভ ডাটা আনা
    $json = file_get_contents("http://yourdomain.com/api/client_live_status.php?id=" . $client_id);
    $data = json_decode($json, true);

    if (!empty($data) && isset($data['rx_speed'])) {
        $st = db()->prepare("INSERT INTO client_traffic_log 
            (client_id, log_time, rx_speed, tx_speed, total_download_gb, total_upload_gb)
            VALUES (?, NOW(), ?, ?, ?, ?)");
        $st->execute([
            $client_id,
            intval($data['rx_speed']),
            intval($data['tx_speed']),
            floatval($data['total_download_gb']),
            floatval($data['total_upload_gb'])
        ]);
        echo "Saved log for client {$client_id}\n";
    } else {
        echo "Failed to get data for client {$client_id}\n";
    }
}
