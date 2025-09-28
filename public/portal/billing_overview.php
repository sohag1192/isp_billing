<?php
// /public/portal/billing_overview.php
// One-page: My Bills (Invoices) + Payments (standalone, safe, schema-aware)
// UI English; বাংলা কমেন্ট
declare(strict_types=1);

require_once __DIR__ . '/../../app/require_login.php';
require_once __DIR__ . '/../../app/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
function col_exists(PDO $pdo, string $tbl, string $col): bool {
  try{ $st=$pdo->prepare("SHOW COLUMNS FROM `$tbl` LIKE ?"); $st->execute([$col]); return (bool)$st->fetchColumn(); }
  catch(Throwable $e){ return false; }
}

/* ---------------- Client resolve (সেশন/গেট থেকে) ---------------- */
function resolve_client_id(PDO $pdo): ?int {
  if (session_status() === PHP_SESSION_NONE) session_start();

  foreach (['client_id','SESS_CLIENT_ID','SESS_USER_ID'] as $k) {
    if (!empty($_SESSION[$k]) && ctype_digit((string)$_SESSION[$k])) return (int)$_SESSION[$k];
  }
  if (!empty($_GET['cid']) && ctype_digit((string)$_GET['cid'])) return (int)$_GET['cid'];
  if (!empty($_GET['pppoe'])) {
    $pp=trim((string)$_GET['pppoe']); if($pp!==''){
      $q=$pdo->prepare("SELECT id FROM clients WHERE pppoe_id=? OR client_code=? OR name=?");
      $q->execute([$pp,$pp,$pp]); if($id=$q->fetchColumn()) return (int)$id;
    }
  }
  $cands=[];
  foreach (['pppoe_id','username','SESS_USER_NAME','SESS_USERNAME','user_name'] as $k) if(!empty($_SESSION[$k])) $cands[]=trim((string)$_SESSION[$k]);
  foreach ($cands as $u){ if($u==='')continue; $q=$pdo->prepare("SELECT id FROM clients WHERE pppoe_id=? OR client_code=? OR name=?"); $q->execute([$u,$u,$u]); if($id=$q->fetchColumn()) return (int)$id; }
  foreach (['email','SESS_USER_EMAIL'] as $k){ if(!empty($_SESSION[$k])){ $em=trim((string)$_SESSION[$k]); if($em!==''){ $q=$pdo->prepare("SELECT id FROM clients WHERE email=?"); $q->execute([$em]); if($id=$q->fetchColumn()) return (int)$id; } } }
  foreach (['mobile','phone'] as $k){ if(!empty($_SESSION[$k])){ $m=preg_replace('/\D+/','',(string)$_SESSION[$k]); if($m!==''){ $q=$pdo->prepare("SELECT id FROM clients WHERE REPLACE(REPLACE(REPLACE(mobile,'-',''),' ','') , '+', '') LIKE ?"); $q->execute(['%'.$m.'%']); if($id=$q->fetchColumn()) return (int)$id; } } }
  return null;
}

$client_id = resolve_client_id($pdo);
if (!$client_id) {
  http_response_code(403);
  echo "<div style='max-width:680px;margin:40px auto;font-family:sans-serif'><h4 style='color:#b02a37'>Access denied</h4><p>Client context not found. Please sign in.</p><a href='/public/portal/index.php' style='border:1px solid #ccc;padding:6px 10px;border-radius:6px;text-decoration:none'>Back to Portal</a></div>";
  exit;
}
$_SESSION['client_id'] = (int)$client_id;

/* ------------- Current client (mapping) ------------- */
$stC=$pdo->prepare("SELECT id, client_code, pppoe_id, name, email, mobile FROM clients WHERE id=?");
$stC->execute([$client_id]);
$CLIENT=$stC->fetch(PDO::FETCH_ASSOC) ?: ['id'=>$client_id];

