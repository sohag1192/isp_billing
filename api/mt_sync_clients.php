<?php
// /api/mt_sync_clients.php
// Purpose: Sync PPPoE users from MikroTik into `clients` (upsert) with RouterOS v7 show-sensitive handling
// নোট: কোড/লেবেল ইংরেজি; শুধু কমেন্ট বাংলায়

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../app/require_login.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/routeros_api.class.php';

function respond($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
function arr_get($a,$k,$d=''){ return isset($a[$k]) ? $a[$k] : $d; }

// টেবিলে কোনো কলাম আছে কি না—চেকার
function has_col(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetchColumn();
}

// RouterOS v7: show-sensitive সহ secrets পড়ার চেষ্টাঃ
function mt_fetch_secrets_with_password(RouterosAPI $API): array {
    // প্রথমে: print + show-sensitive + proplist
    $out = [];
    // কিছু RouterOS API wrapper-এ ফ্ল্যাগ পাস করতে custom write দরকার হয়
    // তাই আমরা 'write' ব্যবহার করছি
    $API->write('/ppp/secret/print', false);
    $API->write('=show-sensitive=');
    $API->write('=.proplist=name,profile,disabled,comment,service,password,.id');
    $API->write('?service=pppoe', true); // কেবল PPPoE
    $out = $API->read(false);

    // যদি empty আসে বা password ফিল্ড নাই—fallback হিসেবে আবার চেষ্টা (কিছু রাউটারে show-sensitive ফ্ল্যাগ কাজ করে না)
    $hasPwd = false;
    if (is_array($out) && count($out) > 0) {
        foreach ($out as $r) { if (isset($r['password'])) { $hasPwd = true; break; } }
    }
    if (!$hasPwd) {
        // fallback: সাধারণ print (id নিয়ে), তারপর প্রতিটি id এর password আলাদা করে 'get value-name=password'
        $base = $API->comm('/ppp/secret/print', [
            '.proplist' => 'name,profile,disabled,comment,service,.id',
            '?service'  => 'pppoe'
        ]);
        $res = [];
        foreach ($base ?: [] as $row) {
            $id = arr_get($row, '.id', '');
            $pwd = null;
            if ($id !== '') {
                // try: /ppp/secret/get value-name=password with show-sensitive
                $API->write('/ppp/secret/get', false);
                $API->write('=.id='.$id, false);
                $API->write('=value-name=password', false);
                $API->write('=show-sensitive=', true);
                $ans = $API->read(false);
                // RouterOS API wrapper-এ get রেসপন্স কিছুটা ভিন্ন হতে পারে; common case: ['!re'=>[['value'=>'xxxx']]]
                if (is_array($ans)) {
                    foreach ($ans as $blk) {
                        if (isset($blk['value'])) { $pwd = $blk['value']; break; }
                    }
                }
            }
            $row['password'] = $pwd; // null হলে সেটাই থাক
            $res[] = $row;
        }
        return $res;
    }
    return $out;
}

try {
    // ---------- Inputs ----------
    $router_id = isset($_GET['router_id']) ? (int)$_GET['router_id'] : 0; // 0 = all
    $preview   = (isset($_GET['preview']) && (int)$_GET['preview'] === 1); // dry-run
    $source    = strtolower(trim($_GET['source'] ?? 'auto')); // auto | secret | active
    $auto_pkg  = (int)($_GET['auto_create_package'] ?? 0); // 1 = create missing packages

    // ---------- DB ----------
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // clients টেবিলের কলাম map (schema-aware)
    $has_created       = has_col($pdo, 'clients', 'created_at');
    $has_updated       = has_col($pdo, 'clients', 'updated_at');
    $has_join_date     = has_col($pdo, 'clients', 'join_date');
    $has_is_left       = has_col($pdo, 'clients', 'is_left');
    $has_pkg           = has_col($pdo, 'clients', 'package_id');
    $has_router        = has_col($pdo, 'clients', 'router_id');
    $has_status        = has_col($pdo, 'clients', 'status');
    $has_client_code   = has_col($pdo, 'clients', 'client_code');
    $has_pppoe_pass    = has_col($pdo, 'clients', 'pppoe_password'); // <-- পাসওয়ার্ড কলাম ধরা হচ্ছে

    // ---------- Load routers ----------
    if ($router_id > 0) {
        $rs = $pdo->prepare("SELECT * FROM routers WHERE id=? LIMIT 1");
        $rs->execute([$router_id]);
    } else {
        $rs = $pdo->query("SELECT * FROM routers ORDER BY id");
    }
    $routers = $rs->fetchAll(PDO::FETCH_ASSOC);
    if (!$routers) respond(['ok'=>false,'error'=>'No router found']);

    // ---------- Package cache ----------
    $pkgCache = [];
    $getPkgId = function(string $pkgName) use ($pdo, &$pkgCache, $auto_pkg) {
        $key = trim($pkgName);
        if ($key === '') return null;
        if (isset($pkgCache[$key])) return $pkgCache[$key];
        $st = $pdo->prepare("SELECT id FROM packages WHERE name = ? LIMIT 1");
        $st->execute([$key]);
        $id = $st->fetchColumn();
        if ($id) { return $pkgCache[$key] = (int)$id; }
        if ($auto_pkg === 1) {
            $ins = $pdo->prepare("INSERT INTO packages (name, price) VALUES (?, 0)");
            $ins->execute([$key]);
            return $pkgCache[$key] = (int)$pdo->lastInsertId();
        }
        return $pkgCache[$key] = null;
    };

    $summary = [
        'ok'=>true, 'preview'=>$preview, 'routers'=>[],
        'inserted'=>0, 'updated'=>0, 'skipped'=>0, 'errors'=>[]
    ];

    foreach ($routers as $router) {
        $rid = (int)$router['id'];
        $API = new RouterosAPI(); $API->debug = false;

        if (!$API->connect($router['ip'], $router['username'], $router['password'], $router['api_port'])) {
            $summary['errors'][] = "Router #{$rid} connect failed";
            $summary['routers'][] = ['router_id'=>$rid,'source'=>'none','fetched'=>0,'inserted'=>0,'updated'=>0,'skipped'=>0,'sample'=>[]];
            continue;
        }

        // ---------- Fetch from MT ----------
        $usedSource = 'none';
        $secrets = [];

        if ($source === 'auto' || $source === 'secret') {
            $secrets = mt_fetch_secrets_with_password($API);
            if (is_array($secrets) && count($secrets) > 0) $usedSource = 'secret';
        }
        if (($source === 'auto' && count($secrets) === 0) || $source === 'active') {
            // Fallback: /ppp/active (password পাওয়া যাবে না)
            $actives = $API->comm('/ppp/active/print', ['.proplist'=>'name,service']);
            $tmp = [];
            foreach ($actives ?: [] as $a) {
                $srv = strtolower(arr_get($a,'service',''));
                if ($srv !== '' && $srv !== 'pppoe') continue;
                $nm = trim(arr_get($a,'name',''));
                if ($nm === '') continue;
                $tmp[] = ['name'=>$nm,'profile'=>'','disabled'=>'false','comment'=>'','service'=>'pppoe','password'=>null];
            }
            if (count($tmp)>0) { $secrets = $tmp; $usedSource = 'active'; }
        }
        $API->disconnect();

        if (!$preview) $pdo->beginTransaction();

        $rStats = [
            'router_id'=>$rid,'source'=>$usedSource,
            'fetched'=>is_array($secrets)?count($secrets):0,
            'inserted'=>0,'updated'=>0,'skipped'=>0,'sample'=>[]
        ];
        if (is_array($secrets) && count($secrets)>0) {
            $rStats['sample'] = array_slice(array_map(fn($s)=>arr_get($s,'name',''), $secrets), 0, 5);
        }

        foreach ($secrets as $s) {
            $pppoe   = trim(arr_get($s,'name',''));
            $profile = trim(arr_get($s,'profile',''));
            $disabled= strtolower((string)arr_get($s,'disabled','false')) === 'true';
            $service = strtolower((string)arr_get($s,'service','pppoe'));
            $passwd  = arr_get($s,'password', null); // null হলে DB তে বসবে না

            if ($pppoe === '' || ($service !== '' && $service !== 'pppoe')) { $rStats['skipped']++; $summary['skipped']++; continue; }

            $status  = $disabled ? 'disabled' : 'active';
            $package_id = $has_pkg ? $getPkgId($profile) : null;

            // comment থেকে mobile hint (UNIQUE হলে ডুপ্লিকেট skip)
            $comment = (string)arr_get($s,'comment','');
            $mobile_hint = null;
            if ($comment !== '' && preg_match('/(01\d{9})/', $comment, $m)) {
                $mobile_hint = $m[1];
                // duplicate check
                $chk = $pdo->prepare("SELECT id FROM clients WHERE mobile = ? LIMIT 1");
                $chk->execute([$mobile_hint]);
                if ($chk->fetchColumn()) $mobile_hint = null;
            }

            // find by PPPoE
            $st = $pdo->prepare("SELECT id FROM clients WHERE pppoe_id=? LIMIT 1");
            $st->execute([$pppoe]);
            $existing_id = $st->fetchColumn();

            if ($existing_id) {
                // ---------- UPDATE ----------
                $sets = [];
                $bind = [':id'=>(int)$existing_id];

                if ($has_router)      { $sets[]="router_id = :router_id";   $bind[':router_id']=$rid; }
                if ($has_pkg && $package_id){ $sets[]="package_id = :package_id"; $bind[':package_id']=$package_id; }
                if ($has_status)      { $sets[]="status = :status";         $bind[':status']=$status; }
                if ($has_pppoe_pass && $passwd !== null){ $sets[]="pppoe_password = :pppoe_password"; $bind[':pppoe_password']=$passwd; }
                if ($mobile_hint){ $sets[]="mobile = COALESCE(NULLIF(mobile,''), :mobile)"; $bind[':mobile']=$mobile_hint; }
                // name কখনো comment থেকে override করব না—PPPoE ডিফল্ট থাকবে
                if ($has_updated)     { $sets[]="updated_at = NOW()"; }

                if (!empty($sets)) {
                    $sql = "UPDATE clients SET ".implode(', ',$sets)." WHERE id=:id";
                    if (!$preview) { $up = $pdo->prepare($sql); $up->execute($bind); }
                    $rStats['updated']++; $summary['updated']++;
                } else { $rStats['skipped']++; $summary['skipped']++; }

            } else {
                // ---------- INSERT ----------
                // name সবসময় PPPoE; comment থেকে কখনো নয়
                $cols = ['pppoe_id','name'];
                $vals = [':pppoe_id',':name'];
                $bind = [':pppoe_id'=>$pppoe, ':name'=>$pppoe];

                if ($has_client_code){ $cols[]='client_code'; $vals[]=':client_code'; $bind[':client_code']=$pppoe; }
                if ($mobile_hint){ $cols[]='mobile'; $vals[]=':mobile'; $bind[':mobile']=$mobile_hint; }
                if ($has_status){ $cols[]='status'; $vals[]=':status'; $bind[':status']=$status; }
                if ($has_is_left){ $cols[]='is_left'; $vals[]='0'; }
                if ($has_pkg){ $cols[]='package_id'; $vals[]= $package_id ? ':package_id':'NULL'; if ($package_id) $bind[':package_id']=$package_id; }
                if ($has_router){ $cols[]='router_id'; $vals[]=':router_id'; $bind[':router_id']=$rid; }
                if ($has_pppoe_pass && $passwd !== null){ $cols[]='pppoe_password'; $vals[]=':pppoe_password'; $bind[':pppoe_password']=$passwd; }
                if ($has_join_date){ $cols[]='join_date'; $vals[]='CURDATE()'; }
                if ($has_created){ $cols[]='created_at'; $vals[]='NOW()'; }
                if ($has_updated){ $cols[]='updated_at'; $vals[]='NOW()'; }

                $sql = "INSERT INTO clients (".implode(',',$cols).") VALUES (".implode(',',$vals).")";
                if (!$preview) { $ins = $pdo->prepare($sql); $ins->execute($bind); }
                $rStats['inserted']++; $summary['inserted']++;
            }
        }

        if (!$preview) $pdo->commit();
        $summary['routers'][] = $rStats;
    }

    respond($summary);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
    respond(['ok'=>false,'error'=>$e->getMessage()]);
}
