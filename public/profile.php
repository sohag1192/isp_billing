<?php
// /public/profile.php
// Tabs: Profile, Edit Name, Change Password, Avatar
// Large avatar (100x100), Bootstrap Icons on tabs + card headers
// UI text: English; Comments: বাংলা

declare(strict_types=1);

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];
function check_csrf(): bool {
  return isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '');
}

/* ---------- Role badge color map ---------- */
function role_badge_class(string $role): string {
  return match(strtolower($role)){
    'admin'   => 'bg-danger',
    'manager' => 'bg-primary',
    'staff'   => 'bg-secondary',
    default   => 'bg-info',
  };
}

/* ---------- Current user ---------- */
$u        = $_SESSION['user'] ?? [];
$uid      = (int)($u['id'] ?? 0);
$username = (string)($u['username'] ?? "user{$uid}");

/* ---------- Load row (simple schema: full_name, password_hash, user_image_url) ---------- */
// বাংলা: টেবিলে কলাম না থাকলে fallback সেশন ডেটা ব্যবহার হবে
$row = [];
try{
  $st = $pdo->prepare("SELECT id, username, full_name, role, last_login, created_at, password_hash, user_image_url
                       FROM users WHERE id=? LIMIT 1");
  $st->execute([$uid]);
  $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
}catch(Throwable $e){
  $row = [
    'id' => $uid,
    'username' => $username,
    'full_name' => $u['full_name'] ?? $u['name'] ?? '',
    'role' => $u['role'] ?? 'user',
    'user_image_url' => $u['user_image_url'] ?? null,
    'last_login' => null,
    'created_at' => null,
  ];
}

$msg = ''; $err = '';

/* ---------- POST actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!check_csrf()) {
    $err = 'Invalid request. Please try again.';
  } else {
    $action = $_POST['action'] ?? '';

    /* ----- Edit Name ----- */
    if ($action === 'update_name') {
      $new_name = trim((string)($_POST['name'] ?? ''));
      if ($new_name === '') {
        $err = 'Name cannot be empty.';
      } else {
        $q = $pdo->prepare("UPDATE users SET full_name=? WHERE id=?");
        $q->execute([$new_name, $uid]);
        // বাংলা: সেশনে সিঙ্ক রাখি
        $_SESSION['user']['full_name'] = $new_name;
        $_SESSION['user']['name'] = $new_name;
        $msg = 'Name updated successfully.';
      }
    }

    /* ----- Change Password ----- */
    if ($action === 'update_pass') {
      $current = (string)($_POST['current'] ?? '');
      $new     = (string)($_POST['new'] ?? '');
      $confirm = (string)($_POST['confirm'] ?? '');

      if (!$row || !password_verify($current, (string)($row['password_hash'] ?? ''))) {
        $err = 'Current password is incorrect.';
      } elseif ($new !== $confirm) {
        $err = 'New passwords do not match.';
      } elseif (strlen($new) < 6) {
        $err = 'Password must be at least 6 characters.';
      } else {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$hash, $uid]);
        $msg = 'Password updated successfully.';
      }
    }

    /* ----- Avatar Upload ----- */
    if ($action === 'update_avatar') {
      if (!isset($_FILES['avatar']) || ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $err = 'Please choose an image file.';
      } else {
        $f = $_FILES['avatar'];
        if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
          $err = 'Upload failed. Try again.';
        } else {
          $maxBytes = 2 * 1024 * 1024; // 2MB
          if (($f['size'] ?? 0) > $maxBytes) {
            $err = 'Image too large (max 2MB).';
          } else {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $f['tmp_name']);
            finfo_close($finfo);
            $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
            if (!isset($allowed[$mime])) {
              $err = 'Only JPG, PNG or WEBP allowed.';
            } else {
              $root = dirname(__DIR__);
              $dir  = $root . '/uploads/users';
              if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
              $ext  = $allowed[$mime];
              $safeUsername = preg_replace('/[^a-zA-Z0-9_\-]/','_', strtolower($username));
              $destRel = "/uploads/users/{$safeUsername}.{$ext}";
              $destAbs = $root . $destRel;

              // বাংলা: এই ইউজারনেমের পুরনো ভ্যারিয়েন্ট থাকলে ডিলিট করি
              foreach (['jpg','jpeg','png','webp'] as $ex) {
                $old = $root . "/uploads/users/{$safeUsername}.{$ex}";
                if (is_file($old)) @unlink($old);
              }

              if (move_uploaded_file($f['tmp_name'], $destAbs)) {
                $pdo->prepare("UPDATE users SET user_image_url=? WHERE id=?")->execute([$destRel, $uid]);
                $_SESSION['user']['user_image_url'] = $destRel;
                $msg = 'Avatar updated successfully.';
              } else {
                $err = 'Could not save file. Check permissions.';
              }
            }
          }
        }
      }
    }
  }

  // বাংলা: আপডেটের পর রো রিফ্রেশ
  $st = $pdo->prepare("SELECT id, username, full_name, role, last_login, created_at, user_image_url
                       FROM users WHERE id=? LIMIT 1");
  $st->execute([$uid]);
  $row = $st->fetch(PDO::FETCH_ASSOC) ?: $row;
}

/* ---------- View ---------- */
$page_title = 'My Profile';
require_once __DIR__ . '/../partials/partials_header.php';

/* ---------- Resolve avatar with filesystem existence check ---------- */
$avatar = (string)($row['user_image_url'] ?? ($_SESSION['user']['user_image_url'] ?? ''));
$abs    = $avatar ? (dirname(__DIR__) . $avatar) : '';
if (!$avatar || !is_file($abs)) {
  $avatar = '/assets/images/default-avatar.png';
}

