<?php
// /api/wallet_transfer_action.php — approve/reject transfer
declare(strict_types=1);
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';

header('Content-Type: text/html; charset=utf-8'); // form-post friendly

function can_approve(): bool {
  $is_admin=(int)($_SESSION['user']['is_admin'] ?? 0);
  $role=strtolower((string)($_SESSION['user']['role'] ?? ''));
  return $is_admin===1 || in_array($role,['admin','superadmin','manager','accounts','accountant','billing'],true);
}
function jexit($msg,$ok=true){ echo $ok? $msg : "<div class='alert alert-danger'>$msg</div>"; exit; }

if(!can_approve()) jexit('Forbidden: approver only.', false);

$id = (int)($_POST['id'] ?? 0);
$action = $_POST['action'] ?? '';
$note = trim($_POST['note'] ?? '');
$uid = (int)($_SESSION['user']['id'] ?? 0);

if($id<=0 || !in_array($action,['approve','reject'],true)) jexit('Invalid request', false);

$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ensure still pending
$st = $pdo->prepare("SELECT * FROM wallet_transfers WHERE id=? AND status='pending' LIMIT 1");
$st->execute([$id]); $r=$st->fetch(PDO::FETCH_ASSOC);
if(!$r) jexit('Already processed or not found', false);

$status = ($action==='approve')? 'approved' : 'rejected';
$upd = $pdo->prepare("UPDATE wallet_transfers SET status=?, approved_by=?, approved_at=NOW(), decision_note=? WHERE id=? AND status='pending'");
$upd->execute([$status,$uid,$note,$id]);

// redirect back to approvals
header('Location: /public/wallet_approvals.php?status=pending');
