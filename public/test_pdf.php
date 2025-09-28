<?php
// /public/test_pdf.php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$options = new Options();
$options->set('isRemoteEnabled', true);
$options->setChroot($_SERVER['DOCUMENT_ROOT']); // নিরাপত্তার জন্য

$dompdf = new Dompdf($options);

// বাংলা ফন্ট অ্যাড করা
$fontPath = realpath(__DIR__ . '/../assets/fonts/NotoSansBengali-Regular.ttf');
$fontCss = '';
if ($fontPath) {
    $fontCss = "@font-face {
        font-family: 'NotoBengali';
        src: url('file://$fontPath') format('truetype');
    }
    body { font-family: 'NotoBengali', DejaVu Sans, sans-serif; }";
}

// HTML কন্টেন্ট
$html = '
<html>
<head>
<meta charset="utf-8">
<style>
'.$fontCss.'
h1 { color: darkblue; text-align:center; }
p { font-size:14px; }
</style>
</head>
<body>
<h1>বাংলা PDF টেস্ট</h1>
<p>এটি একটি টেস্ট পিডিএফ যেখানে বাংলা লেখা সঠিকভাবে দেখা যাচ্ছে।</p>
<p><b>নাম:</b> হোসাইন আহামেদ</p>
<p><b>কোম্পানি:</b> Discovery Internet</p>
</body>

</html>
';

// Render PDF
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Inline ভিউ (ডাউনলোড না করে ব্রাউজারে ওপেন হবে)
$dompdf->stream('test_bangla.pdf', ['Attachment' => false]);