// বাংলা: প্রদর্শনের জন্য কমন ভ্যারিয়েবল
$display_name = $row['full_name'] ?? $u['full_name'] ?? $u['name'] ?? $username;
$display_user = $row['username']  ?? $username;
$display_role = $row['role']      ?? ($u['role'] ?? 'user');
?>
<div class="container mt-4">
  <div class="row justify-content-center">
    <div class="col-md-8 col-lg-7">
      <div class="card shadow-sm">
        <div class="card-header bg-white border-bottom-0">
          <ul class="nav nav-tabs card-header-tabs" id="profileTabs" role="tablist">
            <li class="nav-item">
              <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#pane-profile" type="button">
                <i class="bi bi-person-circle"></i> Profile
              </button>
            </li>
            <li class="nav-item">
              <button class="nav-link" data-bs-toggle="tab" data-bs-target="#pane-edit" type="button">
                <i class="bi bi-pencil-square"></i> Edit Name
              </button>
            </li>
            <li class="nav-item">
              <button class="nav-link" data-bs-toggle="tab" data-bs-target="#pane-pass" type="button">
                <i class="bi bi-key-fill"></i> Change Password
              </button>
            </li>
            <li class="nav-item">
              <button class="nav-link" data-bs-toggle="tab" data-bs-target="#pane-avatar" type="button">
                <i class="bi bi-image"></i> Avatar
              </button>
            </li>
          </ul>
        </div>

        <div class="card-body tab-content">
          <?php if($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
          <?php if($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

          <!-- Profile -->
          <div class="tab-pane fade show active" id="pane-profile">
            <div class="card mb-3">
              <div class="card-header">
                <i class="bi bi-person-circle"></i> Profile
              </div>
              <div class="card-body">
                <div class="d-flex align-items-center gap-3 mb-3">
                  <img src="<?= h($avatar) ?>" class="rounded-circle border shadow-sm"
                       style="width:100px;height:100px;object-fit:cover;">
                  <div>
                    <div class="fw-semibold mb-1 fs-5">
                      <i class="bi bi-person-badge"></i>
                      <?= h($display_name) ?>
                    </div>
                    <div class="small text-muted">@<?= h($display_user) ?></div>
                    <span class="badge <?= role_badge_class((string)$display_role) ?> mt-1">
                      <?= h($display_role) ?>
                    </span>
                  </div>
                </div>

                <dl class="row mb-0">
                  <dt class="col-sm-4">Username</dt><dd class="col-sm-8"><?= h($display_user) ?></dd>
                  <dt class="col-sm-4">Name</dt><dd class="col-sm-8"><?= h($display_name) ?></dd>
                  <dt class="col-sm-4">Role</dt><dd class="col-sm-8"><?= h($display_role) ?></dd>
                  <dt class="col-sm-4">Last Login</dt><dd class="col-sm-8"><?= h($row['last_login'] ?? 'N/A') ?></dd>
                  <dt class="col-sm-4">Joined</dt><dd class="col-sm-8"><?= h($row['created_at'] ?? 'N/A') ?></dd>
                </dl>
              </div>
            </div>
          </div>

          <!-- Edit Name -->
          <div class="tab-pane fade" id="pane-edit">
            <div class="card mb-3">
              <div class="card-header">
                <i class="bi bi-pencil-square"></i> Edit Name
              </div>
              <div class="card-body">
                <form method="post">
                  <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                  <input type="hidden" name="action" value="update_name">
                  <div class="mb-3">
                    <label class="form-label">Name</label>
                    <input type="text" name="name" class="form-control"
                           value="<?= h($display_name) ?>" required>
                  </div>
                  <button type="submit" class="btn btn-primary">
                    <i class="bi bi-pencil-square"></i> Update Name
                  </button>
                </form>
              </div>
            </div>
          </div>

          <!-- Change Password -->
          <div class="tab-pane fade" id="pane-pass">
            <div class="card mb-3">
              <div class="card-header">
                <i class="bi bi-key-fill"></i> Change Password
              </div>
              <div class="card-body">
                <form method="post" autocomplete="off">
                  <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                  <input type="hidden" name="action" value="update_pass">
                  <div class="mb-3">
                    <label class="form-label">Current Password</label>
                    <input type="password" name="current" class="form-control" required>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">New Password</label>
                    <input type="password" name="new" class="form-control" minlength="6" required>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Confirm New Password</label>
                    <input type="password" name="confirm" class="form-control" minlength="6" required>
                  </div>
                  <button type="submit" class="btn btn-warning">
                    <i class="bi bi-key-fill"></i> Change Password
                  </button>
                </form>
              </div>
            </div>
          </div>

          <!-- Avatar -->
          <div class="tab-pane fade" id="pane-avatar">
            <div class="card mb-3">
              <div class="card-header">
                <i class="bi bi-image"></i> Avatar
              </div>
              <div class="card-body">
                <form method="post" enctype="multipart/form-data">
                  <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                  <input type="hidden" name="action" value="update_avatar">
                  <div class="mb-3">
                    <label class="form-label">Choose Image (JPG/PNG/WEBP, max 2MB)</label>
                    <input type="file" name="avatar" class="form-control"
                           accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" required>
                  </div>
                  <button type="submit" class="btn btn-success">
                    <i class="bi bi-cloud-upload"></i> Upload Avatar
                  </button>
                </form>
              </div>
            </div>
          </div>

        </div><!-- /tab-content -->
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../partials/partials_footer.php'; ?>