/* ------------- Schema detects ------------- */
$inv_has_number = col_exists($pdo,'invoices','invoice_number');
$inv_has_no     = col_exists($pdo,'invoices','invoice_no');
$inv_has_bmon   = col_exists($pdo,'invoices','billing_month');
$inv_has_month  = col_exists($pdo,'invoices','month');
$inv_has_year   = col_exists($pdo,'invoices','year');
$inv_has_date   = col_exists($pdo,'invoices','invoice_date');
$inv_has_status = col_exists($pdo,'invoices','status');

$pay_tbl     = 'payments';
$pay_has_iid = col_exists($pdo,$pay_tbl,'invoice_id');
$pay_has_bid = col_exists($pdo,$pay_tbl,'bill_id');
$pay_col_inv = $pay_has_iid ? 'invoice_id' : ($pay_has_bid ? 'bill_id' : null);

/* Invoice amount expr */
$amount_exprs=[];
if (col_exists($pdo,'invoices','total'))   $amount_exprs[]='i.total';
if (col_exists($pdo,'invoices','payable')) $amount_exprs[]='i.payable';
if (col_exists($pdo,'invoices','amount'))  $amount_exprs[]='i.amount';
$amount_expr = $amount_exprs ? ('COALESCE('.implode(',', $amount_exprs).')') : '0';

/* Payment cols (schema-agnostic) */
$pay_amt_col = col_exists($pdo,$pay_tbl,'amount') ? 'amount' : (col_exists($pdo,$pay_tbl,'paid_amount')?'paid_amount':'amount');
$pay_has_disc= col_exists($pdo,$pay_tbl,'discount');
$pay_date_col= col_exists($pdo,$pay_tbl,'payment_date') ? 'payment_date' : (col_exists($pdo,$pay_tbl,'date')?'date':'created_at');
$pay_meth_col= col_exists($pdo,$pay_tbl,'method') ? 'method' : (col_exists($pdo,$pay_tbl,'payment_method')?'payment_method':null);
$pay_ref_col = col_exists($pdo,$pay_tbl,'reference') ? 'reference' : (col_exists($pdo,$pay_tbl,'trx_id')?'trx_id':null);

/* ---------------- Filters ---------------- */
$month  = trim($_GET['month']  ?? '');
$status = strtolower(trim($_GET['status'] ?? '')); // paid/partial/unpaid/due or ''
$search = trim($_GET['q'] ?? '');
$page   = max(1, intval($_GET['page'] ?? 1));
$limit  = 20; $offset = ($page-1)*$limit;

/* ---------------- Strict client WHERE (leak-prevent) ---------------- */
$where = []; $args=[];
$invClientCol=null;
foreach(['client_id','customer_id','subscriber_id','user_id','client','cid'] as $col){
  if(col_exists($pdo,'invoices',$col)){ $invClientCol=$col; break; }
}
if($invClientCol){ $where[]="i.`$invClientCol`=?"; $args[]=$client_id; }
else{
  $matched=false;
  $cands=[
    ['col'=>'client_code','val'=>$CLIENT['client_code']??null],
    ['col'=>'pppoe_id','val'=>$CLIENT['pppoe_id']??null],
    ['col'=>'email','val'=>$CLIENT['email']??null],
    ['col'=>'mobile','val'=>$CLIENT['mobile']??null],
    ['col'=>'name','val'=>$CLIENT['name']??null],
  ];
  foreach($cands as $c){
    if($c['val'] && col_exists($pdo,'invoices',$c['col'])){ $where[]="i.`{$c['col']}`=?"; $args[]=$c['val']; $matched=true; break;}
  }
  if(!$matched){ $where[]="1=0"; } // safety: সব ইনভয়েস লুকাও
}

/* Month filter */
if ($month!=='' && preg_match('/^\d{4}-\d{2}$/',$month)) {
  if ($inv_has_bmon){ $where[]="i.billing_month=?"; $args[]=$month; }
  elseif($inv_has_month && $inv_has_year){ [$y,$m]=explode('-',$month); $where[]="(i.year=? AND LPAD(i.month,2,'0')=?)"; $args[]=(int)$y; $args[]=$m; }
  elseif($inv_has_date){ $where[]="DATE_FORMAT(i.invoice_date,'%Y-%m')=?"; $args[]=$month; }
}

