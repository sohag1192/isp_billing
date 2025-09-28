<?php
/**
 * /api/mac_vendor.php
 * Single-file MAC→Vendor resolver (CSV-based) with strong path resolution,
 * file/HTTP fetch, gz-JSON cache, debug & self-test.
 *
 * Usage:
 *   /api/mac_vendor.php?mac=A4:5E:60:12:34:56
 *   /api/mac_vendor.php?mac=00-1B-44-11-3A-B7&csv=/assets/mac_vendors.csv
 *   /api/mac_vendor.php?mac=...&format=txt
 *   /api/mac_vendor.php?selftest=1&csv=/assets/mac_vendors.csv
 *   /api/mac_vendor.php?debug=1
 *   /api/mac_vendor.php?refresh=1      (ignore cache once)
 *   /api/mac_vendor.php?ttl=86400
 *
 * CSV format (no header preferred): prefix,vendor
 * Examples:
 *   AABBCC,Vendor Name
 *   AABBCCDD,Vendor Name (32-bit OUI)
 *
 * UI labels: English; Comments: Bengali
 */

declare(strict_types=1);

// ---------- Output & CORS ----------
$format = strtolower((string)($_GET['format'] ?? $_POST['format'] ?? 'json'));
if ($format === 'txt') header('Content-Type: text/plain; charset=utf-8');
else header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
// CORS permissive (প্রয়োজন না হলে কমেন্ট করে দিন)
header('Access-Control-Allow-Origin: *');

$DEBUG   = isset($_GET['debug']) ? true : false;
$REFRESH = isset($_GET['refresh']) ? true : false;

// ---------- Helpers (all inline) ----------

// Bengali: সেফ JSON আউট
function out_json(array $a, bool $isTxt = false): void {
    global $format;
    if ($format === 'txt' || $isTxt) {
        echo (string)($a['vendor'] ?? ''); // .txt আউটপুট শুধু ভেন্ডর
    } else {
        echo json_encode($a, JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Bengali: MAC কে AA:BB:CC:DD:EE:FF ফরম্যাটে নর্মালাইজ
function normalize_mac(string $mac, string $delim=':'): string {
    $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac));
    $hex = substr(str_pad($hex, 12, '0', STR_PAD_RIGHT), 0, 12);
    return implode($delim, str_split($hex, 2));
}

// Bengali: MAC → OUI prefix (6 বা 8 হেক্স, uppercase, delimiter ছাড়া)
function mac_oui_prefix(string $mac, int $len=6): string {
    $hex = strtoupper(preg_replace('/[^0-9A-F]/', '', $mac));
    $len = ($len === 8) ? 8 : 6;
    return substr($hex, 0, $len);
}

// Bengali: ডকুমেন্ট রুট বের করি (Apache/Nginx হলে থাকে)
function doc_root(): string {
    $dr = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    if ($dr !== '') return $dr;
    // fallback: /api থেকে এক লেভেল উপরে project root ধরে নেই
    return rtrim(dirname(__DIR__), '/');
}

// Bengali: CSV path resolve করি → file বা http
function resolve_csv(string $input = ''): array {
    // Return: ['type'=>'file'|'http','value'=>path_or_url,'resolvedFrom'=>'...']
    if ($input === '') {
        // default: /assets/mac_vendors.csv
        $input = '/assets/mac_vendors.csv';
        $from  = 'default';
    } else {
        $from = 'param';
    }

    // If absolute URL
    if (preg_match('~^https?://~i', $input)) {
        return ['type'=>'http', 'value'=>$input, 'resolvedFrom'=>$from.'-url'];
    }

    // If starts with slash: treat as doc_root relative file
    if ($input[0] === '/') {
        $full = doc_root() . $input;
        if (is_file($full)) {
            return ['type'=>'file', 'value'=>$full, 'resolvedFrom'=>$from.'-docroot'];
        }
        // If not found as file, maybe intended as URL on same host
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? '';
        if ($host !== '') {
            return ['type'=>'http', 'value'=>$scheme.'://'.$host.$input, 'resolvedFrom'=>$from.'-host-url'];
        }
        // fallback: still return file path (may fail)
        return ['type'=>'file', 'value'=>$full, 'resolvedFrom'=>$from.'-docroot-missing'];
    }

    // Relative path: try as file relative to project root
    $proj = doc_root();
    $full = $proj . '/' . ltrim($input, '/');
    if (is_file($full)) {
        return ['type'=>'file', 'value'=>$full, 'resolvedFrom'=>$from.'-relative-file'];
    }

    // Try as-is file name
    if (is_file($input)) {
        return ['type'=>'file', 'value'=>$input, 'resolvedFrom'=>$from.'-as-is-file'];
    }

    // Last resort: treat as URL if we have host
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? '';
    if ($host !== '') {
        return ['type'=>'http', 'value'=>$scheme.'://'.$host.'/'.$input, 'resolvedFrom'=>$from.'-relative-url'];
    }

    // Fallback: file path under project root (may fail)
    return ['type'=>'file', 'value'=>$full, 'resolvedFrom'=>$from.'-fallback-file'];
}

// Bengali: cache dir resolve (writable location pick)
function cache_dir(): string {
    $candidates = [
        __DIR__ . '/cache',
        sys_get_temp_dir() . '/mac_vendor_cache',
    ];
    foreach ($candidates as $d) {
        if (is_dir($d) || @mkdir($d, 0775, true)) {
            // test write
            $t = $d . '/.writetest';
            if (@file_put_contents($t, 'ok') !== false) {
                @unlink($t);
                return $d;
            }
        }
    }
    // fallback to current dir (may be read-only)
    return __DIR__;
}

// Bengali: HTTP/Local ফাইল—দুটোই সাপোর্ট (cURL না থাকলেও চলবে)
function fetch_string(string $type, string $value, int $timeout=8, ?string &$err=null): ?string {
    $err = null;
    if ($type === 'file') {
        if (!is_file($value)) { $err = 'file_not_found'; return null; }
        $data = @file_get_contents($value);
        if ($data === false) { $err = 'file_read_failed'; return null; }
        return $data;
    }

    // HTTP(S)
    if (function_exists('curl_init')) {
        $ch = curl_init($value);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => ['Accept: text/csv, text/plain, */*'],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) { $err = 'http_error:'.$code.($cerr?':'.$cerr:''); return null; }
        return $body;
    } elseif (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http'=>['timeout'=>$timeout,'follow_location'=>1,'header'=>"Accept: text/csv, text/plain, */*\r\n"]]);
        $body = @file_get_contents($value, false, $ctx);
        if ($body === false) { $err = 'http_fopen_failed'; return null; }
        return $body;
    } else {
        $err = 'no_curl_and_url_fopen_disabled';
        return null;
    }
}

