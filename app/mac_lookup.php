<?php
/**
 * mac_lookup.php — JSON OUI DB ভিত্তিক MAC → Vendor
 */
function load_oui_db(): array {
    static $db = null;
    if ($db === null) {
        $path = __DIR__ . '/oui_vendors.json';
        if (file_exists($path)) {
            $json = file_get_contents($path);
            $db = json_decode($json, true) ?: [];
        } else {
            $db = [];
        }
    }
    return $db;
}
function mac_vendor_lookup(string $mac): string {
    $clean  = strtoupper(preg_replace('/[^0-9A-F]/', '', $mac));
    if (strlen($clean) < 6) return 'Unknown Vendor';
    $prefix = substr($clean, 0, 6);
    $db = load_oui_db();
    return $db[$prefix] ?? 'Unknown Vendor';
}
