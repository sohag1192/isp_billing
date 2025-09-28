<?php
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Monthly Report</title>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.3.6/css/buttons.dataTables.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.3.6/js/dataTables.buttons.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.html5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<h2>Monthly Report</h2>
<form id="filterForm">
    <label>Month: <input type="month" name="month" required></label>
    <label>Status:
        <select name="status">
            <option value="">All</option>
            <option value="paid">Paid</option>
            <option value="unpaid">Unpaid</option>
            <option value="partial">Partial</option>
        </select>
    </label>
    <button type="submit">Filter</button>
</form>
<hr>
<canvas id="paymentChart" width="400" height="150"></canvas>
<table id="reportTable" class="display" style="width:100%">
    <thead>
        <tr>
            <th>Invoice ID</th>
            <th>Client</th>
            <th>Date</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Method</th>
        </tr>
    </thead>
</table>
<script>
$(document).ready(function(){
    var table = $('#reportTable').DataTable({
        dom: 'Bfrtip',
        buttons: [
            { extend: 'excelHtml5', title: 'Monthly_Report' },
            { extend: 'pdfHtml5', title: 'Monthly_Report' }
        ],
        ajax: {
            url: 'monthly_data.php',
            data: function(d){
                d.month = $('input[name=month]').val();
                d.status = $('select[name=status]').val();
            }
        },
        columns: [
            { data: 'invoice_id' },
            { data: 'client' },
            { data: 'date' },
            { data: 'amount' },
            { data: 'status' },
            { data: 'method' }
        ]
    });
    $('#filterForm').on('submit', function(e){
        e.preventDefault();
        table.ajax.reload();
        loadChart();
    });
    function loadChart(){
        $.get('monthly_data.php', { month: $('input[name=month]').val(), chart: 1 }, function(res){
            var ctx = document.getElementById('paymentChart').getContext('2d');
            new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: res.labels,
                    datasets: [{ data: res.data }]
                }
            });
        }, 'json');
    }
});
</script>
</body>
</html>