// Bengali: CSV → maps['6'|'8'] ; header থাকলে auto-skip
function parse_csv_to_maps(string $csv): array {
    // Remove BOM if exists
    if (strncmp($csv, "\xEF\xBB\xBF", 3) === 0) $csv = substr($csv, 3);

    $map6 = []; $map8 = [];
    $fp = fopen('php://temp', 'r+');
    fwrite($fp, $csv);
    rewind($fp);
    while (($row = fgetcsv($fp)) !== false) {
        if (count($row) < 2) continue;
        $raw = (string)$row[0];
        $ven = trim((string)$row[1]);
        $pref = strtoupper(preg_replace('/[^0-9A-F]/', '', $raw));
        // skip header-ish first row (e.g., "prefix,vendor")
        if ($pref === '' || $ven === '') continue;
        $len = strlen($pref);
        if ($len === 6)      $map6[$pref] = $ven;
        elseif ($len === 8)  $map8[$pref] = $ven;
        // others ignored
    }
    fclose($fp);
    return ['6'=>$map6, '8'=>$map8];
}

// Bengali: load map with cache (gz JSON). Supports ?refresh=1 to bypass file freshness
function load_map_with_cache(array $src, int $ttl, bool $refresh, ?array &$debugInfo=null): array {
    $debug = [
        'csv_type' => $src['type'],
        'csv_value'=> $src['value'],
        'resolved' => $src['resolvedFrom'],
        'ttl'      => $ttl,
        'refresh'  => $refresh ? 1 : 0,
        'cache_dir'=> cache_dir(),
        'cache'    => null,
        'cache_age'=> null,
        'fetch_err'=> null,
        'counts'   => ['6'=>0,'8'=>0],
    ];

    $cdir = cache_dir();
    $ckey = md5($src['type'].':'.$src['value']);
    $cfile = $cdir . '/oui_cache_' . $ckey . '.json.gz';
    $debug['cache'] = $cfile;

    // Use cache?
    if (!$refresh && is_file($cfile)) {
        $age = time() - filemtime($cfile);
        $debug['cache_age'] = $age;
        if ($age >= 0 && $age <= $ttl) {
            $gz = @file_get_contents($cfile);
            if ($gz !== false) {
                $json = @gzdecode($gz);
                if ($json === false) $json = $gz;
                $arr = json_decode($json, true);
                if (is_array($arr) && isset($arr['6'], $arr['8'])) {
                    $debug['counts']['6'] = count($arr['6']);
                    $debug['counts']['8'] = count($arr['8']);
                    if ($debugInfo !== null) $debugInfo = $debug;
                    return $arr;
                }
            }
        }
    }

    // Fetch CSV now
    $csv = fetch_string($src['type'], $src['value'], 10, $err);
    if ($csv === null) {
        $debug['fetch_err'] = $err;
        // fallback to stale cache if present
        if (is_file($cfile)) {
            $gz = @file_get_contents($cfile);
            if ($gz !== false) {
                $json = @gzdecode($gz);
                if ($json === false) $json = $gz;
                $arr = json_decode($json, true);
                if (is_array($arr) && isset($arr['6'], $arr['8'])) {
                    $debug['counts']['6'] = count($arr['6']);
                    $debug['counts']['8'] = count($arr['8']);
                    if ($debugInfo !== null) $debugInfo = $debug;
                    return $arr;
                }
            }
        }
        if ($debugInfo !== null) $debugInfo = $debug;
        return ['6'=>[], '8'=>[]];
    }

    $maps = parse_csv_to_maps($csv);
    $debug['counts']['6'] = count($maps['6']);
    $debug['counts']['8'] = count($maps['8']);

    // Save cache (ignore write errors)
    $json = json_encode($maps, JSON_UNESCAPED_UNICODE);
    if ($json !== false) @file_put_contents($cfile, gzencode($json, 6));

    if ($debugInfo !== null) $debugInfo = $debug;
    return $maps;
}

