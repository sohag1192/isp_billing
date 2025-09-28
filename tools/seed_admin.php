<?php
// Admin Seeder Script
// ফাইল রান করলে একটি ডিফল্ট অ্যাডমিন ইউজার তৈরি হবে

require_once __DIR__ . '/../app/db.php';

$username   = 'admin';            // ডিফল্ট ইউজারনেম
$password   = 'admin';        // ডিফল্ট পাসওয়ার্ড (লগইনের সময় এইটা ব্যবহার করবেন)
$full_name  = 'Super Admin';       // নাম
$role       = 'admin';             // ভূমিকা
$status     = 1;                    // Active

// আগে চেক করুন ইউজার আছে কিনা
$check = db()->prepare("SELECT id FROM users WHERE username = ?");
$check->execute([$username]);

if ($check->rowCount() > 0) {
    echo "❌ User '{$username}' already exists.\n";
    exit;
}

// পাসওয়ার্ড MD5 এ কনভার্ট
$hashedPassword = md5($password);

// ইনসার্ট করুন
$stmt = db()->prepare("INSERT INTO users (username, password, full_name, role, status) VALUES (?, ?, ?, ?, ?)");
$stmt->execute([$username, $hashedPassword, $full_name, $role, $status]);

echo "✅ Admin user created successfully!\n";
echo "Username: {$username}\n";
echo "Password: {$password}\n";
