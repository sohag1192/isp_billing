<?php
// app/ssh.php — VSOL safe: multi strategy handshake + debug

function ssh_run_commands($host, $port, $user, $pass, $commands = [], $prompt_regex = null, $need_enable = false, $enable_pass = null, $debug = false) {
    $autoloads = [ __DIR__ . '/../vendor/autoload.php', __DIR__ . '/vendor/autoload.php' ];
    $loaded = false;
    foreach ($autoloads as $a) { if (file_exists($a)) { require_once $a; $loaded = true; break; } }
    if (!$loaded) return ['ok'=>false,'error'=>'phpseclib not found (composer install)'];

    $strategies = [
      // wide first (sha2/ctr + sha1/cbc mix)
      [
        'name'=>'wide+pty', 'pty'=>true,
        'alg'=>[
          'kex'=>['diffie-hellman-group14-sha256','diffie-hellman-group14-sha1','diffie-hellman-group-exchange-sha256','diffie-hellman-group-exchange-sha1','diffie-hellman-group1-sha1'],
          'hostkey'=>['ssh-rsa','ssh-dss'],
          'client_to_server'=>['crypt'=>['aes256-ctr','aes128-ctr','aes256-cbc','aes128-cbc','3des-cbc'],'mac'=>['hmac-sha2-256','hmac-sha1','hmac-md5'],'comp'=>['none']],
          'server_to_client'=>['crypt'=>['aes256-ctr','aes128-ctr','aes256-cbc','aes128-cbc','3des-cbc'],'mac'=>['hmac-sha2-256','hmac-sha1','hmac-md5'],'comp'=>['none']],
        ]
      ],
      [
        'name'=>'wide+nopty', 'pty'=>false, /* same alg */ 'alg'=>[
          'kex'=>['diffie-hellman-group14-sha256','diffie-hellman-group14-sha1','diffie-hellman-group-exchange-sha256','diffie-hellman-group-exchange-sha1','diffie-hellman-group1-sha1'],
          'hostkey'=>['ssh-rsa','ssh-dss'],
          'client_to_server'=>['crypt'=>['aes256-ctr','aes128-ctr','aes256-cbc','aes128-cbc','3des-cbc'],'mac'=>['hmac-sha2-256','hmac-sha1','hmac-md5'],'comp'=>['none']],
          'server_to_client'=>['crypt'=>['aes256-ctr','aes128-ctr','aes256-cbc','aes128-cbc','3des-cbc'],'mac'=>['hmac-sha2-256','hmac-sha1','hmac-md5'],'comp'=>['none']],
        ]
      ],
      // ultra-old (group1 + 3DES-CBC only) – অনেক VSOL এটাই লাগে
      [
        'name'=>'old+pty', 'pty'=>true,
        'alg'=>[
          'kex'=>['diffie-hellman-group1-sha1'],
          'hostkey'=>['ssh-rsa','ssh-dss'],
          'client_to_server'=>['crypt'=>['3des-cbc'],'mac'=>['hmac-sha1','hmac-md5'],'comp'=>['none']],
          'server_to_client'=>['crypt'=>['3des-cbc'],'mac'=>['hmac-sha1','hmac-md5'],'comp'=>['none']],
        ]
      ],
      [
        'name'=>'old+nopty', 'pty'=>false,
        'alg'=>[
          'kex'=>['diffie-hellman-group1-sha1'],
          'hostkey'=>['ssh-rsa','ssh-dss'],
          'client_to_server'=>['crypt'=>['3des-cbc'],'mac'=>['hmac-sha1','hmac-md5'],'comp'=>['none']],
          'server_to_client'=>['crypt'=>['3des-cbc'],'mac'=>['hmac-sha1','hmac-md5'],'comp'=>['none']],
        ]
      ],
    ];

    $prompt_regex = $prompt_regex ?: '.*[>#]\s*$';
    $steps = [];

    foreach ($strategies as $S) {
      try {
        $ssh = new \phpseclib3\Net\SSH2($host, (int)$port);
        $ssh->setPreferredAlgorithms($S['alg']);
        $ssh->setTimeout(8);

        $ok = $ssh->login($user, $pass);
        if (!$ok) {
          $ok = $ssh->login($user, function($prompts) use ($pass){ return is_array($prompts)?array_fill(0,count($prompts),$pass):$pass; });
        }
        if (!$ok) { $steps[]=['strategy'=>$S['name'],'err'=>'auth failed']; $ssh->disconnect(); continue; }

        if ($S['pty']) $ssh->enablePTY();

        // initial prompt
        $ssh->write("\r\n");
        $ssh->read("/$prompt_regex/");

        // enable flow (VSOL)
        if ($need_enable) {
          $ssh->write("enable\r\n");
          $ssh->read('/[Pp]assword[: ]*$/');          // may or may not prompt
          $ssh->write(($enable_pass ?: $pass) . "\r\n");
          $ssh->read("/$prompt_regex/");
          // pager off
          $ssh->write("terminal length 0\r\n");
          $ssh->read("/$prompt_regex/");
        }

        // run commands
        $full = '';
        foreach ((array)$commands as $cmd) {
          if (!$cmd) continue;
          $ssh->write($cmd . "\r\n");
          $full .= $ssh->read("/$prompt_regex/");
        }
        $ssh->disconnect();
        if ($debug) $steps[]=['strategy'=>$S['name'],'ok'=>true,'len'=>strlen($full)];
        return ['ok'=>true,'output'=>$full,'steps'=>$debug?$steps:[]];

      } catch (\phpseclib3\Exception\ConnectionClosedException $e) {
        $steps[]=['strategy'=>$S['name'],'err'=>'closed by server'];
      } catch (\Throwable $e) {
        $steps[]=['strategy'=>$S['name'],'err'=>$e->getMessage()];
      }
    }

    return ['ok'=>false,'error'=>'ssh connection closed by server (all strategies failed)','steps'=>$debug?$steps:[]];
}
