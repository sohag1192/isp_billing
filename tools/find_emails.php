<?php
// /tools/find_emails.php
// UI: English; Comments: বাংলা — Scan DB for email-like values + inline Edit/Delete actions.

declare(strict_types=1);
require_once __DIR__ . '/../app/db.php';

/* ------------- bootstrap + helpers ------------- */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf_token'];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function dbh(): PDO { $pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); return $pdo; }

/* (বাংলা) টেবিলে প্রাইমারি কি সনাক্তকরণ: একক কলাম হলেই OK */
function detect_pk(PDO $pdo, string $tbl): ?string {
  $sql = "SELECT COLUMN_NAME
          FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND CONSTRAINT_NAME = 'PRIMARY'
          ORDER BY ORDINAL_POSITION";
  $st = $pdo->prepare($sql); $st->execute([$tbl]);
  $pks = $st->fetchAll(PDO::FETCH_COLUMN);
  return (count($pks) === 1) ? (string)$pks[0] : null;
}

/* (বাংলা) টেবিল/কলাম অস্তিত্ব যাচাই */
function table_exists(PDO $pdo, string $t): bool {
  $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $st->execute([$t]); return (bool)$st->fetchColumn();
}
function column_exists(PDO $pdo, string $t, string $c): bool {
  $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $st->execute([$t,$c]); return (bool)$st->fetchColumn();
}

/* ------------- inputs ------------- */
$pdo  = dbh();
$mode = $_GET['mode'] ?? 'list';
$tbl  = $_GET['tbl']  ?? '';
$col  = $_GET['col']  ?? '';
$msg  = $_GET['msg']  ?? '';
$err  = $_GET['err']  ?? '';

$regex = "^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\\.[A-Za-z]{2,}$"; // MySQL REGEXP for emails

/* ------------- POST actions: update/delete ------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // CSRF
  if (empty($_POST['csrf_token']) || !hash_equals($csrf, (string)$_POST['csrf_token'])) {
    header("Location: ?mode=sample&tbl=".rawurlencode((string)$_POST['tbl'])."&col=".rawurlencode((string)$_POST['col'])."&err=Invalid+CSRF");
    exit;
  }

  $act = $_POST['act'] ?? '';
  $ptbl = $_POST['tbl'] ?? '';
  $pcol = $_POST['col'] ?? '';
  $pkc  = $_POST['pkcol'] ?? '';
  $pkv  = $_POST['pkval'] ?? '';

  // Validate table/column
  if (!table_exists($pdo, $ptbl) || !column_exists($pdo, $ptbl, $pcol)) {
    header("Location: ?mode=sample&tbl=".rawurlencode($ptbl)."&col=".rawurlencode($pcol)."&err=Invalid+table/column");
    exit;
  }
  // Validate PK
  $realPk = detect_pk($pdo, $ptbl);
  if (!$realPk || $realPk !== $pkc) {
    header("Location: ?mode=sample&tbl=".rawurlencode($ptbl)."&col=".rawurlencode($pcol)."&err=Primary+key+not+supported");
    exit;
  }

  try {
    if ($act === 'update') {
      // (বাংলা) শুধু সিলেক্টেড কলাম আপডেট
      $new = (string)($_POST['value'] ?? '');
      $sql = "UPDATE `{$ptbl}` SET `{$pcol}` = ? WHERE `{$pkc}` = ? LIMIT 1";
      $st  = $pdo->prepare($sql);
      $st->execute([$new, $pkv]);
      header("Location: ?mode=sample&tbl=".rawurlencode($ptbl)."&col=".rawurlencode($pcol)."&msg=Updated");
      exit;

    } elseif ($act === 'delete') {
      // (বাংলা) Soft delete সম্ভব হলে; নাহলে hard DELETE
      $cols = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
      $cols->execute([$ptbl]);
      $colList = $cols->fetchAll(PDO::FETCH_COLUMN);

      $hasIsDel  = in_array('is_deleted', $colList, true);
      $hasDelAt  = in_array('deleted_at', $colList, true);
      $hasStatus = in_array('status', $colList, true);

      if ($hasIsDel || $hasDelAt || $hasStatus) {
        $sets = []; $vals = [];
        if ($hasIsDel)  { $sets[] = "`is_deleted` = 1"; }
        if ($hasDelAt)  { $sets[] = "`deleted_at` = NOW()"; }
        if ($hasStatus) { $sets[] = "`status` = 'deleted'"; }
        $sql = "UPDATE `{$ptbl}` SET ".implode(', ', $sets)." WHERE `{$pkc}` = ? LIMIT 1";
        $st  = $pdo->prepare($sql); $st->execute([$pkv]);
        header("Location: ?mode=sample&tbl=".rawurlencode($ptbl)."&col=".rawurlencode($pcol)."&msg=Soft+deleted");
        exit;
      } else {
        // Hard delete (বাংলা: টেস্ট টুল—সাবধানে)
        $sql = "DELETE FROM `{$ptbl}` WHERE `{$pkc}` = ? LIMIT 1";
        $st  = $pdo->prepare($sql); $st->execute([$pkv]);
        header("Location: ?mode=sample&tbl=".rawurlencode($ptbl)."&col=".rawurlencode($pcol)."&msg=Deleted");
        exit;
      }
    }

  } catch (Throwable $e) {
    header("Location: ?mode=sample&tbl=".rawurlencode($ptbl)."&col=".rawurlencode($pcol)."&err=".rawurlencode($e->getMessage()));
    exit;
  }
}

/* ------------- page render ------------- */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><title>Find Emails</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css">
</head>
<body class="p-4">
<div class="container" style="max-width: 980px">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Email columns</h1>
    <a class="btn btn-outline-secondary btn-sm" href="?">Scan again</a>
  </div>

  <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

