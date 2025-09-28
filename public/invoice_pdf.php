<?php
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;

$invoice_id = $_GET['id'] ?? 0;

// ইনভয়েস তথ্য আনা
$stmt = db()->prepare("
    SELECT i.*, c.name AS client_name, c.address, c.mobile
    FROM invoices i
    JOIN clients c ON i.client_id = c.id
    WHERE i.id = ?
");
$stmt->execute([$invoice_id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    die("Invoice not found");
}

$stmt_items = db()->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
$stmt_items->execute([$invoice_id]);
$items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

// HTML তৈরি
ob_start();
include __DIR__ . '/invoice_pdf_template.php';
$html = ob_get_clean();

$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream("Invoice-{$invoice['invoice_number']}.pdf", ["Attachment" => true]);
