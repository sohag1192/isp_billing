<?php
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

// ======== আপনার ISP নাম ========
$company_name = "SWAPON MULTIMEDIA";
$company_email = "billing@swaponmultimedia.com";
$company_phone = "017XXXXXXXX";

// ======== SMS পাঠানোর ফাংশন =========
function send_sms($mobile, $message) {
    // এখানে আপনার SMS API কোড বসাবেন
    // উদাহরণ:
    // file_get_contents("https://sms-api.example.com/send?to={$mobile}&text=" . urlencode($message));
    echo "📩 SMS Sent to {$mobile}: {$message}<br>";
}

// ======== Email পাঠানোর ফাংশন =========
function send_email($to, $subject, $message, $from) {
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: {$from}" . "\r\n";
    mail($to, $subject, $message, $headers);
    echo "📧 Email Sent to {$to}<br>";
}

$month = $_GET['month'] ?? date('m');
$year  = $_GET['year'] ?? date('Y');
$status = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');

// ==================== Generate Monthly Invoices ====================
if (isset($_GET['generate']) && $_GET['generate'] == 1) {
    $check = db()->prepare("SELECT COUNT(*) FROM invoices WHERE MONTH(invoice_date)=? AND YEAR(invoice_date)=?");
    $check->execute([$month, $year]);

    if ($check->fetchColumn() > 0) {
        echo "<p style='color:red;'>❌ এই মাসের ইনভয়েস আগে থেকেই তৈরি আছে!</p>";
    } else {
        $clients = db()->query("SELECT id, name, mobile, email, package_price FROM clients WHERE status='active'")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($clients as $c) {
            $invoice_no = strtoupper("INV".date('ym').$c['id']);
            $stmt = db()->prepare("INSERT INTO invoices (client_id, invoice_number, invoice_date, total_amount, paid_amount, status) VALUES (?, ?, ?, ?, 0, 'unpaid')");
            $stmt->execute([$c['id'], $invoice_no, "$year-$month-01", $c['package_price']]);

            // SMS পাঠানো
            if (!empty($c['mobile'])) {
                $sms_text = "প্রিয় {$c['name']}, {$company_name}-এ আপনার মাসিক বিল {$c['package_price']} টাকা। Invoice: {$invoice_no}। পেমেন্টের জন্য যোগাযোগ: {$company_phone}";
                send_sms($c['mobile'], $sms_text);
            }

            // Email পাঠানো
            if (!empty($c['email'])) {
                $email_subject = "{$company_name} - Monthly Invoice ({$invoice_no})";
                $email_body = "
                    <p>প্রিয় {$c['name']},</p>
                    <p>আপনার মাসিক বিল: <strong>{$c['package_price']} টাকা</strong></p>
                    <p>Invoice No: <strong>{$invoice_no}</strong></p>
                    <p>পেমেন্টের জন্য যোগাযোগ করুন: {$company_phone}</p>
                    <br>
                    <p>ধন্যবাদান্তে,</p>
                    <p><strong>{$company_name}</strong></p>
                ";
                send_email($c['email'], $email_subject, $email_body, "{$company_name} <{$company_email}>");
            }
        }
        echo "<p style='color:green;'>✅ মাসিক ইনভয়েস তৈরি এবং SMS/Email পাঠানো সম্পন্ন!</p>";
    }
}

// ==================== ফিল্টার + রিপোর্ট ====================
$sql = "SELECT i.*, c.name AS client_name, c.mobile
        FROM invoices i
        LEFT JOIN clients c ON i.client_id = c.id
        WHERE MONTH(i.invoice_date) = ? AND YEAR(i.invoice_date) = ?";
$params = [$month, $year];

if ($status != '') {
    $sql .= " AND i.status = ?";
    $params[] = $status;
}

if ($search != '') {
    $sql .= " AND (c.name LIKE ? OR c.mobile LIKE ? OR i.invoice_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY i.invoice_date ASC";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_amount = 0;
$total_paid = 0;
foreach ($invoices as $inv) {
    $total_amount += $inv['total_amount'];
    $total_paid += $inv['paid_amount'];
}
$total_due = $total_amount - $total_paid;
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <title>Monthly Invoice Report</title>
    <style>
        body { font-family: Arial; padding: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        th { background: #f0f0f0; }
        .summary { margin-top: 20px; }
        .summary span { margin-right: 20px; font-weight: bold; }
        .filter-form input, .filter-form select { padding: 5px; }
        .buttons { margin-top: 15px; }
    </style>
</head>
<body>

<h2><?= $company_name ?> - Monthly Invoice Report</h2>

<form method="GET" class="filter-form">
    মাস:
    <select name="month">
        <?php for ($m=1; $m<=12; $m++): ?>
            <option value="<?= sprintf('%02d',$m) ?>" <?= $month == sprintf('%02d',$m) ? 'selected' : '' ?>>
                <?= date('F', mktime(0,0,0,$m,1)) ?>
            </option>
        <?php endfor; ?>
    </select>

    বছর:
    <select name="year">
        <?php for ($y=date('Y')-5; $y<=date('Y'); $y++): ?>
            <option value="<?= $y ?>" <?= $year == $y ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
    </select>

    স্ট্যাটাস:
    <select name="status">
        <option value="">--সব--</option>
        <option value="paid" <?= $status=='paid'?'selected':'' ?>>Paid</option>
        <option value="unpaid" <?= $status=='unpaid'?'selected':'' ?>>Unpaid</option>
        <option value="partial" <?= $status=='partial'?'selected':'' ?>>Partial</option>
    </select>

    সার্চ: <input type="text" name="search" value="<?= htmlspecialchars($search) ?>">

    <button type="submit">ফিল্টার</button>
</form>

<div class="buttons">
    <button onclick="window.location='?month=<?= $month ?>&year=<?= $year ?>&generate=1'">🆕 Generate Monthly Invoices</button>
    <button onclick="window.print()">🖨 Print</button>
    <a href="?month=<?= $month ?>&year=<?= $year ?>&status=<?= $status ?>&search=<?= urlencode($search) ?>&pdf=1" target="_blank">
        📄 Download PDF
    </a>
</div>

<table>
    <tr>
        <th>Invoice No</th>
        <th>তারিখ</th>
        <th>ক্লায়েন্ট</th>
        <th>মোট বিল</th>
        <th>Paid</th>
        <th>বাকি</th>
        <th>Status</th>
    </tr>
    <?php foreach ($invoices as $inv): ?>
        <tr>
            <td><?= $inv['invoice_number'] ?></td>
            <td><?= $inv['invoice_date'] ?></td>
            <td><?= htmlspecialchars($inv['client_name']) ?> (<?= $inv['mobile'] ?>)</td>
            <td><?= number_format($inv['total_amount'], 2) ?></td>
            <td><?= number_format($inv['paid_amount'], 2) ?></td>
            <td><?= number_format($inv['total_amount'] - $inv['paid_amount'], 2) ?></td>
            <td><?= ucfirst($inv['status']) ?></td>
        </tr>
    <?php endforeach; ?>
</table>

<div class="summary">
    <span>মোট বিল: <?= number_format($total_amount, 2) ?></span>
    <span>মোট পেমেন্ট: <?= number_format($total_paid, 2) ?></span>
    <span>বাকি: <?= number_format($total_due, 2) ?></span>
</div>

</body>
</html>
