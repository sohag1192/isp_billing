<?php
// /tg/telegram.php
// UI: English; Comments: বাংলা — Telegram core (config, DB settings, templates, subscribers, link-token, queue, sender, batch, instant-send)
declare(strict_types=1);

/* -----------------------------------------------------------------------------
 | CONFIG (env + DB override via /tg/settings.php)
 | বাংলা: আগে ENV, পরে DB-র ভ্যালু নিলে ENV-কে ওভাররাইড করবে।
 -----------------------------------------------------------------------------*/
function tg_cfg(): array {
  $cfg = [
    'bot_token'    => getenv('TELEGRAM_BOT_TOKEN') ?: '',
    'bot_user'     => getenv('TELEGRAM_BOT_USERNAME') ?: 'YourBot',
    'wh_secret'    => getenv('TELEGRAM_WEBHOOK_SECRET') ?: '',
    'batch_limit'  => 100,
    'min_gap_m'    => 30,     // বাংলা: একই uniq_key মেসেজের মধ্যে অন্তত এই ব্যবধান (মিনিট)
    'parse_mode'   => 'HTML', // HTML / MarkdownV2 / ''
    'app_base_url' => '',
    'test_chat_id' => '',     // বাংলা: subscriber না থাকলে fallback test chat (admin) — টেস্টিং সহজ
  ];

  try {
    if (function_exists('db')) {
      /** @var PDO $pdo */
      $pdo = db();
      $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
      $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
      $chk = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME='telegram_settings'");
      $chk->execute([$db]);
      if ($chk->fetchColumn()) {
        $kv = $pdo->query("SELECT k, v FROM telegram_settings")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        if ($kv) {
          foreach (['bot_token','bot_user','wh_secret','app_base_url','parse_mode','test_chat_id'] as $k) {
            if (isset($kv[$k])) $cfg[$k] = (string)$kv[$k];
          }
          if (isset($kv['batch_limit'])) $cfg['batch_limit'] = max(1, (int)$kv['batch_limit']);
          if (isset($kv['min_gap_m']))   $cfg['min_gap_m']   = max(0, (int)$kv['min_gap_m']);
        }
      }
    }
  } catch (Throwable $e) { /* ignore */ }

  if (!in_array($cfg['parse_mode'], ['', 'HTML', 'MarkdownV2'], true)) $cfg['parse_mode'] = 'HTML';
  return $cfg;
}

/* -----------------------------------------------------------------------------
 | DB HELPERS
 -----------------------------------------------------------------------------*/
function tg_tbl_exists(PDO $pdo, string $t): bool {
  try {
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $q  = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
    $q->execute([$db, $t]);
    return (bool)$q->fetchColumn();
  } catch (Throwable $e) { return false; }
}
function tg_col_exists(PDO $pdo, string $t, string $c): bool {
  try {
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $q  = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
    $q->execute([$db, $t, $c]);
    return (bool)$q->fetchColumn();
  } catch (Throwable $e) { return false; }
}
function tg_pick_tbl(PDO $pdo, array $cands): ?string {
  foreach ($cands as $t) if (tg_tbl_exists($pdo, $t)) return $t;
  return null;
}
function tg_pick_client_cols(PDO $pdo, string $CT): array {
  $id    = tg_col_exists($pdo,$CT,'id') ? 'id' : (tg_col_exists($pdo,$CT,'client_id') ? 'client_id' : 'id');
  $name  = tg_col_exists($pdo,$CT,'name') ? 'name' : (tg_col_exists($pdo,$CT,'client_name') ? 'client_name' : $id);
  $pppoe = tg_col_exists($pdo,$CT,'pppoe_id') ? 'pppoe_id' : (tg_col_exists($pdo,$CT,'username') ? 'username' : null);
  return compact('id','name','pppoe');
}

/* -----------------------------------------------------------------------------
 | USER NAME HELPERS — receiver/collector নাম বের করার জন্য
 -----------------------------------------------------------------------------*/
