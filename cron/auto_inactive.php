<?php
require_once __DIR__ . '/../app/db.php';

// আজকের তারিখ
$today = date('Y-m-d');

// মেয়াদোত্তীর্ণ ক্লায়েন্ট Inactive করা
$stmt = db()->prepare("UPDATE clients SET status='inactive' WHERE expire_date < ? AND status != 'inactive'");
if ($stmt->execute([$today])) {
    echo "✅ Auto Inactive process completed successfully.\n";
} else {
    echo "❌ Failed to update inactive clients.\n";
}