/* Status */
if ($status!=='' && $inv_has_status){ $where[]="LOWER(i.status)=?"; $args[]=$status; }

/* Search by invoice no/notes */
if ($search!==''){
  $like='%'.$search.'%'; $parts=[];
  if($inv_has_number) $parts[]="i.invoice_number LIKE ?";
  if($inv_has_no)     $parts[]="i.invoice_no LIKE ?";
  if(col_exists($pdo,'invoices','notes')) $parts[]="i.notes LIKE ?";
  if($parts){ $where[]='('.implode(' OR ',$parts).')'; foreach($parts as $_) $args[]=$like; }
}

$where_sql = $where?('WHERE '.implode(' AND ',$where)):'';

/* ---------------- Invoices: count & fetch (page) ---------------- */
$stc=$pdo->prepare("SELECT COUNT(*) FROM invoices i $where_sql"); $stc->execute($args);
$total_rows=(int)$stc->fetchColumn(); $total_pages=max(1,(int)ceil($total_rows/$limit));

$selects=["i.id","$amount_expr AS total_amount", $inv_has_status?"COALESCE(i.status,'') AS status":"'' AS status"];
if($inv_has_number)      $selects[]="i.invoice_number AS invoice_no";
elseif($inv_has_no)      $selects[]="i.invoice_no AS invoice_no";
else                     $selects[]="i.id AS invoice_no";
if($inv_has_bmon)                    $selects[]="i.billing_month AS ym";
elseif($inv_has_month && $inv_has_year) $selects[]="CONCAT(i.year,'-',LPAD(i.month,2,'0')) AS ym";
elseif($inv_has_date)                $selects[]="DATE_FORMAT(i.invoice_date,'%Y-%m') AS ym";
else                                 $selects[]="'' AS ym";

$sql="SELECT ".implode(', ',$selects)." FROM invoices i $where_sql ORDER BY i.id DESC LIMIT $limit OFFSET $offset";
$sti=$pdo->prepare($sql); $sti->execute($args); $invoices=$sti->fetchAll(PDO::FETCH_ASSOC);

/* Paid sums for page invoices */
$paid_map=[];
if($pay_col_inv && $invoices){
  $ids=array_column($invoices,'id'); $in=implode(',',array_fill(0,count($ids),'?'));
  $sumExpr = "SUM(COALESCE($pay_amt_col,0)".($pay_has_disc?("-COALESCE(discount,0)"):"").")";
  $sqlp="SELECT $pay_col_inv AS iid, $sumExpr AS paid_sum FROM $pay_tbl WHERE $pay_col_inv IN ($in) GROUP BY $pay_col_inv";
  $stp=$pdo->prepare($sqlp); $stp->execute($ids);
  while($r=$stp->fetch(PDO::FETCH_ASSOC)) $paid_map[(int)$r['iid']] = (float)$r['paid_sum'];
}

/* ---------------- Recent Payments (last 30) ---------------- */
$pw = []; $pa = [];
// client filter for payments: try FK to clients.id
$pClientCol=null;
foreach(['client_id','customer_id','subscriber_id','user_id','client','cid'] as $c){ if(col_exists($pdo,$pay_tbl,$c)){ $pClientCol=$c; break; } }
if($pClientCol){ $pw[]="p.`$pClientCol`=?"; $pa[]=$client_id; }
else{
  // fall back by identifiers (same strategy)
  $matched=false;
  foreach([ 'client_code','pppoe_id','email','mobile','name' ] as $c){
    if(($CLIENT[$c]??null) && col_exists($pdo,$pay_tbl,$c)){ $pw[]="p.`$c`=?"; $pa[]=$CLIENT[$c]; $matched=true; break; }
  }
  if(!$matched){ $pw[]="1=0"; }
}
$pw_sql = $pw?('WHERE '.implode(' AND ',$pw)):'';
$selp = ["p.id","COALESCE(p.$pay_date_col, p.created_at) AS pdate","COALESCE(p.$pay_amt_col,0) AS amount"];
if($pay_has_disc) $selp[]="COALESCE(p.discount,0) AS discount";
if($pay_meth_col) $selp[]="p.$pay_meth_col AS method";
if($pay_ref_col)  $selp[]="p.$pay_ref_col  AS reference";
if($pay_col_inv)  $selp[]="p.$pay_col_inv  AS invoice_id";
$sqlR="SELECT ".implode(', ',$selp)." FROM $pay_tbl p $pw_sql ORDER BY pdate DESC, p.id DESC LIMIT 30";
$str=$pdo->prepare($sqlR); $str->execute($pa); $payments=$str->fetchAll(PDO::FETCH_ASSOC);

