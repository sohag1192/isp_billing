<?php
// /tools/ensure_audit_table.php
declare(strict_types=1);

require_once __DIR__ . '/../app/db.php';

$sql = <<<SQL
CREATE TABLE IF NOT EXISTS audit_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  action VARCHAR(64) NOT NULL,
  entity_type VARCHAR(64) NOT NULL,
  entity_id INT NOT NULL,
  details JSON NULL,
  ip VARCHAR(45) NULL,
  ua VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_action (action),
  KEY idx_entity (entity_type, entity_id),
  KEY idx_user (user_id),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

db()->exec($sql);

echo "ok\n";
