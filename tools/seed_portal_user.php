<?php
// tools/seed_portal_user.php
// Use from browser or CLI.
// Examples:
//   http://localhost/tools/seed_portal_user.php?pppoe_id=user007&username=017xxxxxxxx&password=changeme123
//   http://localhost/tools/seed_portal_user.php?client_code=CLT0007&username=rahim&password=pass123
//   php seed_portal_user.php pppoe_id=user007 username=017xxxxxxxx password=changeme123

require_once __DIR__ . '/../app/db.php';

function parse_cli_args(&$arr) {
    global $argv;
    if (!isset($argv) || !is_array($argv)) return;
    foreach ($argv as $i => $arg) {
        if ($i === 0) continue;
        if (strpos($arg, '=') !== false) {
            [$k, $v] = explode('=', $arg, 2);
            $arr[$k] = $v;
        }
    }
}

// collect params
$params = $_GET;
parse_cli_args($params);

$pppoe_id   = trim($params['pppoe_id']   ?? '');
$client_code= trim($params['client_code']?? '');
$username   = trim($params['username']   ?? '');
$password   = (string)($params['password'] ?? '');

header('Content-Type: text/plain; charset=utf-8');

try {
    if ($username === '' || $password === '' || ($pppoe_id === '' && $client_code === '')) {
        echo "Usage:\n";
        echo "  seed_portal_user.php?pppoe_id=<pppoe>&username=<user>&password=<pass>\n";
        echo "  or seed_portal_user.php?client_code=<CLTxxxx>&username=<user>&password=<pass>\n";
        exit;
    }

    // Find client_id by pppoe_id or client_code
    if ($pppoe_id !== '') {
        $st = db()->prepare("SELECT id, name FROM clients WHERE pppoe_id=? LIMIT 1");
        $st->execute([$pppoe_id]);
    } else {
        $st = db()->prepare("SELECT id, name FROM clients WHERE client_code=? LIMIT 1");
        $st->execute([$client_code]);
    }
    $client = $st->fetch(PDO::FETCH_ASSOC);
    if (!$client) {
        throw new Exception("Client not found for ".($pppoe_id ? "pppoe_id={$pppoe_id}" : "client_code={$client_code}"));
    }
    $client_id = (int)$client['id'];

    // Check if a portal user already exists for this client or username
    $chk1 = db()->prepare("SELECT id FROM portal_users WHERE client_id=? LIMIT 1");
    $chk1->execute([$client_id]);
    if ($chk1->fetchColumn()) {
        throw new Exception("Portal user already exists for client_id={$client_id} (".($client['name'] ?? '').")");
    }

    $chk2 = db()->prepare("SELECT id FROM portal_users WHERE username=? LIMIT 1");
    $chk2->execute([$username]);
    if ($chk2->fetchColumn()) {
        throw new Exception("Username already taken: {$username}");
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    if ($hash === false) {
        throw new Exception("Password hash failed");
    }

    $ins = db()->prepare("INSERT INTO portal_users (client_id, username, password_hash) VALUES (?,?,?)");
    $ins->execute([$client_id, $username, $hash]);

    echo "✅ Portal user created.\n";
    echo "   client_id: {$client_id}\n";
    echo "   client:    ".($client['name'] ?? '')."\n";
    echo "   username:  {$username}\n";

} catch (Throwable $e) {
    http_response_code(500);
    echo "❌ Error: ".$e->getMessage()."\n";
    echo "Tip: নিশ্চিত করুন clients টেবিলে ওই pppoe_id/client_code আছে।\n";
}
