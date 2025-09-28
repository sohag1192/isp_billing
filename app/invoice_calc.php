<?php
// /app/billing_helpers.php
// ইনভয়েস টোটাল ক্যালকুলেশন (আপনার invoice_calc.php থাকলে সেটাই ইউজ করবে)

require_once __DIR__ . '/invoice_calc.php'; // keep this; if missing, add a simple calc below

if (!function_exists('compute_invoice_total')) {
    function compute_invoice_total(float $amount, float $discount, float $vat_percent): array {
        // amount - discount + vat
        $base = max(0, $amount - $discount);
        $vat  = round($base * ($vat_percent/100), 2);
        $total= round($base + $vat, 2);
        return ['base'=>$amount, 'discount'=>$discount, 'vat_percent'=>$vat_percent, 'vat'=>$vat, 'total'=>$total];
    }
}
