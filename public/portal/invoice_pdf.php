<?php
require_once __DIR__ . '/../../app/portal_require_login.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../vendor/autoload.php'; // Dompdf or mPDF

use Dompdf\Dompdf;

$id = intval($_GET['id'] ?? 0);
$st = db()->prepare("SELECT * FROM invoices WHERE id = ? AND client_id = ? LIMIT 1");
$st->execute([$id, portal_client_id()]);
$invoice = $st->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    die("Invoice not found.");
}

$items = db()->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
$items->execute([$id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);

// HTML Template for PDF
$html = "
<h2>Invoice #{$invoice['id']}</h2>
<p><strong>Date:</strong> {$invoice['date']}</p>
<p><strong>Status:</strong> {$invoice['status']}</p>
<p><strong>Total:</strong> " . number_format($invoice['total'], 2) . "</p>
<table border='1' cellspacing='0' cellpadding='5' width='100%'>
    <thead>
        <tr>
            <th>Description</th>
            <th>Amount</th>
        </tr>
    </thead>
    <tbody>";
foreach ($items as $item) {
    $html .= "<tr>
                <td>{$item['description']}</td>
                <td>" . number_format($item['amount'], 2) . "</td>
              </tr>";
}
$html .= "</tbody></table>";

// Generate PDF
$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream("invoice_{$invoice['id']}.pdf", ["Attachment" => true]);