// Bengali: lookup vendor from maps (8→6)
function lookup_vendor(string $mac, array $maps): array {
    $norm = normalize_mac($mac, ':');
    $p8 = mac_oui_prefix($norm, 8);
    if (isset($maps['8'][$p8])) return [$norm, $maps['8'][$p8], 8];

    $p6 = mac_oui_prefix($norm, 6);
    if (isset($maps['6'][$p6])) return [$norm, $maps['6'][$p6], 6];

    return [$norm, null, 0];
}

// ---------- Inputs ----------
$mac     = trim((string)($_GET['mac'] ?? $_POST['mac'] ?? $_GET['q'] ?? $_POST['q'] ?? ''));
$csvIn   = trim((string)($_GET['csv'] ?? $_POST['csv'] ?? '')); // e.g. /assets/mac_vendors.csv
$ttl     = (int)($_GET['ttl'] ?? $_POST['ttl'] ?? 86400);
$selft   = isset($_GET['selftest']) ? true : false;

if ($selft) {
    // Self-test: CSV resolve + fetch + parse counts
    $src = resolve_csv($csvIn);
    $info = null;
    $maps = load_map_with_cache($src, max(60,$ttl), $REFRESH, $info);

    $ok = (count($maps['6']) + count($maps['8'])) > 0;
    $out = [
        'ok'       => $ok ? 1 : 0,
        'message'  => $ok ? 'Self-test OK' : 'Self-test failed (no entries).',
        'csv'      => $src,
        'debug'    => $info,
        'example'  => [
            'try' => '/api/mac_vendor.php?mac=A4:5E:60:12:34:56&csv=' . rawurlencode($csvIn !== '' ? $csvIn : '/assets/mac_vendors.csv'),
            'note'=> 'Use a MAC whose OUI exists in your CSV.'
        ],
    ];
    if ($format === 'txt') {
        // .txt আউটপুট চাইলে সংক্ষেপে
        echo $out['message'] . "\n";
        exit;
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

// Require MAC for normal lookup
if ($mac === '') {
    $err = ['ok'=>0, 'error'=>'Missing parameter: mac', 'hint'=>'/api/mac_vendor.php?mac=AA:BB:CC:DD:EE:FF'];
    out_json($err);
}

// Load maps (with cache)
$src  = resolve_csv($csvIn);
$info = null;
$maps = load_map_with_cache($src, max(60,$ttl), $REFRESH, $info);

// Lookup
list($normalized, $vendor, $matchLen) = lookup_vendor($mac, $maps);

// Build response
$resp = [
    'ok'                 => 1,
    'mac'                => $mac,
    'normalized'         => $normalized,
    'vendor'             => $vendor ?: 'Unknown Vendor',
    'matched_prefix_len' => $matchLen,
    'source'             => $vendor ? 'csv-cache' : 'fallback',
];

if ($DEBUG) {
    $resp['debug'] = [
        'csv'   => $src,
        'cache' => $info ?? null,
        'doc_root' => doc_root(),
    ];
}

// Output
if ($format === 'txt') {
    out_json(['vendor'=>$resp['vendor']], true);
} else {
    out_json($resp);
}
