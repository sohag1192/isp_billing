<?php
/**
 * /api/mac_lookup_csv.php
 * Input:
 *   - mac=AA:BB:CC:DD:EE:FF (or q=)
 *   - (optional) csv=https://your-host/path/oui.csv
 *   - (optional) ttl=86400
 * Output: JSON { ok, mac, normalized, vendor, matched_prefix_len, source, cache_info }
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../app/helpers.php';

function jexit(array $a): void { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

$mac = trim($_GET['mac'] ?? $_POST['mac'] ?? $_GET['q'] ?? $_POST['q'] ?? '');
$csv = trim($_GET['csv'] ?? $_POST['csv'] ?? '');  // optional override
$ttl = intval($_GET['ttl'] ?? $_POST['ttl'] ?? 0); // optional override

if ($mac === '') {
    jexit(['ok'=>0, 'error'=>'Missing parameter: mac']);
}

$norm = normalize_mac($mac, ':');

$vendor = vendor_lookup_from_remote_csv($norm, $csv !== '' ? $csv : null, $ttl > 0 ? $ttl : null);
$matchLen = 0;

if ($vendor !== null) {
    // crude way to decide if it was 8 or 6 (check presence in maps again)
    $csvUrl = $csv !== '' ? $csv : (getenv('OUI_CSV_URL') ?: '');
    $ttlVal = $ttl > 0 ? $ttl : intval(getenv('OUI_CSV_TTL') ?: '86400');
    $maps   = load_remote_oui_map_csv($csvUrl, null, $ttlVal);
    $p8     = mac_oui_prefix($norm, 8);
    $p6     = mac_oui_prefix($norm, 6);
    $matchLen = isset($maps['8'][$p8]) ? 8 : (isset($maps['6'][$p6]) ? 6 : 0);
}

jexit([
    'ok'                 => 1,
    'mac'                => $mac,
    'normalized'         => $norm,
    'vendor'             => $vendor ?: 'Unknown Vendor',
    'matched_prefix_len' => $matchLen,
    'source'             => $vendor ? 'remote-csv-cache' : 'fallback',
    'cache_info'         => [
        'ttl_seconds' => $ttl > 0 ? $ttl : intval(getenv('OUI_CSV_TTL') ?: '86400'),
        'csv_url'     => $csv !== '' ? $csv : (getenv('OUI_CSV_URL') ?: '(unset)'),
    ],
]);
