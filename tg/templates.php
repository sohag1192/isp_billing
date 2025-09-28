<?php
// /tg/templates.php
// UI: English; Comments: বাংলা — Telegram message templates manager (CRUD + activate)
// Stack: PHP (procedural + PDO), Bootstrap 5

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');

$ROOT = dirname(__DIR__, 1);
require_once $ROOT . '/app/require_login.php';
require_once $ROOT . '/app/db.php';

// (Optional) ACL — না থাকলে সবসময় allow
$acl_file = $ROOT . '/app/acl.php';
if (is_file($acl_file)) require_once $acl_file;
$can_manage = function_exists('acl_can') ? acl_can('tg.manage') : true;
if (!$can_manage) { http_response_code(403); echo 'Forbidden'; exit; }

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
$CSRF = (string)$_SESSION['csrf'];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function flash_set(string $k, string $v){ $_SESSION["flash_$k"]=$v; }
function flash_get(string $k): ?string { $x=$_SESSION["flash_$k"]??null; unset($_SESSION["flash_$k"]); return $x? (string)$x : null; }

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ----------------- helpers: schema ----------------- */
function tbl_exists(PDO $pdo, string $t): bool{
  try{ $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
       $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
       $q->execute([$db,$t]); return (bool)$q->fetchColumn();
  }catch(Throwable $e){ return false; }
}
function col_exists(PDO $pdo, string $t, string $c): bool{
  try{ $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
       $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
       $q->execute([$db,$t,$c]); return (bool)$q->fetchColumn();
  }catch(Throwable $e){ return false; }
}
function idx_exists(PDO $pdo, string $t, string $idx): bool{
  try{
    $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
    $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=?");
    $q->execute([$db,$t,$idx]); return (bool)$q->fetchColumn();
  }catch(Throwable $e){ return false; }
}
function ensure_templates_table(PDO $pdo){
  // বাংলা: টেবিল না থাকলে বানাই (modern schema)
  if (!tbl_exists($pdo,'telegram_templates')) {
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS telegram_templates (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        template_key VARCHAR(64) NOT NULL,
        body TEXT NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        INDEX idx_key (template_key)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
  }

  // বাংলা: পুরনো টেবিল হলে মিসিং কলামগুলো যোগ করি (silent)
  try{ if(!col_exists($pdo,'telegram_templates','active'))    $pdo->exec("ALTER TABLE telegram_templates ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1"); }catch(Throwable $e){}
  try{ if(!col_exists($pdo,'telegram_templates','created_at'))$pdo->exec("ALTER TABLE telegram_templates ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"); }catch(Throwable $e){}
  try{ if(!col_exists($pdo,'telegram_templates','updated_at'))$pdo->exec("ALTER TABLE telegram_templates ADD COLUMN updated_at DATETIME NULL"); }catch(Throwable $e){}

  // Optional unique per (template_key, active) => conflict থাকলে fail করবে; তাই soft index (try/catch)
  if (!idx_exists($pdo,'telegram_templates','uk_active')) {
    try { $pdo->exec("ALTER TABLE telegram_templates ADD UNIQUE KEY uk_active (template_key, active)"); } catch(Throwable $e){}
  }

  // seed if empty
  try {
    $c = (int)$pdo->query("SELECT COUNT(1) FROM telegram_templates")->fetchColumn();
    if ($c === 0) {
      $pdo->prepare("INSERT INTO telegram_templates (template_key, body, active, created_at) VALUES
        ('due_reminder', 'প্রিয় {{name}}, আপনার বিল বকেয়া {{amount}} টাকা। পরিশোধ করুন: {{pay_link}}', 1, NOW()),
        ('payment_confirm', '{{name}}, আপনার {{amount}} টাকা পেমেন্ট পেয়েছি। ধন্যবাদ।', 1, NOW())
      ")->execute();
    }
  } catch(Throwable $e){}
}
ensure_templates_table($pdo);

/* ----------------- inputs ----------------- */
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$id     = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

// বাংলা: POST অ্যাকশনগুলো হ্যান্ডেল
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($CSRF, (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(400); echo 'Bad CSRF'; exit;
  }

  if ($action === 'save') {
    $template_key = trim((string)($_POST['template_key'] ?? ''));
    $body         = (string)($_POST['body'] ?? '');
    $active       = (int)($_POST['active'] ?? 0) === 1 ? 1 : 0;

    if ($template_key === '' || $body === '') {
      flash_set('error','Template key and body are required.');
      header('Location: /tg/templates.php'.($id?('?action=edit&id='.(int)$id):'')); exit;
    }

    $has_updated = col_exists($pdo,'telegram_templates','updated_at');
    $has_created = col_exists($pdo,'telegram_templates','created_at');

    try{ $pdo->beginTransaction(); }catch(Throwable $e){}

    if ($id > 0) {
      // update (updated_at only if exists)
      $sql = "UPDATE telegram_templates SET template_key=?, body=?, active=?"
           . ($has_updated ? ", updated_at=NOW()" : "")
           . " WHERE id=?";
      $st = $pdo->prepare($sql);
      $st->execute([$template_key, $body, $active, $id]);
    } else {
      // insert (created_at only if exists)
      $sql = "INSERT INTO telegram_templates (template_key, body, active"
           . ($has_created ? ", created_at" : "")
           . ") VALUES (?,?,?"
           . ($has_created ? ", NOW()" : "")
           . ")";
      $st = $pdo->prepare($sql);
      $st->execute([$template_key, $body, $active]);
      $id = (int)$pdo->lastInsertId();
    }

    // বাংলা: active=1 হলে একই key-র অন্যান্যগুলো inactive
    if ($active === 1) {
      $sql = "UPDATE telegram_templates SET active=0"
           . ($has_updated ? ", updated_at=NOW()" : "")
           . " WHERE template_key=? AND id<>?";
      $u = $pdo->prepare($sql);
      $u->execute([$template_key, $id]);
    }

    try{ if($pdo->inTransaction()) $pdo->commit(); }catch(Throwable $e){}

    flash_set('success','Template saved.');
    header('Location: /tg/templates.php'); exit;
  }

  if ($action === 'activate' && $id>0) {
    $has_updated = col_exists($pdo,'telegram_templates','updated_at');
    $st=$pdo->prepare("SELECT template_key FROM telegram_templates WHERE id=? LIMIT 1");
    $st->execute([$id]); $key=(string)($st->fetchColumn() ?: '');
    if ($key!=='') {
      try{ $pdo->beginTransaction(); }catch(Throwable $e){}
      $pdo->prepare("UPDATE telegram_templates SET active=0".($has_updated?", updated_at=NOW()":"")." WHERE template_key=?")->execute([$key]);
      $pdo->prepare("UPDATE telegram_templates SET active=1".($has_updated?", updated_at=NOW()":"")." WHERE id=?")->execute([$id]);
      try{ if($pdo->inTransaction()) $pdo->commit(); }catch(Throwable $e){}
      flash_set('success','Activated.');
    }
    header('Location: /tg/templates.php'); exit;
  }

  if ($action === 'deactivate' && $id>0) {
    $has_updated = col_exists($pdo,'telegram_templates','updated_at');
    $pdo->prepare("UPDATE telegram_templates SET active=0".($has_updated?", updated_at=NOW()":"")." WHERE id=?")->execute([$id]);
    flash_set('success','Deactivated.');
    header('Location: /tg/templates.php'); exit;
  }

  if ($action === 'duplicate' && $id>0) {
    $has_created = col_exists($pdo,'telegram_templates','created_at');
    $st=$pdo->prepare("SELECT template_key, body FROM telegram_templates WHERE id=? LIMIT 1");
    $st->execute([$id]); $row=$st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      $sql = "INSERT INTO telegram_templates (template_key, body, active"
           . ($has_created ? ", created_at" : "")
           . ") VALUES (?,?,0"
           . ($has_created ? ", NOW()" : "")
           . ")";
      $pdo->prepare($sql)->execute([$row['template_key'], $row['body']]);
      flash_set('success','Duplicated (inactive copy created).');
    }
    header('Location: /tg/templates.php'); exit;
  }

  if ($action === 'delete' && $id>0) {
    // বাংলা: hard delete না—inactive করে দেই
    $has_updated = col_exists($pdo,'telegram_templates','updated_at');
    $pdo->prepare("UPDATE telegram_templates SET active=0".($has_updated?", updated_at=NOW()":"")." WHERE id=?")->execute([$id]);
    flash_set('success','Marked inactive.');
    header('Location: /tg/templates.php'); exit;
  }

  // fallback
  header('Location: /tg/templates.php'); exit;
}

/* ----------------- GET views ----------------- */
$editing = ($action==='edit' && $id>0);

// load editing row (if any)
$edit_row = null;
if ($editing) {
  $st=$pdo->prepare("SELECT * FROM telegram_templates WHERE id=? LIMIT 1");
  $st->execute([$id]);
  $edit_row=$st->fetch(PDO::FETCH_ASSOC) ?: null;
  if (!$edit_row) { $editing=false; }
}

// list rows (with search/filter)
$q = trim((string)($_GET['q'] ?? ''));
$where = []; $args=[];
if ($q!=='') { $where[]="(template_key LIKE ? OR body LIKE ?)"; $args[]="%$q%"; $args[]="%$q%"; }
$sql = "SELECT * FROM telegram_templates".
       ($where?(" WHERE ".implode(' AND ',$where)):"").
       " ORDER BY template_key ASC, active DESC, id DESC";
$rows = $pdo->prepare($sql); $rows->execute($args); $rows = $rows->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Helpers: placeholder help


$help_vars = [
  '{{name}}','{{client_id}}','{{pppoe}}','{{amount}}','{{invoice_id}}',
  '{{pay_link}}','{{portal_link}}','{{message}}',
  // NEW:
  '{{method}}','{{txn_id}}','{{paid_at}}','{{receiver}}','{{received_by}}','{{received_by_name}}'
];









require_once $ROOT . '/partials/partials_header.php';
?>
<div class="container-fluid py-3">
  <div class="d-flex align-items-center justify-content-between mb-2">
    <h4 class="mb-0">Telegram Templates</h4>
    <form class="d-flex" method="get" action="/tg/templates.php">
      <input type="text" name="q" class="form-control form-control-sm me-2" value="<?php echo h($q); ?>" placeholder="Search key/body">
      <button class="btn btn-sm btn-outline-primary"><i class="bi bi-search"></i> Search</button>
    </form>
  </div>

  <?php if($m=flash_get('error')): ?>
    <div class="alert alert-danger py-2"><?php echo h($m); ?></div>
  <?php endif; ?>
  <?php if($m=flash_get('success')): ?>
    <div class="alert alert-success py-2"><?php echo h($m); ?></div>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-lg-7">
      <div class="card">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
          <strong>Templates</strong>
          <span class="text-muted small">Active one per key</span>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
              <thead class="table-light">
                <tr>
                  <th style="width:140px">Key</th>
                  <th>Body (first 120 chars)</th>
                  <th style="width:90px" class="text-center">Active</th>
                  <th style="width:210px">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php if (!$rows): ?>
                <tr><td colspan="4" class="text-center text-muted py-4">No templates found.</td></tr>
              <?php else: foreach($rows as $r): ?>
                <tr>
                  <td class="fw-semibold"><?php echo h((string)$r['template_key']); ?></td>
                  <td class="text-muted"><?php
                    $snip = mb_substr((string)$r['body'],0,120);
                    echo nl2br(h($snip)).(mb_strlen((string)$r['body'])>120?'…':'');
                  ?></td>
                  <td class="text-center">
                    <?php if((int)$r['active']===1): ?>
                      <span class="badge bg-success">Yes</span>
                    <?php else: ?>
                      <span class="badge bg-secondary">No</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div class="d-flex gap-1 flex-wrap">
                      <a class="btn btn-sm btn-outline-primary" href="/tg/templates.php?action=edit&id=<?php echo (int)$r['id']; ?>">
                        <i class="bi bi-pencil"></i> Edit
                      </a>
                      <?php if ((int)$r['active']===1): ?>
                        <form method="post" class="d-inline">
                          <input type="hidden" name="csrf_token" value="<?php echo h($CSRF); ?>">
                          <input type="hidden" name="action" value="deactivate">
                          <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                          <button class="btn btn-sm btn-outline-warning"><i class="bi bi-pause"></i> Deactivate</button>
                        </form>
                      <?php else: ?>
                        <form method="post" class="d-inline">
                          <input type="hidden" name="csrf_token" value="<?php echo h($CSRF); ?>">
                          <input type="hidden" name="action" value="activate">
                          <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                          <button class="btn btn-sm btn-outline-success"><i class="bi bi-check2-circle"></i> Activate</button>
                        </form>
                      <?php endif; ?>
                      <form method="post" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo h($CSRF); ?>">
                        <input type="hidden" name="action" value="duplicate">
                        <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                        <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-files"></i> Duplicate</button>
                      </form>
                      <form method="post" class="d-inline" onsubmit="return confirm('Mark this template inactive?')">
                        <input type="hidden" name="csrf_token" value="<?php echo h($CSRF); ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Delete</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
        <div class="card-footer small text-muted">
          Placeholders: <?php foreach($help_vars as $v){ echo '<code class="me-1">'.h($v).'</code>'; } ?>
        </div>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="card">
        <div class="card-header bg-light">
          <strong><?php echo $editing ? 'Edit Template' : 'Create Template'; ?></strong>
        </div>
        <div class="card-body">
          <form method="post" action="/tg/templates.php">
            <input type="hidden" name="csrf_token" value="<?php echo h($CSRF); ?>">
            <input type="hidden" name="action" value="save">
            <?php if($editing): ?>
              <input type="hidden" name="id" value="<?php echo (int)$edit_row['id']; ?>">
            <?php endif; ?>

            <div class="mb-2">
              <label class="form-label">Template Key <span class="text-danger">*</span></label>
              <input type="text" name="template_key" class="form-control form-control-sm" maxlength="64"
                     value="<?php echo h($editing ? (string)$edit_row['template_key'] : ''); ?>"
                     placeholder="e.g. due_reminder, payment_confirm" required>
              <div class="form-text">Use snake_case keys. Active one per key is used.</div>
            </div>

            <div class="mb-2">
              <label class="form-label">Body <span class="text-danger">*</span></label>
              <textarea name="body" rows="10" class="form-control" placeholder="Write template body..." required><?php
                echo h($editing ? (string)$edit_row['body'] : '');
              ?></textarea>
              <div class="form-text">
                HTML allowed (bot parse mode). Placeholders:
                <?php foreach($help_vars as $v){ echo '<code class="me-1">'.h($v).'</code>'; } ?>
              </div>
            </div>

            <div class="form-check form-switch mb-3">
              <?php $actDefault = $editing ? (int)$edit_row['active']===1 : 1; ?>
              <input class="form-check-input" type="checkbox" id="active" name="active" value="1" <?php echo $actDefault?'checked':''; ?>>
              <label class="form-check-label" for="active">Active</label>
            </div>

            <div class="d-flex gap-2">
              <button class="btn btn-primary"><i class="bi bi-save"></i> Save</button>
              <?php if($editing): ?>
                <a class="btn btn-outline-secondary" href="/tg/templates.php"><i class="bi bi-plus-circle"></i> New</a>
              <?php else: ?>
                <button class="btn btn-outline-secondary" type="reset">Reset</button>
              <?php endif; ?>
            </div>
          </form>
        </div>
        <div class="card-footer small text-muted">
          Tip: Activating a template for a key will auto-deactivate older ones of the same key.
        </div>
      </div>
    </div>
  </div>
</div>
<?php require_once $ROOT . '/partials/partials_footer.php'; ?>
