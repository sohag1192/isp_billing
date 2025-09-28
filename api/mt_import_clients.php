<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

header('Content-Type: application/json; charset=utf-8');
function jexit($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
function col_exists(PDO $pdo,string $tbl,string $col):bool{
  $st=$pdo->prepare("SHOW COLUMNS FROM `$tbl` LIKE ?"); $st->execute([$col]); return (bool)$st->fetchColumn();
}
function list_columns(PDO $pdo,string $tbl): array {
  $rows=$pdo->query("SHOW COLUMNS FROM `$tbl`")->fetchAll(PDO::FETCH_ASSOC);
  return array_map(fn($r)=>$r['Field'],$rows?:[]);
}

// PPPoE → safe client_code
function make_code_from_pppoe(string $pppoe): string {
  $x = preg_replace('/[^A-Za-z0-9]/', '', $pppoe);
  if ($x === '') $x = 'CL'.date('ymdHis');
  return substr($x, 0, 32);
}
// ensure client_code unique
function ensure_unique_client_code(PDO $pdo, string $base): string {
  $code = $base; $i=0;
  $st=$pdo->prepare("SELECT 1 FROM clients WHERE client_code=? LIMIT 1");
  while(true){
    $st->execute([$code]);
    if(!$st->fetchColumn()) return $code;
    $i++; $suf='-'.$i;
    $code = substr($base, 0, max(1, 32 - strlen($suf))).$suf;
  }
}

/**
 * Generate/replace invoices for given clients for YYYY-MM.
 * Returns ['created'=>N, 'debug'=>['zero_amount'=>x, 'no_table'=>0, 'had_old'=>y]]
 */
function generate_invoices_for(PDO $pdo, array $clientIds, string $ym): array {
  $debug = ['zero_amount'=>0, 'no_table'=>0, 'had_old'=>0];
  if(!$clientIds) return ['created'=>0,'debug'=>$debug];

  // invoices table present?
  try { $cols = list_columns($pdo,'invoices'); }
  catch(Throwable $e){ $debug['no_table']=count($clientIds); return ['created'=>0,'debug'=>$debug]; }

  // column discovery (very liberal)
  $col_total = null; // which amount column to use
  foreach (['total','amount','payable','grand_total','net_total'] as $cand){
    if(in_array($cand,$cols,true)){ $col_total = $cand; break; }
  }
  $has_bm   = in_array('billing_month',$cols,true);
  $has_date = in_array('invoice_date',$cols,true);
  $has_stat = in_array('status',$cols,true);
  $has_void = in_array('is_void',$cols,true);
  $has_desc = in_array('description',$cols,true);

  // get amount (monthly_bill, fallback package price)
  $in = implode(',', array_fill(0,count($clientIds),'?'));
  $q = $pdo->prepare("
    SELECT c.id AS client_id, COALESCE(c.monthly_bill,0) AS bill, p.price AS pkg_price
    FROM clients c LEFT JOIN packages p ON p.id=c.package_id
    WHERE c.id IN ($in)
  ");
  $q->execute($clientIds);
  $rows = $q->fetchAll(PDO::FETCH_ASSOC);
  if(!$rows) return ['created'=>0,'debug'=>$debug];

  $now = date('Y-m-d H:i:s'); $created = 0;

  // statements
  $stGetOld = $has_bm
    ? $pdo->prepare("SELECT id,".($col_total?$col_total:'0')." AS total FROM invoices WHERE client_id=? AND billing_month=? ".($has_void?"AND (is_void=0 OR is_void IS NULL)":($has_stat?"AND status<>'void'":""))." LIMIT 1")
    : null;

  $stVoid = ($has_void || $has_stat)
    ? $pdo->prepare("UPDATE invoices SET ".($has_void?"is_void=1":"").($has_void&&$has_stat?", ":"").($has_stat?"status='void'":"").", updated_at=? WHERE id=?")
    : null;

  foreach($rows as $r){
    $cid = (int)$r['client_id'];
    $amt = (float)$r['bill'];
    if($amt<=0) $amt = (float)($r['pkg_price'] ?? 0);
    if($amt<=0){ $debug['zero_amount']++; continue; }

    // replace-safe: void old for same month (if billing_month exists)
    if($stGetOld){
      $stGetOld->execute([$cid, $ym]);
      if($old = $stGetOld->fetch(PDO::FETCH_ASSOC)){
        $debug['had_old']++;
        if($stVoid) $stVoid->execute([$now, $old['id']]);
        // ledger -= old total (if known column)
        if(isset($old['total']) && $old['total']!==null){
          $pdo->prepare("UPDATE clients SET ledger_balance=COALESCE(ledger_balance,0)-? WHERE id=?")->execute([(float)$old['total'], $cid]);
        }
      }
    }

    // build insert
    $colsIns = ['client_id']; $vals = [$cid];
    if($col_total){ $colsIns[]=$col_total; $vals[]=$amt; }
    if($has_bm){ $colsIns[]='billing_month'; $vals[]=$ym; }
    if($has_date){ $colsIns[]='invoice_date'; $vals[] = date('Y-m-d'); }
    if($has_stat){ $colsIns[]='status'; $vals[]='unpaid'; }
    if($has_desc){ $colsIns[]='description'; $vals[]='Auto-generated from Mikrotik import'; }
    $colsIns[]='created_at'; $vals[]=$now;
    $colsIns[]='updated_at'; $vals[]=$now;

    $ph = rtrim(str_repeat('?,', count($colsIns)), ',');
    $pdo->prepare("INSERT INTO invoices (".implode(',', $colsIns).") VALUES ($ph)")->execute($vals);

    // ledger += amount (using our computed $amt)
    $pdo->prepare("UPDATE clients SET ledger_balance=COALESCE(ledger_balance,0)+? WHERE id=?")->execute([$amt, $cid]);

    $created++;
  }

  return ['created'=>$created, 'debug'=>$debug];
}

try{
  $body=json_decode(file_get_contents('php://input')?:'', true);
  if(!is_array($body)) jexit(['ok'=>false,'msg'=>'Invalid JSON']);

  $router_id=(int)($body['router_id']??0);
  $profile=(string)($body['profile']??'');
  $options=$body['options']??[];
  $rows=$body['rows']??[];
  if($router_id<=0 || !$rows) jexit(['ok'=>false,'msg'=>'Missing router_id or rows']);

  $opt_do_not_overwrite=!empty($options['do_not_overwrite']);
  $opt_save_password=!empty($options['save_password']);
  $opt_generate_invoice=!empty($options['generate_invoice']);
  $invoice_month=trim((string)($options['invoice_month']??'')); // YYYY-MM

  $pdo=db(); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
  $has_pwd_col  = col_exists($pdo,'clients','pppoe_password');
  $has_code_col = col_exists($pdo,'clients','client_code');

  // packages
  $pkgRows=$pdo->query("SELECT id,name,price FROM packages")->fetchAll(PDO::FETCH_ASSOC);
  $pkgMapId=[]; foreach($pkgRows as $p){ $pkgMapId[$p['id']]=$p; }

  // existing
  $cliRows=$pdo->query("SELECT id,pppoe_id,client_code,name,mobile,package_id,monthly_bill FROM clients")->fetchAll(PDO::FETCH_ASSOC);
  $cliMap=[]; foreach($cliRows as $c){ $cliMap[$c['pppoe_id']]=$c; }

  $created=$updated=$skipped=0; $affectedIds=[]; $now=date('Y-m-d H:i:s');

  $pdo->beginTransaction();
  foreach($rows as $r){
    if(empty($r['selected'])){ $skipped++; continue; }

    $pppoe_id   = trim((string)($r['pppoe_id']??'')); if($pppoe_id===''){ $skipped++; continue; }
    $client_name= trim((string)($r['client_name']??$pppoe_id));
    $mobile     = trim((string)($r['mobile']??''));
    $status     = (string)($r['status']??'active');
    $package_id = $r['package_id']??null;
    $pkg        = $package_id?($pkgMapId[$package_id]??null):null;
    $bill       = $pkg ? (float)$pkg['price'] : 0.0;
    $password   = $r['password']??null;

    if(isset($cliMap[$pppoe_id])){
      // UPDATE
      $existing = $cliMap[$pppoe_id];
      $fields = ['router_id'=>$router_id,'status'=>$status,'updated_at'=>$now];
      if(!$opt_do_not_overwrite || empty($existing['name']))   $fields['name']=$client_name;
      if(!$opt_do_not_overwrite || empty($existing['mobile'])) $fields['mobile']=($mobile!==''?$mobile:null);
      if($package_id && $pkg){ $fields['package_id']=$package_id; $fields['monthly_bill']=$bill; }
      if($opt_save_password && $has_pwd_col && $password){ $fields['pppoe_password']=$password; }
      if($has_code_col && (empty($existing['client_code'])||$existing['client_code']==='')){
        $fields['client_code']=ensure_unique_client_code($pdo, make_code_from_pppoe($pppoe_id));
      }
      $set=[];$vals=[]; foreach($fields as $k=>$v){ $set[]="`$k`=?"; $vals[]=$v; } $vals[]=$pppoe_id;
      $pdo->prepare("UPDATE clients SET ".implode(',',$set)." WHERE pppoe_id=?")->execute($vals);
      $updated++; $affectedIds[]=(int)$existing['id'];
    } else {
      // INSERT (package optional)
      $client_code = $has_code_col ? ensure_unique_client_code($pdo, make_code_from_pppoe($pppoe_id)) : null;
      $cols=['pppoe_id','client_code','name','mobile','package_id','router_id','status','monthly_bill','is_left','created_at','updated_at','join_date'];
      $vals=[$pppoe_id,$client_code,$client_name,($mobile!==''?$mobile:null),($package_id?:null),$router_id,$status,$bill,0,$now,$now,$now];
      if(!$has_code_col){ array_splice($cols,1,1); array_splice($vals,1,1); }
      if($opt_save_password && $has_pwd_col && $password){ $cols[]='pppoe_password'; $vals[]=$password; }
      $ph=rtrim(str_repeat('?,',count($cols)),',');
      $pdo->prepare("INSERT INTO clients (".implode(',',$cols).") VALUES ($ph)")->execute($vals);
      $newId=(int)$pdo->lastInsertId(); $created++; $affectedIds[]=$newId;
      $cliMap[$pppoe_id]=['id'=>$newId,'pppoe_id'=>$pppoe_id];
    }
  }
  $pdo->commit();

  // --- invoices
  $inv_created=0; $inv_debug=[];
  if($opt_generate_invoice && preg_match('/^\d{4}-\d{2}$/',$invoice_month) && $affectedIds){
    $pdo->beginTransaction();
    try{
      $res = generate_invoices_for($pdo, array_values(array_unique($affectedIds)), $invoice_month);
      $inv_created = (int)$res['created']; $inv_debug = $res['debug'];
      $pdo->commit();
    }catch(Throwable $e){ $pdo->rollBack(); $inv_debug=['error'=>$e->getMessage()]; }
  }

  jexit(['ok'=>true,'summary'=>[
    'created'=>$created,'updated'=>$updated,'skipped'=>$skipped,'invoices_created'=>$inv_created
  ], 'invoice_debug'=>$inv_debug]);

}catch(Throwable $e){
  if(isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  jexit(['ok'=>false,'msg'=>$e->getMessage()]);
}
