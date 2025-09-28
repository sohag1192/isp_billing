<?php
// /app/session_track.php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

function st_db(): PDO {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  return $pdo;
}

function st_bootstrap(PDO $pdo): void {
  // base table (username হয়তো আগে ছিল না)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS user_sessions (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id BIGINT NOT NULL,
      session_id VARCHAR(191) NOT NULL,
      ip VARCHAR(45) NOT NULL,
      user_agent TEXT,
      login_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_session (session_id),
      KEY idx_user (user_id),
      KEY idx_seen (last_seen)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  // যদি username কলাম না থাকে → যোগ করো (আগের ছোট স্কিমা কভার করতে)
  $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
  $st = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                       WHERE TABLE_SCHEMA=? AND TABLE_NAME='user_sessions' AND COLUMN_NAME='username'");
  $st->execute([$db]);
  if ((int)$st->fetchColumn() === 0) {
    $pdo->exec("ALTER TABLE user_sessions ADD COLUMN username VARCHAR(191) NULL AFTER user_id");
  }
}

/* প্রকৃত ক্লায়েন্ট IP */
function st_client_ip(): string {
  $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
  if ($xff) {
    $parts = array_map('trim', explode(',', $xff));
    if (!empty($parts[0])) return substr($parts[0], 0, 45);
  }
  return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/* লগইনে সেশন আপসার্ট */
function session_track_login(int $user_id, ?string $username = null): void {
  if (session_status() === PHP_SESSION_NONE) session_start();
  $pdo = st_db(); st_bootstrap($pdo);

  $sid = session_id();
  $ip  = st_client_ip();
  $ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';

  // username কলাম আছে কিনা দেখো
  $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
  $hasUname = (int)$pdo->query("
      SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA='{$db}' AND TABLE_NAME='user_sessions' AND COLUMN_NAME='username'
    ")->fetchColumn() > 0;

  if ($hasUname) {
    $sql = "INSERT INTO user_sessions (user_id, username, session_id, ip, user_agent)
            VALUES (:uid, :uname, :sid, :ip, :ua)
            ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), username=VALUES(username),
                ip=VALUES(ip), user_agent=VALUES(user_agent), last_seen=NOW()";
    $pdo->prepare($sql)->execute([
      ':uid'=>$user_id, ':uname'=>$username, ':sid'=>$sid, ':ip'=>$ip, ':ua'=>$ua
    ]);
  } else {
    $sql = "INSERT INTO user_sessions (user_id, session_id, ip, user_agent)
            VALUES (:uid, :sid, :ip, :ua)
            ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),
                ip=VALUES(ip), user_agent=VALUES(user_agent), last_seen=NOW()";
    $pdo->prepare($sql)->execute([
      ':uid'=>$user_id, ':sid'=>$sid, ':ip'=>$ip, ':ua'=>$ua
    ]);
  }

  // পুরনো zombie সেশন পরিষ্কার
  $pdo->prepare("DELETE FROM user_sessions WHERE last_seen < NOW() - INTERVAL 6 HOUR")->execute();
}

/* প্রতি রিকোয়েস্টে টাচ */
function session_track_touch(): void {
  if (session_status() === PHP_SESSION_NONE) session_start();
  if (empty($_SESSION['user']['id'])) return;
  $pdo = st_db(); st_bootstrap($pdo);
  $pdo->prepare("UPDATE user_sessions SET last_seen=NOW(), ip=:ip WHERE session_id=:sid")
      ->execute([':sid'=>session_id(), ':ip'=>st_client_ip()]);
}

/* লগআউটে রিমুভ */
function session_track_logout(): void {
  if (session_status() === PHP_SESSION_NONE) session_start();
  $pdo = st_db(); st_bootstrap($pdo);
  $pdo->prepare("DELETE FROM user_sessions WHERE session_id=?")->execute([session_id()]);
}
