<?php
/**
 * /api/client_left_toggle.php
 * Input: JSON { id: int, action: 'left'|'undo' }
 * Output: JSON { status, message }
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/audit.php';

function jexit($arr){ echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }

// বাংলা কমেন্ট: ইনপুট পড়া
$raw = file_get_contents('php://input');
$in  = json_decode($raw, true);
$id  = (int)($in['id'] ?? 0);
$action = strtolower(trim($in['action'] ?? ''));

// বাংলা কমেন্ট: ভ্যালিডেশন
if ($id <= 0 || !in_array($action, ['left','undo'], true)) {
    jexit(['status'=>'error','message'=>'Invalid request']);
}

try{
    $db = db();
    $db->beginTransaction();

    // বাংলা কমেন্ট: আগের অবস্থা নিয়ে আসি (অডিটের জন্য)
    $st = $db->prepare("SELECT id, name, pppoe_id, router_id, is_left, left_at FROM clients WHERE id = ?");
    $st->execute([$id]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) { throw new Exception('Client not found'); }

    if ($action === 'left') {
        // is_left=1 + left_at=NOW()
        $u = $db->prepare("UPDATE clients SET is_left=1, left_at=NOW() WHERE id=?");
        $u->execute([$id]);

        // বাংলা কমেন্ট: অডিট লগ
        audit_log('client.left', (int)$c['id'], $c['router_id'] ? (int)$c['router_id'] : null, [
            'pppoe_id' => $c['pppoe_id'],
            'name'     => $c['name'],
            'before'   => ['is_left' => (int)$c['is_left'], 'left_at' => $c['left_at']],
            'after'    => ['is_left' => 1, 'left_at' => 'NOW()'],
            'source'   => 'client_left_toggle.php'
        ]);

        $db->commit();
        jexit(['status'=>'success','message'=>'Marked as LEFT']);
    } else {
        // undo: is_left=0 + left_at=NULL
        $u = $db->prepare("UPDATE clients SET is_left=0, left_at=NULL WHERE id=?");
        $u->execute([$id]);

        // বাংলা কমেন্ট: অডিট লগ
        audit_log('client.undo_left', (int)$c['id'], $c['router_id'] ? (int)$c['router_id'] : null, [
            'pppoe_id' => $c['pppoe_id'],
            'name'     => $c['name'],
            'before'   => ['is_left' => (int)$c['is_left'], 'left_at' => $c['left_at']],
            'after'    => ['is_left' => 0, 'left_at' => null],
            'source'   => 'client_left_toggle.php'
        ]);

        $db->commit();
        jexit(['status'=>'success','message'=>'Undo LEFT done']);
    }
}
catch(Exception $e){
    if(isset($db) && $db->inTransaction()) $db->rollBack();
    jexit(['status'=>'error','message'=>$e->getMessage()]);
}
