<?php
// /public/payments_bkash_match.php
// UI: English; Comments: বাংলা
// Feature: Manually match an inbox row to a client and apply payment + (optional) wallet credit.

declare(strict_types=1);
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (!isset($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf'] ?? '')) {
  http_response_code(403);
  echo "Invalid CSRF token."; exit;
}

$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------- helpers ---------- */
function tbl_exists(PDO $pdo, string $t): bool {
  try{
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
    $st->execute([$db, $t]); return (bool)$st->fetchColumn();
  }catch(Throwable $e){ return false; }
}
function col_exists(PDO $pdo, string $tbl, string $col): bool {
  try{
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $st = $pdo->prepare("SELECT 1
                         FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?
                         LIMIT 1");
    $st->execute([$db, $tbl, $col]);
    return (bool)$st->fetchColumn();
  }catch(Throwable $e){ return false; }
}
function table_columns(PDO $pdo, string $tbl): array {
  try {
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $st = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
    $st->execute([$db, $tbl]);
    return array_map(fn($r)=>$r['COLUMN_NAME'], $st->fetchAll(PDO::FETCH_ASSOC));
  } catch (Throwable $e) { return []; }
}
function normalize_msisdn($s){
  $s = preg_replace('/\D+/','', (string)$s);
  if (strlen($s)===13 && substr($s,0,3)==='880') $s = '0'.substr($s,3);
  if (strlen($s)===14 && substr($s,0,4)==='0880') $s = '0'.substr($s,4);
  if (strlen($s)===11 && substr($s,0,2)==='01') return $s;
  return $s;
}
// রেফারেন্স থেকে client resolve (invoice_no থাকলে তা, না থাকলে fallback)
function resolve_client_from_ref(PDO $pdo, ?string $ref): ?int {
  $ref = trim((string)$ref);
  if ($ref === '') return null;

  if (col_exists($pdo, 'invoices', 'invoice_no')) {
    $st = $pdo->prepare("SELECT client_id FROM invoices WHERE invoice_no=? LIMIT 1");
    $st->execute([$ref]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      if (!empty($row['client_id'])) return (int)$row['client_id'];
    }
  }

  if (ctype_digit($ref)) {
    try{
      $st = $pdo->prepare("SELECT client_id FROM invoices WHERE id=? LIMIT 1");
      $st->execute([(int)$ref]);
      if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($row['client_id'])) return (int)$row['client_id'];
      }
    }catch(Throwable $e){}
  }

  foreach (['client_code','pppoe_id','pppoeid','pppoe','code','username','pppoe_username','customer_code','clientid'] as $col) {
    try{
      if (!col_exists($pdo, 'clients', $col)) continue;
      $st = $pdo->prepare("SELECT id FROM clients WHERE `$col`=? LIMIT 1");
      $st->execute([$ref]);
      if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($row['id'])) return (int)$row['id'];
      }
    }catch(Throwable $e){}
  }
  return null;
}
function resolve_collector_user_id(PDO $pdo, ?string $msisdn_to): int {
  if (!$msisdn_to) return 0;
  $msisdn_to = normalize_msisdn($msisdn_to);
  if (tbl_exists($pdo,'wallet_accounts')) {
    $st = $pdo->prepare("SELECT user_id FROM wallet_accounts WHERE msisdn=? LIMIT 1");
    $st->execute([$msisdn_to]); $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r && !empty($r['user_id'])) return (int)$r['user_id'];
  }
  try{
    if (col_exists($pdo,'users','phone')) {
      $st = $pdo->prepare("SELECT id FROM users WHERE phone=? LIMIT 1");
      $st->execute([$msisdn_to]); $r = $st->fetch(PDO::FETCH_ASSOC);
      if ($r && !empty($r['id'])) return (int)$r['id'];
    }
  }catch(Throwable $e){}
  return 0;
}

