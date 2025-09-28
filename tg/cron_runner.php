<?php
// /tg/cron_runner.php
// বাংলা: queued Telegram মেসেজ পাঠানো (batch runner)
declare(strict_types=1);
error_reporting(E_ALL); ini_set('display_errors','0'); ini_set('log_errors','1');

$ROOT = dirname(__DIR__, 1);
require_once $ROOT.'/app/db.php';
require_once __DIR__.'/telegram.php';

$pdo=db(); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);

$res = tg_run_batch($pdo);
echo "Processed={$res['processed']} Sent={$res['sent']} Failed={$res['failed']}\n";
