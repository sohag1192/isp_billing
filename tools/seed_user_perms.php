<?php
// /tools/seed_user_perms.php — ensure user_permissions / user_permission_denies tables
declare(strict_types=1);
$ROOT = dirname(__DIR__);
require_once $ROOT.'/app/db.php';
header('Content-Type:text/plain; charset=utf-8');
$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function tbl_exists(PDO $pdo,string $t):bool{ $db=$pdo->query('SELECT DATABASE()')->fetchColumn(); $st=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?"); $st->execute([$db,$t]); return (bool)$st->fetchColumn(); }

$created=[];
if(!tbl_exists($pdo,'user_permissions')){
  $pdo->exec("CREATE TABLE user_permissions(
    user_id INT NOT NULL, permission_id INT NOT NULL,
    PRIMARY KEY(user_id,permission_id),
    CONSTRAINT fk_up_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_up_perm FOREIGN KEY(permission_id) REFERENCES permissions(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $created[]='user_permissions';
}
if(!tbl_exists($pdo,'user_permission_denies')){
  $pdo->exec("CREATE TABLE user_permission_denies(
    user_id INT NOT NULL, permission_id INT NOT NULL,
    PRIMARY KEY(user_id,permission_id),
    CONSTRAINT fk_ud_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ud_perm FOREIGN KEY(permission_id) REFERENCES permissions(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $created[]='user_permission_denies';
}
echo $created?('Created: '.implode(', ',$created).PHP_EOL):"Tables exist.\n";
echo "OK\n";