/* ------------------------- Wallet credit helper (flexible) ------------------------- */
function wallet_credit(PDO $pdo, int $user_id, float $amount, array $meta): void {
  if ($user_id<=0 || $amount<=0) return;
  $tbl = 'wallet_transactions';
  if (!tbl_exists($pdo,$tbl)) return;

  $cols = table_columns($pdo,$tbl);
  if (!$cols) return;

  $ownerCol = null;
  foreach (['user_id','employee_id','emp_id','owner_id','collector_id'] as $c) {
    if (in_array($c,$cols,true)) { $ownerCol = $c; break; }
  }
  if (!$ownerCol) return;

  $typeCol = in_array('type',$cols,true) ? 'type' : (in_array('txn_type',$cols,true) ? 'txn_type' : null);
  $amtCol  = in_array('amount',$cols,true) ? 'amount' : null;
  if (!$amtCol) return;

  $srcCol  = in_array('source',$cols,true) ? 'source' : (in_array('channel',$cols,true) ? 'channel' : null);
  $refCol  = in_array('reference',$cols,true) ? 'reference' : (in_array('ref',$cols,true) ? 'ref' : null);
  $metaCol = in_array('meta_json',$cols,true) ? 'meta_json' : (in_array('meta',$cols,true) ? 'meta' : null);
  $created = in_array('created_at',$cols,true) ? 'created_at' : null;

  $fields = [];
  $placeholders = [];
  $values = [];

  $fields[]="`$ownerCol`"; $placeholders[]='?'; $values[]=$user_id;
  $fields[]="`$amtCol`";   $placeholders[]='?'; $values[]=$amount;
  if ($typeCol){ $fields[]="`$typeCol`"; $placeholders[]='?'; $values[]='credit'; }
  if ($srcCol) { $fields[]="`$srcCol`";  $placeholders[]='?'; $values[]='bkash_sms'; }
  if ($refCol) { $fields[]="`$refCol`";  $placeholders[]='?'; $values[]=$meta['trx_id'] ?? null; }
  if ($metaCol){ $fields[]="`$metaCol`"; $placeholders[]='?'; $values[]=json_encode($meta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
  if ($created){ $fields[]="`$created`"; $placeholders[]='NOW()'; }

  $sql = "INSERT INTO `$tbl` (".implode(',', $fields).") VALUES (".implode(',', $placeholders).")";
  $st  = $pdo->prepare($sql);
  $st->execute($values);
}

/* ------------------------- Settle helper ------------------------- */
function settle_payment(PDO $pdo, int $client_id, float $amount, string $trx_id, string $method, string $note, ?string $paid_at): array {
  $applied = 0.0; $appliedInvoices=[];
  $sql="SELECT i.id, i.payable, COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.bill_id=i.id),0) AS paid_sum
        FROM invoices i WHERE i.client_id=? ORDER BY i.year ASC, i.month ASC, i.id ASC";
  $st=$pdo->prepare($sql); $st->execute([$client_id]); $invs=$st->fetchAll(PDO::FETCH_ASSOC);
  foreach($invs as $inv){
    if($amount<=0) break;
    $due=(float)$inv['payable']-(float)$inv['paid_sum']; if($due<=0) continue;
    $pay=min($due,$amount);
    $pdo->prepare("INSERT INTO payments (bill_id,amount,discount,method,txn_id,paid_at,notes) VALUES (?,?,?,?,?,?,?)")
        ->execute([(int)$inv['id'],$pay,0,$method,$trx_id,$paid_at ?: date('Y-m-d H:i:s'),$note]);
    $sum=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE bill_id=?");
    $sum->execute([(int)$inv['id']]); $newPaid=(float)$sum->fetchColumn();
    $pdo->prepare("UPDATE invoices SET status=? WHERE id=?")
        ->execute([($newPaid>=(float)$inv['payable'])?'Paid':'Due',(int)$inv['id']]);
    $applied+=$pay; $amount-=$pay; $appliedInvoices[]=(int)$inv['id'];
  }
  return ['applied_amount'=>$applied,'applied_invoices'=>$appliedInvoices,'remaining'=>$amount];
}

/* ---------- inputs ---------- */
$inbox_id = (int)($_POST['inbox_id'] ?? 0);
$ref      = trim($_POST['ref'] ?? '');
$clientId = (int)($_POST['client_id'] ?? 0);
$amount   = (float)($_POST['amount'] ?? 0);
$trxId    = strtoupper(trim($_POST['trx_id'] ?? ''));
$markProc = !empty($_POST['mark_processed']);

if ($inbox_id<=0) { echo "Invalid inbox id."; exit; }

/* load inbox */
$st = $pdo->prepare("SELECT * FROM sms_inbox WHERE id=? LIMIT 1");
$st->execute([$inbox_id]); $row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) { echo "Inbox row not found."; exit; }

/* sanity */
if ($amount <= 0)  $amount = (float)($row['amount'] ?? 0);
if ($trxId === '') $trxId  = (string)($row['trx_id'] ?? '');
if ($trxId === '') { echo "Missing TrxID."; exit; }

/* resolve client if not provided */
if ($clientId<=0 && $ref!=='') $clientId = resolve_client_from_ref($pdo, $ref);
if ($clientId<=0) { echo "Client not resolved."; exit; }

/* dup guard */
$dup = $pdo->prepare("SELECT id FROM payments WHERE method='bkash' AND txn_id=? LIMIT 1");
$dup->execute([$trxId]);
if ($dup->fetch()) {
  if ($markProc) {
    $pdo->prepare("UPDATE sms_inbox SET processed=1,error_msg=NULL WHERE id=?")->execute([$inbox_id]);
  }
  header("Location: /public/payments_bkash_inbox.php?status=unprocessed&msg=".urlencode('Duplicate payment; marked processed.'));
  exit;
}

$pdo->beginTransaction();

$note = sprintf('bKash SMS manual match; inbox_id=%d; ref=%s', $inbox_id, $ref);
$res = settle_payment($pdo, $clientId, $amount, $trxId, 'bkash', $note, $row['received_at'] ?? null);

/* wallet credit to collector (owner of msisdn_to) */
$collectorId = resolve_collector_user_id($pdo, $row['msisdn_to'] ?? null);
wallet_credit($pdo, $collectorId, (float)$res['applied_amount'], [
  'trx_id'=>$trxId,'client_id'=>$clientId,'invoices'=>$res['applied_invoices'],
  'msisdn_to'=>$row['msisdn_to'] ?? null,'sender'=>$row['sender_number'] ?? null,'ref'=>$ref
]);

if ($markProc) {
  $pdo->prepare("UPDATE sms_inbox SET processed=1,error_msg=NULL WHERE id=?")->execute([$inbox_id]);
}

$pdo->commit();

header("Location: /public/payments_bkash_inbox.php?status=unprocessed&msg=".urlencode('Applied TK '.$res['applied_amount'].' to client '.$clientId));
exit;
