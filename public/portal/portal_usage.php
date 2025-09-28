<?php
require_once __DIR__ . '/../../app/portal_require_login.php';
?>
<div class="d-flex">
    <?php include 'portal_sidebar.php'; ?>
    <div class="p-4" style="flex:1;">
        <h2>My Usage</h2>
        <canvas id="usageChart" height="120"></canvas>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const ctx = document.getElementById('usageChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
        datasets: [{
            label: 'Usage (GB)',
            data: [5, 6, 3, 8, 4, 7, 6],
            borderColor: 'blue',
            fill: false
        }]
    }
});
</script>