/* ---------------- UI ---------------- */
$page_title = "Billing Overview";
$__sidebar = __DIR__ . '/../portal_sidebar.php';
if (is_file($__sidebar)) require_once $__sidebar;
require_once __DIR__ . '/../../partials/partials_header.php';
?>
<div class="container my-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">Billing Overview</h3>
    <div class="d-flex gap-2">
      <a href="/public/portal/bkash.php" class="btn btn-sm btn-danger"><i class="bi bi-phone"></i> Pay via bKash</a>
      <a href="/public/portal/invoices.php" class="btn btn-sm btn-outline-primary">Invoices</a>
      <a href="/public/portal/payments.php" class="btn btn-sm btn-outline-success">Payments</a>
    </div>
  </div>

  <!-- Filters -->
  <form class="row g-2 mb-3" method="get">
    <div class="col-md-3">
      <label class="form-label mb-1">Month</label>
      <input type="month" class="form-control" name="month" value="<?php echo h($month); ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label mb-1">Status</label>
      <select class="form-select" name="status">
        <option value="">All</option>
        <?php foreach(['paid'=>'Paid','partial'=>'Partial','unpaid'=>'Unpaid','due'=>'Due'] as $k=>$v): ?>
          <option value="<?php echo h($k); ?>" <?php echo ($status===$k?'selected':''); ?>><?php echo h($v); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label mb-1">Search</label>
      <input type="text" class="form-control" name="q" value="<?php echo h($search); ?>" placeholder="Invoice no / notes">
    </div>
    <div class="col-md-2 d-flex align-items-end">
      <button class="btn btn-primary w-100" type="submit">Apply</button>
    </div>
  </form>

  <?php
    $sum_total=0.0; $sum_paid=0.0;
    foreach($invoices as $r){ $iid=(int)$r['id']; $tot=(float)($r['total_amount']??0); $pd=(float)($paid_map[$iid]??0); $sum_total+=$tot; $sum_paid+=$pd; }
  ?>
  <div class="d-flex flex-wrap gap-2 mb-3">
    <span class="badge text-bg-secondary p-2">Invoices: <?php echo h($total_rows); ?></span>
    <span class="badge text-bg-primary p-2">Total (page): <?php echo number_format($sum_total,2); ?></span>
    <span class="badge text-bg-success p-2">Paid (page): <?php echo number_format($sum_paid,2); ?></span>
    <span class="badge text-bg-danger p-2">Due (page): <?php echo number_format(max(0,$sum_total-$sum_paid),2); ?></span>
  </div>

  <div class="table-responsive mb-4">
    <table class="table table-sm align-middle">
      <thead class="table-light">
        <tr>
          <th style="width: 110px;">Invoice</th>
          <th style="width: 110px;">Month</th>
          <th class="text-end" style="width: 120px;">Total</th>
          <th class="text-end" style="width: 120px;">Paid</th>
          <th class="text-end" style="width: 120px;">Due</th>
          <th style="width: 100px;">Status</th>
          <th style="width: 120px;">Action</th>
        </tr>
      </thead>
      <tbody>
      <?php if(!$invoices): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">No invoices found.</td></tr>
      <?php else: foreach($invoices as $r):
        $iid=(int)$r['id']; $tot=(float)($r['total_amount']??0); $pd=(float)($paid_map[$iid]??0);
        $due=max(0.0,$tot-$pd); $st=trim((string)($r['status']??''));
        $badge='secondary';
        if($st!==''){ if(strcasecmp($st,'paid')===0)$badge='success'; elseif(strcasecmp($st,'partial')===0)$badge='warning'; elseif(strcasecmp($st,'unpaid')===0||strcasecmp($st,'due')===0)$badge='danger'; }
        else{ $badge=($due<=0.00001)?'success':(($pd>0)?'warning':'danger'); $st=($due<=0.00001)?'Paid':(($pd>0)?'Partial':'Unpaid'); }
      ?>
        <tr>
          <td><?php echo h($r['invoice_no'] ?? ('#'.$iid)); ?></td>
          <td><?php echo h($r['ym'] ?? ''); ?></td>
          <td class="text-end"><?php echo number_format($tot,2); ?></td>
          <td class="text-end"><?php echo number_format($pd,2); ?></td>
          <td class="text-end"><?php echo number_format($due,2); ?></td>
          <td><span class="badge text-bg-<?php echo h($badge); ?>"><?php echo h(ucfirst($st)); ?></span></td>
          <td>
            <div class="btn-group btn-group-sm">
              <a class="btn btn-outline-primary" href="/public/invoices.php?view=<?php echo $iid; ?>" target="_blank">View</a>
              <a class="btn btn-outline-danger" href="/public/portal/bkash.php" target="_blank">Pay</a>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if($total_pages>1): ?>
  <nav aria-label="Invoices pages">
    <ul class="pagination pagination-sm">
      <?php $q=$_GET; for($p=1;$p<=$total_pages;$p++){ $q['page']=$p; $url='?'.http_build_query($q); $active=($p===$page)?'active':''; ?>
        <li class="page-item <?php echo $active; ?>"><a class="page-link" href="<?php echo h($url); ?>"><?php echo $p; ?></a></li>
      <?php } ?>
    </ul>
  </nav>
  <?php endif; ?>

  <!-- Recent payments -->
  <div class="d-flex justify-content-between align-items-center mt-4 mb-2">
    <h5 class="mb-0">Recent Payments</h5>
    <a class="btn btn-sm btn-outline-success" href="/public/portal/payments.php">View all</a>
  </div>

  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead class="table-light">
        <tr>
          <th style="width:160px;">Date</th>
          <th class="text-end" style="width:120px;">Amount</th>
          <?php if($pay_has_disc): ?><th class="text-end" style="width:120px;">Discount</th><?php endif; ?>
          <?php if($pay_meth_col): ?><th style="width:140px;">Method</th><?php endif; ?>
          <?php if($pay_ref_col): ?><th>Reference</th><?php endif; ?>
          <?php if($pay_col_inv): ?><th style="width:110px;">Invoice</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php if(!$payments): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">No payments found.</td></tr>
      <?php else: foreach($payments as $p):
        $amt=(float)($p['amount']??0); $disc=$pay_has_disc? (float)($p['discount']??0):0;
        $net=$amt-($pay_has_disc?$disc:0);
      ?>
        <tr>
          <td><?php echo h($p['pdate'] ?? ''); ?></td>
          <td class="text-end"><?php echo number_format($amt,2); ?></td>
          <?php if($pay_has_disc): ?><td class="text-end"><?php echo number_format($disc,2); ?></td><?php endif; ?>
          <?php if($pay_meth_col): ?><td><?php echo h($p['method'] ?? ''); ?></td><?php endif; ?>
          <?php if($pay_ref_col): ?><td><?php echo h($p['reference'] ?? ''); ?></td><?php endif; ?>
          <?php if($pay_col_inv): ?><td>#<?php echo h($p['invoice_id'] ?? ''); ?></td><?php endif; ?>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div class="alert alert-info mt-3">
    <i class="bi bi-info-circle"></i>
    Totals above are page-scope. For statement/export, go to <a class="alert-link" href="/public/portal/invoices.php">Invoices</a> or <a class="alert-link" href="/public/portal/payments.php">Payments</a>.
  </div>
</div>

<?php require_once __DIR__ . '/../../partials/partials_footer.php'; ?>