function tg_pick_user_tbl(PDO $pdo): ?string {
  foreach (['users','staff','employees','admin_users','admins'] as $t) {
    if (tg_tbl_exists($pdo,$t)) return $t;
  }
  return null;
}
function tg_pick_user_cols(PDO $pdo, string $UT): array {
  $id = 'id'; foreach (['id','user_id','uid'] as $c) if (tg_col_exists($pdo,$UT,$c)) { $id=$c; break; }
  $name = null; foreach (['name','full_name','display_name','username'] as $c) if (tg_col_exists($pdo,$UT,$c)) { $name=$c; break; }
  $first = tg_col_exists($pdo,$UT,'first_name') ? 'first_name' : null;
  $last  = tg_col_exists($pdo,$UT,'last_name')  ? 'last_name'  : null;
  return compact('id','name','first','last');
}
/** বাংলা: ইউজারের display name resolve করি */
function tg_user_display_name(PDO $pdo, int $user_id): ?string {
  if ($user_id<=0) return null;
  $UT = tg_pick_user_tbl($pdo);
  if (!$UT) return null;
  $C  = tg_pick_user_cols($pdo,$UT);

  $sel = ["`{$C['id']}` AS id"];
  if ($C['name'])  $sel[] = "`{$C['name']}` AS name";
  if ($C['first']) $sel[] = "`{$C['first']}` AS first_name";
  if ($C['last'])  $sel[] = "`{$C['last']}` AS last_name";

  $sql = "SELECT ".implode(',', $sel)." FROM `$UT` WHERE `{$C['id']}`=? LIMIT 1";
  $st  = $pdo->prepare($sql); $st->execute([$user_id]);
  $u = $st->fetch(PDO::FETCH_ASSOC) ?: null;
  if (!$u) return null;

  if (!empty($u['name'])) return (string)$u['name'];
  $fn = trim((string)($u['first_name'] ?? ''));
  $ln = trim((string)($u['last_name'] ?? ''));
  $nm = trim($fn.' '.$ln);
  return $nm !== '' ? $nm : null;
}

/* -----------------------------------------------------------------------------
 | TEMPLATES
 -----------------------------------------------------------------------------*/
function tg_render_template(PDO $pdo, string $template_key, array $clientRow, array $payload): string {
  $TT  = tg_tbl_exists($pdo,'telegram_templates') ? 'telegram_templates' : null;
  $body = '';
  if ($TT) {
    $st = $pdo->prepare("SELECT body FROM `$TT` WHERE template_key=? AND active=1 LIMIT 1");
    $st->execute([$template_key]);
    $body = (string)($st->fetchColumn() ?: '');
  }
  if ($body === '') {
    $defaults = [
      'due_reminder'    => "প্রিয় {{name}}, আপনার বিল বকেয়া {{amount}} টাকা। পরিশোধ করুন: {{pay_link}}",
      'payment_confirm' => "{{name}}, আপনার {{amount}} টাকা পেমেন্ট পেয়েছি (Receiver: {{receiver}}). ধন্যবাদ।",
      'generic'         => "Hi {{name}}, {{message}}",
    ];
    $body = $defaults[$template_key] ?? $defaults['generic'];
  }

  // Receiver/paid info resolve (payload → DB fallback)
  $receiver_name = (string)($payload['received_by_name'] ?? '');
  $receiver_id   = (int)($payload['received_by'] ?? 0);
  if ($receiver_name === '' && $receiver_id > 0) {
    $rn = tg_user_display_name($pdo, $receiver_id);
    if ($rn) $receiver_name = $rn;
  }

  $vars = [
    '{{name}}'             => (string)($clientRow['name'] ?? 'Customer'),
    '{{client_id}}'        => (string)($clientRow['id'] ?? ''),
    '{{pppoe}}'            => (string)($clientRow['pppoe'] ?? ''),
    '{{amount}}'           => (string)($payload['amount'] ?? ''),
    '{{invoice_id}}'       => (string)($payload['invoice_id'] ?? ''),
    '{{pay_link}}'         => (string)($payload['pay_link'] ?? ''),
    '{{portal_link}}'      => (string)($payload['portal_link'] ?? ''),
    '{{message}}'          => (string)($payload['message'] ?? ''),
    // NEW:
    '{{method}}'           => (string)($payload['method'] ?? ''),
    '{{txn_id}}'           => (string)($payload['txn_id'] ?? ''),
    '{{paid_at}}'          => (string)($payload['paid_at'] ?? ''),
    '{{received_by}}'      => $receiver_id ? (string)$receiver_id : '',
    '{{received_by_name}}' => $receiver_name,
    '{{receiver}}'         => $receiver_name, // alias
  ];
  return strtr($body, $vars);
}

/* -----------------------------------------------------------------------------
 | TELEGRAM API
 -----------------------------------------------------------------------------*/
