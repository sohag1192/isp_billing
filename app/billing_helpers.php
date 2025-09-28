<?php
// /app/billing_helpers.php
// ইনভয়েস টোটাল ক্যালকুলেশন হেল্পার

// optional: if you keep custom math in /app/invoice_calc.php, load it
$calcFile = __DIR__ . '/invoice_calc.php';
if (is_file($calcFile)) {
    require_once $calcFile;
}

/**
 * Compute totals: (amount - discount) + VAT
 * amount, discount, vat_percent are trusted again on server.
 */
if (!function_exists('compute_invoice_total')) {
    function compute_invoice_total(float $amount, float $discount, float $vat_percent): array {
        $amount    = round($amount, 2);
        $discount  = max(0, round($discount, 2));
        $base      = max(0, $amount - $discount);
        $vat_p     = max(0, round($vat_percent, 2));
        $vat       = round($base * ($vat_p / 100), 2);
        $total     = round($base + $vat, 2);
        return [
            'amount'      => $amount,
            'discount'    => $discount,
            'vat_percent' => $vat_p,
            'vat'         => $vat,
            'total'       => $total
        ];
    }
}
