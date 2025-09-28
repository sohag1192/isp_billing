<?php
// /public/report_payments.php
// UI: English; Comments: বাংলা — সকল পেমেন্ট রিপোর্ট (Wallet/Account/Collector দেখাবে) + CSV এক্সপোর্ট

declare(strict_types=1);
require_once __DIR__ . '/../partials/partials_header.php';
require_once __DIR__ . '/../app/db.php';

$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function tbl_exists(PDO $pdo, string $t): bool { try{ $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); return true; }catch(Throwable $e){ return false; } }
function col_exists(PDO $pdo, string $t, string $c): bool { try{ $cols=$pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN)?:[]; return in_array($c,$cols,true);}catch(Throwable $e){ return false; } }

$T_PAY='payments';
if(!tbl_exists($pdo,$T_PAY)){ echo '<div class="container my-4"><div class="alert alert-danger">payments table not found.</div></div>'; require_once __DIR__.'/../partials/partials_footer.php'; exit; }

$HAS_ACC = col_exists($pdo,$T_PAY,'account_id');
$HAS_RECV= col_exists($pdo,$T_PAY,'received_by');
$HAS_REF = col_exists($pdo,$T_PAY,'ref_no') || col_exists($pdo,$T_PAY,'txn_ref');
$REF_COL = col_exists($pdo,$T_PAY,'ref_no') ? 'ref_no' : (col_exists($pdo,$T_PAY,'txn_ref') ? 'txn_ref' : null);
$HAS_PAID= col_exists($pdo,$T_PAY,'paid_at');
$DATE_COL= $HAS_PAID ? 'paid_at' : (col_exists($pdo,$T_PAY,'created_at') ? 'created_at' : null);

/* ---------- filters ---------- */
$q = trim((string)($_GET['q'] ?? ''));
$method = trim((string)($_GET['method'] ?? ''));
$wallet_scope = trim((string)($_GET['wallet'] ?? 'all')); // all|company|user
$from = trim((string)($_GET['from'] ?? date('Y-m-01')));
$to   = trim((string)($_GET['to'] ?? date('Y-m-d')));
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = min(300, max(10,(int)($_GET['per_page'] ?? 50)));
$export = strtolower((string)($_GET['export'] ?? ''));

/* ---------- joins ---------- */
$cols = ['p.id','p.amount'];
if($DATE_COL) $cols[] = "p.`$DATE_COL` AS paid_on";
if(col_exists($pdo,$T_PAY,'method')) $cols[] = "p.method";
if($HAS_REF && $REF_COL) $cols[] = "p.`$REF_COL` AS ref_no";

$join=''; $uPick=null;
if($HAS_ACC && tbl_exists($pdo,'accounts')){
  $cols[]="a.id AS account_id";
  if(col_exists($pdo,'accounts','name')) $cols[]="a.name AS account_name";
  if(col_exists($pdo,'accounts','user_id')) $cols[]="a.user_id AS wallet_user_id";
  if(col_exists($pdo,'accounts','type'))   $cols[]="a.type AS account_type";
  $join.=" LEFT JOIN accounts a ON a.id=p.account_id ";
}
if($HAS_RECV && tbl_exists($pdo,'users')){
  $ucols=$pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
  $uPick='name'; foreach(['name','full_name','username','email'] as $c) if(in_array($c,$ucols,true)){ $uPick=$c; break; }
  $cols[]="u.`$uPick` AS received_by_name";
  $cols[]="u.id AS received_by";
  $join.=" LEFT JOIN users u ON u.id=p.received_by ";
}

/* ---------- where ---------- */
$where=[]; $args=[];
if($DATE_COL){ $where[]="DATE(p.`$DATE_COL`) BETWEEN ? AND ?"; $args[]=$from; $args[]=$to; }
if($q!==''){
  $w=["p.amount LIKE ?"];
  if($HAS_REF && $REF_COL) $w[]="p.`$REF_COL` LIKE ?";
  if(col_exists($pdo,$T_PAY,'method')) $w[]="p.method LIKE ?";
  $args=array_merge($args,array_fill(0,count($w),'%'.$q.'%'));
  $where[]='('.implode(' OR ',$w).')';
}
if($method!==''){ $where[]="p.method=?"; $args[]=$method; }
if($wallet_scope!=='all' && $HAS_ACC && tbl_exists($pdo,'accounts')){
  if($wallet_scope==='company'){
    if(col_exists($pdo,'accounts','user_id')) $where[]="(a.user_id IS NULL)";
    elseif(col_exists($pdo,'accounts','type')) $where[]="(a.type IN('company','vault'))";
  }elseif($wallet_scope==='user'){
    if(col_exists($pdo,'accounts','user_id')) $where[]="(a.user_id IS NOT NULL)";
    elseif(col_exists($pdo,'accounts','type')) $where[]="(a.type='user')";
  }
}
$sql_where = $where ? (' WHERE '.implode(' AND ',$where)) : '';