function tg_api(string $method, array $params, ?array &$raw=null): array {
  $cfg   = tg_cfg();
  $token = $cfg['bot_token'];
  if ($token === '') return [false, 'Bot token not configured'];

  $url = "https://api.telegram.org/bot{$token}/{$method}";
  $ch  = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_POSTFIELDS     => $params,
  ]);
  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($err) return [false, "cURL: $err"];
  $json = json_decode((string)$resp, true);
  if (is_array($raw)) $raw = $json ?? [];
  if ($code === 200 && $json && ($json['ok'] ?? false)) return [true,'OK'];
  $desc = $json['description'] ?? '';
  return [false, "HTTP{$code}: ".($desc ?: substr((string)$resp, 0, 300))];
}

function tg_send_text(int|string $chat_id, string $text, ?string $parseMode=null): array {
  $cfg = tg_cfg();
  $pm  = $parseMode ?? $cfg['parse_mode'];
  $raw = [];
  return tg_api('sendMessage', [
    'chat_id' => $chat_id,
    'text'    => $text,
    'parse_mode' => $pm ?: null,
    'disable_web_page_preview' => true,
  ], $raw);
}

/* -----------------------------------------------------------------------------
 | SUBSCRIBERS
 -----------------------------------------------------------------------------*/
function tg_subscriber_get(PDO $pdo, int $client_id): ?array {
  if (!tg_tbl_exists($pdo,'telegram_subscribers')) return null;
  $st = $pdo->prepare("SELECT * FROM telegram_subscribers WHERE client_id=? AND is_active=1 ORDER BY id DESC LIMIT 1");
  $st->execute([$client_id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}
function tg_subscriber_upsert(PDO $pdo, int $client_id, int $chat_id, array $meta=[]): bool {
  if (!tg_tbl_exists($pdo,'telegram_subscribers')) return false;
  $st = $pdo->prepare(
    "INSERT INTO telegram_subscribers (client_id, chat_id, username, first_name, last_name, is_active, created_at)
     VALUES (?,?,?,?,?,1,NOW())
     ON DUPLICATE KEY UPDATE username=VALUES(username), first_name=VALUES(first_name), last_name=VALUES(last_name), is_active=1"
  );
  return $st->execute([
    $client_id,
    $chat_id,
    $meta['username'] ?? null,
    $meta['first_name'] ?? null,
    $meta['last_name'] ?? null
  ]);
}
function tg_subscriber_deactivate(PDO $pdo, int $chat_id): void {
  if (!tg_tbl_exists($pdo,'telegram_subscribers')) return;
  $st = $pdo->prepare("UPDATE telegram_subscribers SET is_active=0 WHERE chat_id=?");
  $st->execute([$chat_id]);
}

/* -----------------------------------------------------------------------------
 | LINK TOKENS
 -----------------------------------------------------------------------------*/
function tg_link_create(PDO $pdo, int $client_id): ?string {
  if (!tg_tbl_exists($pdo,'telegram_link_tokens')) return null;
  $token = bin2hex(random_bytes(8));
  $st = $pdo->prepare("INSERT INTO telegram_link_tokens (token, client_id, created_at) VALUES (?,?,NOW())");
  $st->execute([$token, $client_id]);
  return $token;
}
function tg_link_consume(PDO $pdo, string $token): ?int {
  if (!tg_tbl_exists($pdo,'telegram_link_tokens')) return null;
  $st = $pdo->prepare("SELECT client_id FROM telegram_link_tokens WHERE token=? AND used_at IS NULL ORDER BY id DESC LIMIT 1");
  $st->execute([$token]);
  $cid = $st->fetchColumn();
  if ($cid) {
    $up = $pdo->prepare("UPDATE telegram_link_tokens SET used_at=NOW() WHERE token=?");
    $up->execute([$token]);
    return (int)$cid;
  }
  return null;
}

/* -----------------------------------------------------------------------------
 | QUEUE
 -----------------------------------------------------------------------------*/
function tg_queue(PDO $pdo, int $client_id, string $template_key, array $payload=[], ?string $uniq=null): bool {
  if (!tg_tbl_exists($pdo,'telegram_queue')) return false;

  if ($uniq) {
    $cfg = tg_cfg();
    $gap = min(1440, max(0, (int)$cfg['min_gap_m']));
    $st  = $pdo->prepare("SELECT created_at FROM telegram_queue WHERE uniq_key=? ORDER BY id DESC LIMIT 1");
    $st->execute([$uniq]);
    $at  = $st->fetchColumn();
    if ($at) {
      $t1 = strtotime((string)$at);
      $t2 = time();
      $diffMin = (int)floor(max(0, $t2 - $t1) / 60);
      if ($diffMin < $gap) return false; // বাংলা: থ্রটল
    }
  }

  $st = $pdo->prepare(
    "INSERT INTO telegram_queue (client_id, template_key, payload_json, status, retries, send_after, uniq_key, created_at, last_error)
     VALUES (?,?,?, 'queued', 0, NOW(), ?, NOW(), NULL)"
  );
  return $st->execute([
    $client_id,
    $template_key,
    json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
    $uniq
  ]);
}

/* -----------------------------------------------------------------------------
 | HELPERS — error classifiers
 -----------------------------------------------------------------------------*/
function tg_err_is_parse(string $e): bool {
  $e = strtolower($e);
  return str_contains($e,'parse') || str_contains($e, "can't parse entities");
}
function tg_err_is_blocked(string $e): bool {
  $e = strtolower($e);
  return str_contains($e,'blocked by the user');
}
function tg_err_is_429(string $e): bool {
  return str_contains(strtolower($e),'too many requests') || str_contains($e, '429');
}

/* -----------------------------------------------------------------------------
 | BATCH RUNNER (robust)
 | বাংলা: সাবস্ক্রাইবার না থাকলে test_chat_id fallback; parse_mode error এ retry; blocked হলে inactive।
 -----------------------------------------------------------------------------*/
function tg_run_batch(PDO $pdo): array {
  if (!tg_tbl_exists($pdo,'telegram_queue')) return ['processed'=>0,'sent'=>0,'failed'=>0];

  $cfg = tg_cfg();
  $lim = (int)$cfg['batch_limit'];

  $st = $pdo->prepare("SELECT * FROM telegram_queue WHERE status='queued' AND send_after<=NOW() ORDER BY id ASC LIMIT $lim");
  $st->execute();
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  if (!$rows) return ['processed'=>0,'sent'=>0,'failed'=>0];

  $CT = tg_pick_tbl($pdo, ['clients','customers','subscribers']);
  $Ccols = $CT ? tg_pick_client_cols($pdo, $CT) : [];

  $sent=0; $failed=0;

  foreach ($rows as $n) {
    $id  = (int)$n['id'];
    $cid = (int)$n['client_id'];
    $payload = json_decode((string)$n['payload_json'], true) ?: [];
    $template = (string)$n['template_key'];

    // Load client for template render
    $clientRow = [];
    if ($CT && $cid>0) {
      $sel = ["`{$Ccols['id']}` AS id", "`{$Ccols['name']}` AS name"];
      if ($Ccols['pppoe']) $sel[] = "`{$Ccols['pppoe']}` AS pppoe";
      $sql = "SELECT ".implode(',', $sel)." FROM `$CT` WHERE `{$Ccols['id']}`=? LIMIT 1";
      $s = $pdo->prepare($sql); $s->execute([$cid]);
      $clientRow = $s->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    // Resolve chat
    $sub = tg_subscriber_get($pdo, $cid);
    $chat_id = $sub ? (int)$sub['chat_id'] : 0;
    $used_fallback = false;
    if ($chat_id === 0 && ($cfg['test_chat_id'] ?? '') !== '') {
      $chat_id = (string)$cfg['test_chat_id'];
      $used_fallback = true;
    }
    if (!$chat_id) {
      $upd = $pdo->prepare("UPDATE telegram_queue SET status='failed', last_error='No active Telegram subscriber', sent_at=NOW() WHERE id=?");
      $upd->execute([$id]); $failed++; continue;
    }

    $text = tg_render_template($pdo, $template, $clientRow, $payload);
    [$ok,$err] = tg_send_text($chat_id, $text);

    if (!$ok && tg_err_is_parse($err)) {
      [$ok,$err] = tg_send_text($chat_id, $text, '');
    }

    if ($ok) {
      $note = $used_fallback ? 'fallback:test_chat_id' : null;
      $upd = $pdo->prepare("UPDATE telegram_queue SET status='sent', sent_at=NOW(), last_error=? WHERE id=?");
      $upd->execute([$note, $id]); $sent++; continue;
    }

    if (tg_err_is_blocked($err) && $sub) {
      tg_subscriber_deactivate($pdo, (int)$sub['chat_id']);
    }

    $retries = (int)$n['retries'] + 1;
    $mins    = tg_err_is_429($err) ? min(120, 1 + 2*$retries) : min(60, 5 * (2 ** max(0, $retries - 1)));
    $upd = $pdo->prepare(
      "UPDATE telegram_queue
       SET status='queued', retries=?, send_after=DATE_ADD(NOW(), INTERVAL {$mins} MINUTE), last_error=?
       WHERE id=?"
    );
    $upd->execute([$retries, $err, $id]); $failed++;
  }

  return ['processed'=>count($rows), 'sent'=>$sent, 'failed'=>$failed];
}

/* -----------------------------------------------------------------------------
 | INSTANT SEND (no pending) — upstream fallback can requeue on fail
 | বাংলা: সাথে সাথে পাঠায়; আমরা sent/failed হিসেবে লগ করি।
 -----------------------------------------------------------------------------*/
function tg_send_now(PDO $pdo, int $client_id, string $template_key, array $payload = [], ?string $uniq = null): array {
  // return ['ok'=>bool, 'error'=>string|null, 'queue_id'=>int|null]
  if (!tg_tbl_exists($pdo,'telegram_queue')) {
    return ['ok'=>false,'error'=>'telegram_queue table missing','queue_id'=>null];
  }

  // Uniq-interval guard
  if ($uniq) {
    $cfg = tg_cfg();
    $gap = min(1440, max(0, (int)$cfg['min_gap_m']));
    $st  = $pdo->prepare("SELECT created_at FROM telegram_queue WHERE uniq_key=? ORDER BY id DESC LIMIT 1");
    $st->execute([$uniq]);
    if ($at = $st->fetchColumn()) {
      $diffMin = (int)floor((time()-strtotime((string)$at))/60);
      if ($diffMin < $gap) return ['ok'=>true,'error'=>null,'queue_id'=>null]; // skip silently
    }
  }

  // Client row for template
  $CT = tg_pick_tbl($pdo, ['clients','customers','subscribers']);
  $clientRow = [];
  if ($CT) {
    $C = tg_pick_client_cols($pdo, $CT);
    $sel = ["`{$C['id']}` AS id", "`{$C['name']}` AS name"];
    if ($C['pppoe']) $sel[] = "`{$C['pppoe']}` AS pppoe";
    $sql = "SELECT ".implode(',', $sel)." FROM `$CT` WHERE `{$C['id']}`=? LIMIT 1";
    $s = $pdo->prepare($sql); $s->execute([$client_id]);
    $clientRow = $s->fetch(PDO::FETCH_ASSOC) ?: [];
  }

  // Resolve chat
  $cfg = tg_cfg();
  $sub = tg_subscriber_get($pdo, $client_id);
  $chat_id = $sub ? (int)$sub['chat_id'] : 0;
  $used_fallback = false;
  if ($chat_id === 0 && ($cfg['test_chat_id'] ?? '') !== '') {
    $chat_id = (string)$cfg['test_chat_id'];
    $used_fallback = true;
  }
  if (!$chat_id) {
    $ins = $pdo->prepare("INSERT INTO telegram_queue (client_id, template_key, payload_json, status, retries, send_after, uniq_key, created_at, sent_at, last_error)
                          VALUES (?,?,?,?,0,NOW(),?,NOW(),NOW(),?)");
    $ins->execute([$client_id, $template_key, json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), 'failed', $uniq, 'No active Telegram subscriber']);
    return ['ok'=>false,'error'=>'No active Telegram subscriber','queue_id'=>(int)$pdo->lastInsertId()];
  }

  // Render & send
  $text = tg_render_template($pdo, $template_key, $clientRow, $payload);
  [$ok, $err] = tg_send_text($chat_id, $text);
  if (!$ok && tg_err_is_parse($err)) {
    [$ok, $err] = tg_send_text($chat_id, $text, ''); // retry w/o parse_mode
  }
  if (!$ok && tg_err_is_blocked($err) && $sub) {
    tg_subscriber_deactivate($pdo, (int)$sub['chat_id']);
  }

  // Log as sent/failed (no pending here)
  $last_error = $ok ? ($used_fallback ? 'fallback:test_chat_id' : null) : $err;
  $status     = $ok ? 'sent' : 'failed';
  $ins = $pdo->prepare("INSERT INTO telegram_queue (client_id, template_key, payload_json, status, retries, send_after, uniq_key, created_at, sent_at, last_error)
                        VALUES (?,?,?,?,0,NOW(),?,NOW(),NOW(),?)");
  $ins->execute([$client_id, $template_key, json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $status, $uniq, $last_error]);
  return ['ok'=>$ok, 'error'=>$ok?null:$err, 'queue_id'=>(int)$pdo->lastInsertId()];
}
