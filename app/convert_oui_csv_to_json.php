<?php
/**
 * convert_oui_csv_to_json.php
 *
 * Usage (CLI):
 *   php convert_oui_csv_to_json.php /path/to/oui.csv [output_json_path]
 *
 * Defaults output to: app/oui_vendors.json (same dir as this file)
 *
 * Supports CSV formats:
 *  1) IEEE official: has headers like "Assignment","Organization Name"
 *  2) Simple: "AABBCC,Vendor Name"
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
  fwrite(STDERR, "Run from CLI. Example:\n  php ".__FILE__." /path/to/oui.csv app/oui_vendors.json\n");
  exit(1);
}

$in  = $argv[1] ?? null;
$out = $argv[2] ?? __DIR__ . '/oui_vendors.json';

if (!$in || !is_readable($in)) {
  fwrite(STDERR, "Input CSV not readable: {$in}\n");
  exit(1);
}

function norm_prefix(string $s): ?string {
  $hex = strtoupper(preg_replace('/[^0-9A-F]/', '', $s));
  if (strlen($hex) < 6) return null;              // need at least 3 bytes
  return substr($hex, 0, 6);                      // AABBCC
}

$fh = fopen($in, 'r');
if (!$fh) {
  fwrite(STDERR, "Failed to open input CSV\n");
  exit(1);
}

/** Detect header */
$header = fgetcsv($fh);
if ($header === false) {
  fwrite(STDERR, "Empty CSV\n");
  exit(1);
}
$cols = array_map(fn($x)=>trim((string)$x), $header);
$hasHeader = true;

$idxPrefix = null;
$idxVendor = null;

/** IEEE style */
foreach ($cols as $i => $name) {
  $n = strtolower($name);
  if ($idxPrefix === null && (str_contains($n,'assignment') || $n==='assignment')) $idxPrefix = $i;
  if ($idxVendor === null && (str_contains($n,'organization') || str_contains($n,'org') || str_contains($n,'vendor'))) $idxVendor = $i;
}

/** Simple style (no useful header) */
if ($idxPrefix === null || $idxVendor === null) {
  // Assume first row was data, not header — push it back as first data row
  $hasHeader = false;
  rewind($fh);
}

/** Parse loop */
$map = [];     // 'AABBCC' => 'Vendor Name'

$lineNo = 0;
while (($row = fgetcsv($fh)) !== false) {
  $lineNo++;
  if ($hasHeader && $lineNo===1) continue; // skip header if detected

  if ($idxPrefix === null || $idxVendor === null) {
    // simple two-column CSV
    if (count($row) < 2) continue;
    $rawPrefix = $row[0] ?? '';
    $rawVendor = $row[1] ?? '';
  } else {
    $rawPrefix = $row[$idxPrefix] ?? '';
    $rawVendor = $row[$idxVendor] ?? '';
  }

  $p = norm_prefix($rawPrefix);
  if (!$p) continue;

  $vendor = trim(preg_replace('/\s+/', ' ', (string)$rawVendor));
  if ($vendor === '') $vendor = 'Unknown Vendor';

  // keep first seen (or overwrite—both fine); here we overwrite to keep latest
  $map[$p] = $vendor;
}
fclose($fh);

/** Write JSON */
$dir = dirname($out);
if (!is_dir($dir)) {
  if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
    fwrite(STDERR, "Failed to create output dir: {$dir}\n");
    exit(1);
  }
}

$bytes = file_put_contents($out, json_encode($map, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
if ($bytes === false) {
  fwrite(STDERR, "Failed to write JSON: {$out}\n");
  exit(1);
}

echo "OK: wrote ".number_format(strlen(json_encode($map)))." bytes, ".
     number_format(count($map))." prefixes to {$out}\n";