/* ---------- count ---------- */
$cnt=$pdo->prepare("SELECT COUNT(1) FROM `$T_PAY` p $join $sql_where");
$cnt->execute($args); $total=(int)$cnt->fetchColumn();
$pages=max(1,(int)ceil($total/$per)); $page=min($page,$pages); $off=($page-1)*$per;

/* ---------- list ---------- */
$list_sql="SELECT ".implode(',',$cols)." FROM `$T_PAY` p $join $sql_where ORDER BY p.id DESC";
if($export!=='csv') $list_sql.=" LIMIT $per OFFSET $off";
$st=$pdo->prepare($list_sql); $st->execute($args);

/* ---------- CSV export ---------- */
if($export==='csv'){
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename=payments_'.date('Ymd_His').'.csv');
  $out=fopen('php://output','w'); fprintf($out,"\xEF\xBB\xBF");
  $hdr=['ID','Date','Amount','Method','Ref','Credited To','Received By'];
  fputcsv($out,$hdr);
  while($r=$st->fetch(PDO::FETCH_ASSOC)){
    $label = '';
    if(!empty($r['account_id'])){
      $nm = (string)($r['account_name']??'');
      $uid = isset($r['wallet_user_id'])?(int)$r['wallet_user_id']:null;
      $label = $uid && $uid>0 ? ('User Wallet ('.($nm!==''?$nm:('#'.$uid)).')') : ('Company/Vault '.($nm!==''?'('.$nm.')':'(#'.$r['account_id'].')'));
    }
    $line=[
      (int)$r['id'],
      isset($r['paid_on'])?date('Y-m-d H:i',strtotime((string)$r['paid_on'])):'',
      number_format((float)$r['amount'],2),
      (string)($r['method']??''),
      (string)($r['ref_no']??''),
      $label,
      (string)($r['received_by_name'] ?? ('User#'.(int)($r['received_by']??0))),
    ];
    fputcsv($out,$line);
  }
  fclose($out); exit;
}
$rows=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* ---------- total ---------- */
$total_amt = 0.0; foreach($rows as $r) $total_amt += (float)$r['amount'];
?>
<div class="container-fluid py-3">
  <div class="d-flex align-items-center justify-content-between mb-2">
    <h5 class="mb-0">Payments Report</h5>
    <div class="btn-group">
      <a class="btn btn-outline-success btn-sm" href="<?php
        $p=array_merge($_GET,['export'=>'csv','page'=>1]); echo '?'.http_build_query($p); ?>">
        <i class="bi bi-filetype-csv"></i> Export CSV
      </a>
    </div>
  </div>

  <form class="row g-2 mb-3" method="get">
    <div class="col-12 col-sm-2"><input type="date" name="from" class="form-control" value="<?php echo h($from); ?>"></div>
    <div class="col-12 col-sm-2"><input type="date" name="to"   class="form-control" value="<?php echo h($to); ?>"></div>
    <div class="col-12 col-sm-2">
      <select name="method" class="form-select">
        <option value="">All methods</option>
        <?php foreach(['Cash','bKash','Nagad','Bank','Online'] as $m): ?>
          <option value="<?php echo $m; ?>" <?php echo $method===$m?'selected':''; ?>><?php echo $m; ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-12 col-sm-2">
      <select name="wallet" class="form-select">
        <option value="all" <?php echo $wallet_scope==='all'?'selected':''; ?>>All wallets</option>
        <option value="company" <?php echo $wallet_scope==='company'?'selected':''; ?>>Company/Vault</option>
        <option value="user" <?php echo $wallet_scope==='user'?'selected':''; ?>>User Wallets</option>
      </select>
    </div>
    <div class="col-12 col-sm-3"><input class="form-control" name="q" value="<?php echo h($q); ?>" placeholder="Search (amount/ref/method)"></div>
    <div class="col-6 col-sm-1"><input type="number" name="per_page" class="form-control" value="<?php echo (int)$per; ?>" min="10" max="300"></div>
    <div class="col-6 col-sm-1 d-grid"><button class="btn btn-secondary">Apply</button></div>
  </form>

  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle">
      <thead class="table-light">
        <tr>
          <th>ID</th>
          <?php if($DATE_COL): ?><th>Date</th><?php endif; ?>
          <th class="text-end">Amount</th>
          <?php if(col_exists($pdo,$T_PAY,'method')): ?><th>Method</th><?php endif; ?>
          <?php if($HAS_REF && $REF_COL): ?><th>Ref</th><?php endif; ?>
          <?php if($HAS_ACC): ?><th>Credited To</th><?php endif; ?>
          <?php if($HAS_RECV): ?><th>Received By</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php if(!$rows): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">No payments found.</td></tr>
        <?php else: foreach($rows as $r): ?>
          <?php
            $accLabel = '';
            if(!empty($r['account_id'])){
              $nm = (string)($r['account_name']??'');
              $uid = isset($r['wallet_user_id'])?(int)$r['wallet_user_id']:null;
              $accLabel = $uid && $uid>0 ? ($nm!==''?"User Wallet ($nm)":"User Wallet (#$uid)")
                                         : ($nm!==''?"Company/Vault ($nm)":"Company/Vault (#".(int)$r['account_id'].")");
            }
          ?>
          <tr>
            <td><?php echo (int)$r['id']; ?></td>
            <?php if($DATE_COL): ?><td><?php echo h(isset($r['paid_on'])?date('Y-m-d H:i', strtotime((string)$r['paid_on'])):''); ?></td><?php endif; ?>
            <td class="text-end"><?php echo number_format((float)$r['amount'],2); ?></td>
            <?php if(col_exists($pdo,$T_PAY,'method')): ?><td><?php echo h((string)($r['method']??'')); ?></td><?php endif; ?>
            <?php if($HAS_REF && $REF_COL): ?><td><?php echo h((string)($r['ref_no']??'')); ?></td><?php endif; ?>
            <?php if($HAS_ACC): ?><td><?php echo h($accLabel); ?></td><?php endif; ?>
            <?php if($HAS_RECV): ?><td><?php echo h((string)($r['received_by_name'] ?? ('User#'.(int)($r['received_by']??0)))); ?></td><?php endif; ?>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
      <tfoot>
        <tr class="table-light">
          <th colspan="<?php echo 1 + ($DATE_COL?1:0); ?>">Total (this page)</th>
          <th class="text-end"><?php echo number_format($total_amt,2); ?></th>
          <th colspan="<?php echo (col_exists($pdo,$T_PAY,'method')?1:0) + ($HAS_REF&&$REF_COL?1:0) + ($HAS_ACC?1:0) + ($HAS_RECV?1:0); ?>"></th>
        </tr>
      </tfoot>
    </table>
  </div>

  <div class="d-flex justify-content-between align-items-center">
    <div class="small text-muted">Showing <?php echo count($rows); ?> of <?php echo (int)$total; ?> item(s)</div>
    <ul class="pagination pagination-sm mb-0">
      <?php
      $qs=function($o){ $p=array_merge($_GET,$o); return '?'.http_build_query($p); };
      $btn=function($p,$lbl,$dis=false,$act=false) use($qs){
        echo '<li class="page-item'.($dis?' disabled':'').($act?' active':'').'"><a class="page-link" href="'.($dis?'#':h($qs(['page'=>$p]))).'">'.h($lbl).'</a></li>';
      };
      $btn(1,'«',$page<=1); $btn(max(1,$page-1),'‹',$page<=1);
      for($i=max(1,$page-2);$i<=min($pages,$page+2);$i++) $btn($i,(string)$i,false,$i===$page);
      $btn(min($pages,$page+1),'›',$page>=$pages); $btn($pages,'»',$page>=$pages);
      ?>
    </ul>
  </div>
</div>
<?php require_once __DIR__ . '/../partials/partials_footer.php'; ?>