<?php
if ($mode === 'sample' && $tbl && $col) {
  // (বাংলা) স্যাম্পল ভিউ + ইনলাইন অ্যাকশন
  if (!table_exists($pdo,$tbl) || !column_exists($pdo,$tbl,$col)) {
    echo '<div class="alert alert-danger">Invalid table/column.</div>';
  } else {
    $pk = detect_pk($pdo, $tbl);
    $pkWarn = '';
    if (!$pk) $pkWarn = '<div class="alert alert-warning my-2">No single-column PRIMARY KEY on this table — actions are disabled.</div>';

    // sample rows
    $sql = "SELECT * FROM `{$tbl}` WHERE `{$col}` REGEXP :re LIMIT 100";
    $st  = $pdo->prepare($sql); $st->execute([':re'=>$regex]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    echo '<h5 class="mb-2">'.h($tbl).'.'.h($col).' — sample rows</h5>';
    if ($pkWarn) echo $pkWarn;

    if (!$rows) {
      echo '<div class="alert alert-info">No rows found for this column.</div>';
    } else {
      echo '<div class="table-responsive"><table class="table table-sm table-striped align-middle">';
      echo '<thead><tr>';
      echo '<th>#</th>';
      if ($pk) echo '<th>'.h($pk).'</th>';
      // show selected col first, then a few other cols
      echo '<th>'.h($col).'</th>';

      // extra columns (limit a few)
      $allKeys = array_keys($rows[0]);
      $extras = array_values(array_diff($allKeys, [$col, (string)$pk]));
      $extras = array_slice($extras, 0, 5);
      foreach ($extras as $ex) echo '<th>'.h($ex).'</th>';

      echo '<th class="text-end">Actions</th></tr></thead><tbody>';

      $i=0;
      foreach ($rows as $r) {
        $i++;
        echo '<tr>';
        echo '<td>'.$i.'</td>';
        if ($pk) echo '<td>'.h((string)($r[$pk] ?? '')).'</td>';
        echo '<td>'.h((string)($r[$col] ?? '')).'</td>';
        foreach ($extras as $ex) echo '<td>'.h((string)($r[$ex] ?? '')).'</td>';

        echo '<td class="text-end">';
        if ($pk && isset($r[$pk])) {
          $pkv = (string)$r[$pk];

          // Edit form (inline small)
          echo '<form method="post" class="d-inline me-2" onsubmit="return confirm(\'Apply change?\')">';
          echo '<input type="hidden" name="csrf_token" value="'.h($csrf).'">';
          echo '<input type="hidden" name="act" value="update">';
          echo '<input type="hidden" name="tbl" value="'.h($tbl).'">';
          echo '<input type="hidden" name="col" value="'.h($col).'">';
          echo '<input type="hidden" name="pkcol" value="'.h($pk).'">';
          echo '<input type="hidden" name="pkval" value="'.h($pkv).'">';
          echo '<input type="text" name="value" class="form-control form-control-sm d-inline-block" style="width:220px" value="'.h((string)($r[$col] ?? '')).'" placeholder="new value">';
          echo ' <button class="btn btn-sm btn-primary" type="submit">Edit</button>';
          echo '</form>';

          // Delete form
          echo '<form method="post" class="d-inline" onsubmit="return confirm(\'Delete this row? Soft delete if possible.\')">';
          echo '<input type="hidden" name="csrf_token" value="'.h($csrf).'">';
          echo '<input type="hidden" name="act" value="delete">';
          echo '<input type="hidden" name="tbl" value="'.h($tbl).'">';
          echo '<input type="hidden" name="col" value="'.h($col).'">';
          echo '<input type="hidden" name="pkcol" value="'.h($pk).'">';
          echo '<input type="hidden" name="pkval" value="'.h($pkv).'">';
          echo '<button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>';
          echo '</form>';
        } else {
          echo '<span class="text-muted">No actions</span>';
        }
        echo '</td>';

        echo '</tr>';
      }

      echo '</tbody></table></div>';
    }

    echo '<div class="mt-3"><a class="btn btn-outline-secondary" href="?">← Back</a></div>';
  }

} else {
  // (বাংলা) সার্বিক স্ক্যান: কোন টেবিল/কলামে ইমেইল-সদৃশ ডেটা আছে
  $q = $pdo->prepare("
    SELECT TABLE_NAME, COLUMN_NAME
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND DATA_TYPE IN ('varchar','text','mediumtext','longtext')
  ");
  $q->execute();
  $cols = $q->fetchAll(PDO::FETCH_ASSOC);

  $parts = [];
  foreach ($cols as $c) {
    $t = $c['TABLE_NAME']; $cn = $c['COLUMN_NAME'];
    $parts[] = "SELECT '$t' AS tbl, '$cn' AS col, COUNT(*) AS hits FROM `$t` WHERE `$cn` REGEXP ".$pdo->quote($regex);
  }
  $sql = "SELECT * FROM (".implode(" UNION ALL ", $parts).") x WHERE hits > 0 ORDER BY hits DESC";
  $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

  if (!$rows) {
    echo '<div class="alert alert-warning">No email-like values found. Try username columns or adjust regex.</div>';
  } else {
    echo '<div class="table-responsive"><table class="table table-sm table-striped align-middle">';
    echo '<thead><tr><th>Table</th><th>Column</th><th class="text-end">Rows</th><th></th></tr></thead><tbody>';
    foreach ($rows as $r) {
      $u = '?mode=sample&tbl='.urlencode($r['tbl']).'&col='.urlencode($r['col']);
      echo '<tr>';
      echo '<td>'.h($r['tbl']).'</td>';
      echo '<td>'.h($r['col']).'</td>';
      echo '<td class="text-end">'.(int)$r['hits'].'</td>';
      echo '<td><a class="btn btn-sm btn-outline-primary" href="'.$u.'">View & Edit</a></td>';
      echo '</tr>';
    }
    echo '</tbody></table></div>';
  }

  echo '<hr><div class="small text-muted">';
  echo 'Tips: Many schemas store email in <code>users.email</code> or even <code>users.username</code>. ';
  echo 'Also check <code>user_registrations</code> and <code>password_resets</code>.';
  echo '</div>';
}
?>
</div>
</body>
</html>
