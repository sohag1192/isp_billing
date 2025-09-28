<?php
require_once __DIR__ . '/../app/db.php';

$today = date('Y-m-d');

// ১ম ধাপ: আজ মেয়াদ শেষ হলে Expired করা
$stmt_expire = db()->prepare("UPDATE clients SET status='expired' 
                              WHERE expire_date = ? AND status != 'expired' AND status != 'inactive'");
$expire_count = $stmt_expire->execute([$today]) ? $stmt_expire->rowCount() : 0;

// ২য় ধাপ: Expired হওয়ার পরের দিন Inactive করা
$yesterday = date('Y-m-d', strtotime('-1 day'));
$stmt_inactive = db()->prepare("UPDATE clients SET status='inactive' 
                                WHERE expire_date = ? AND status='expired'");
$inactive_count = $stmt_inactive->execute([$yesterday]) ? $stmt_inactive->rowCount() : 0;

// রেজাল্ট দেখানো
echo "✅ Auto Process Completed\n";
echo "📌 Expired updated: $expire_count\n";
echo "📌 Inactive updated: $inactive_count\n";
