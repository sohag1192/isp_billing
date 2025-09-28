<?php
require_once __DIR__ . '/../app/db.php';

db()->exec("CREATE TABLE IF NOT EXISTS olts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  vendor ENUM('huawei','zte','bdcom','vsol') NOT NULL,
  host VARCHAR(100) NOT NULL,
  ssh_port INT NOT NULL DEFAULT 22,
  username VARCHAR(100) NOT NULL,
  password VARCHAR(200) NOT NULL,
  enable_password VARCHAR(200) NULL,
  prompt_regex VARCHAR(200) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u1 (host)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function p($k,$d=null){ return isset($_REQUEST[$k]) ? trim($_REQUEST[$k]) : $d; }

$name   = p('name','VSOL-Core');
$vendor = strtolower(p('vendor','vsol'));
$host   = p('host','192.168.200.2');
$port   = (int) p('port', 22);
$user   = p('user','olt');
$pass   = p('pass','olt');           // <-- নিজেরটা দাও
$epass  = p('epass','');               // enable password (না দিলে ফাঁকা রাখো)
$regex  = p('prompt','(>|#)\\s*$');
$active = (int) p('active', 1);

$sel = db()->prepare("SELECT id FROM olts WHERE host=?");
$sel->execute([$host]);
$id = $sel->fetchColumn();

if ($id) {
  $up = db()->prepare("UPDATE olts SET name=?,vendor=?,ssh_port=?,username=?,password=?,enable_password=?,prompt_regex=?,is_active=? WHERE id=?");
  $up->execute([$name,$vendor,$port,$user,$pass,$epass,$regex,$active,$id]);
  $mode='updated';
} else {
  $ins = db()->prepare("INSERT INTO olts(name,vendor,host,ssh_port,username,password,enable_password,prompt_regex,is_active)
                        VALUES(?,?,?,?,?,?,?,?,?)");
  $ins->execute([$name,$vendor,$host,$port,$user,$pass,$epass,$regex,$active]);
  $id = db()->lastInsertId(); $mode='inserted';
}
header('Content-Type: application/json'); echo json_encode(['status'=>'success','mode'=>$mode,'id'=>$id]);
