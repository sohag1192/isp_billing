<?php
// ===============================
// সিস্টেম কনফিগারেশন ফাইল
// ===============================

// ডাটাবেজ কানেকশন সেটিংস
define('DB_HOST', 'localhost');
define('DB_USER', 'root');       // আপনার MySQL ইউজারনেম
define('DB_PASS', '');           // আপনার MySQL পাসওয়ার্ড
define('DB_NAME', 'isp_billing'); // আপনার ডাটাবেজ নাম
define('SMS_WEBHOOK_SECRET', 'pk_zQj7BjPYjvDzCoWBqT2vI6MLbJL3bvUdTFS28v2_BoezYPSWmXiDFXgRh4kJoy1Z'); // REQUIRED
define('SMS_IP_WHITELIST',   ''); // e.g. '103.120.XX.XX, 203.76.XX.XX' (optional)


// /app/config.php
define('TELEGRAM_BOT_TOKEN', '8026229240:AAE0n51ieMpODNTy_CFaURAmfp9sdmUYppg'); // <-- BotFather থেকে পাওয়া Token
define('TELEGRAM_CHAT_ID',  '-4879526673');    // <-- আপনার user/group/channel chat_id



// সেশন লাইফটাইম (১ ঘণ্টা)
define('SESSION_LIFETIME', 3600);

// টাইমজোন সেট করুন
date_default_timezone_set('Asia/Dhaka');

// এরর রিপোর্টিং
error_reporting(E_ALL);
ini_set('display_errors', 1);
