<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/routeros_api.class.php';

$client_id = intval($_GET['id'] ?? 0);
if (!$client_id) {
    echo "Invalid Client ID";
    exit;
}

// ক্লায়েন্ট + রাউটার ডাটা বের করা
$stmt = db()->prepare("SELECT c.pppoe_id, r.ip AS router_ip, r.username, r.password, r.api_port
                       FROM clients c
                       LEFT JOIN routers r ON c.router_id = r.id
                       WHERE c.id = ?");
$stmt->execute([$client_id]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$client) {
    echo "Client not found";
    exit;
}

// ইন্টারফেস বের করার জন্য MikroTik এ কানেক্ট
$API = new RouterosAPI();
$API->debug = false;
$interface_name = '';

if ($API->connect($client['router_ip'], $client['username'], $client['password'], $client['api_port'])) {
    $active = $API->comm("/ppp/active/print", ["?name" => $client['pppoe_id']]);
    if (!empty($active[0]['interface'])) {
        $interface_name = $active[0]['interface'];
    }
    $API->disconnect();
}

if (!$interface_name) {
    echo "Client is offline or interface not found.";
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Live Traffic Graph</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body style="margin:0; padding:10px; background:#fff;">

<h6 style="text-align:center">Interface: <?= htmlspecialchars($interface_name) ?></h6>
<div style="display:flex; justify-content:space-around; margin-bottom:10px;">
    <div><b>Uptime:</b> <span id="uptime">Loading...</span></div>
    <div><b>Total Download:</b> <span id="total-rx">0 GB</span></div>
    <div><b>Total Upload:</b> <span id="total-tx">0 GB</span></div>
</div>

<canvas id="trafficChart" height="150"></canvas>

<script>
let ctx = document.getElementById('trafficChart').getContext('2d');

let chartData = {
    labels: [],
    datasets: [
        { label: 'Download (Mbps)', borderColor: 'blue', data: [], fill: false },
        { label: 'Upload (Mbps)', borderColor: 'green', data: [], fill: false }
    ]
};

let trafficChart = new Chart(ctx, {
    type: 'line',
    data: chartData,
    options: {
        responsive: true,
        animation: false,
        scales: {
            y: { beginAtZero: true }
        }
    }
});

function fetchTraffic() {
    fetch('traffic_data.php?id=<?= $client_id; ?>&iface=<?= urlencode($interface_name); ?>')
        .then(res => res.json())
        .then(data => {
            let now = new Date().toLocaleTimeString();

            document.getElementById('uptime').innerText = data.uptime;
            document.getElementById('total-rx').innerText = data.total_rx + " GB";
            document.getElementById('total-tx').innerText = data.total_tx + " GB";

            if (chartData.labels.length >= 20) {
                chartData.labels.shift();
                chartData.datasets[0].data.shift();
                chartData.datasets[1].data.shift();
            }

            chartData.labels.push(now);
            chartData.datasets[0].data.push(parseFloat(data.rx));
            chartData.datasets[1].data.push(parseFloat(data.tx));

            trafficChart.update();
        })
        .catch(err => console.error(err));
}

setInterval(fetchTraffic, 2000);
fetchTraffic();
</script>
</body>
</html>
