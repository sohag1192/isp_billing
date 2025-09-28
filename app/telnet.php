<?php
// app/telnet.php — robust Telnet runner (login → enable → commands)
// null-byte safe, byte-wise IAC stripping, bounded timeouts + optional debug

function telnet_run_commands(
    $host, $port, $user, $pass,
    $commands = [], $prompt_regex = null,
    $need_enable = false, $enable_pass = null,
    $debug = false, $timeout_sec = 8
){
    $steps = [];
    $prompt_regex = $prompt_regex ?: '.*[>#]\s*$';

    $errno = 0; $errstr = '';
    $fp = @fsockopen($host, (int)$port, $errno, $errstr, 5);
    if (!$fp) {
        return ['ok'=>false, 'error'=>"telnet connect failed: $errstr ($errno)", 'steps'=>$debug?$steps:[]];
    }
    stream_set_blocking($fp, true);
    stream_set_timeout($fp, $timeout_sec);

    $write = function($s) use ($fp){ @fwrite($fp, $s); };

    // ---- strip telnet IAC bytes (no regex, byte-wise) ----
    $strip_telnet = function($buf){
        $out = '';
        $len = strlen($buf);
        for ($i=0; $i<$len; $i++) {
            $b = ord($buf[$i]);
            if ($b === 0xFF) { // IAC
                if ($i+1 >= $len) break;
                $cmd = ord($buf[++$i]);
                if ($cmd === 0xFA) { // SB ... IAC SE
                    $pos = strpos($buf, "\xFF\xF0", $i+1);
                    if ($pos === false) break;
                    $i = $pos + 1;
                } else {
                    if ($i+1 < $len) $i++;
                }
                continue;
            }
            if ($b !== 0x00) { // drop NUL
                $out .= $buf[$i];
            }
        }
        // strip ANSI CSI (safe regex)
        $out = preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $out);
        // normalize newlines
        $out = str_replace("\r", '', $out);
        return $out;
    };

    // ---- bounded reader with stream_select ----
    $read_until = function($regex) use ($fp, $timeout_sec, $strip_telnet){
        $deadline = microtime(true) + $timeout_sec;
        $buf = '';
        while (microtime(true) < $deadline) {
            $r = [$fp]; $w = null; $e = null;
            $n = @stream_select($r, $w, $e, 0, 200000); // 200ms
            if ($n === false) break;
            if ($n > 0) {
                $chunk = @fread($fp, 4096);
                if ($chunk === '' || $chunk === false) { usleep(60000); continue; }
                $buf .= $chunk;
                $clean = $strip_telnet($buf);
                if (@preg_match("/{$regex}/m", $clean)) return $clean;
            } else {
                $clean = $strip_telnet($buf);
                if ($clean !== '' && @preg_match("/{$regex}/m", $clean)) return $clean;
            }
        }
        return $strip_telnet($buf); // timeout fallback
    };

    // ---- greeting / login ----
    $out = $read_until('login:|username:|User Name|User:|Password:|'.$prompt_regex);
    if ($debug) $steps[] = ['stage'=>'greeting', 'out'=>mb_substr($out, -2000)];

    if (preg_match('/login:|username:|User Name|User:/i', $out)) {
        $write($user . "\r\n");
        $out = $read_until('Password:|password:|'.$prompt_regex);
        if ($debug) $steps[] = ['stage'=>'username-sent', 'out'=>mb_substr($out, -2000)];
    }

    if (preg_match('/Password:/i', $out)) {
        $write(($pass ?? '') . "\r\n");
        $out = $read_until($prompt_regex);
        if ($debug) $steps[] = ['stage'=>'password-sent', 'out'=>mb_substr($out, -2000)];
    }

    // ---- enable flow (VSOL) ----
    if ($need_enable) {
        $write("enable\r\n");
        $out = $read_until('Password:|password:|'.$prompt_regex);
        if ($debug) $steps[] = ['stage'=>'enable-enter', 'out'=>mb_substr($out, -2000)];
        $write(($enable_pass ?: $pass) . "\r\n");
        $out = $read_until($prompt_regex);
        if ($debug) $steps[] = ['stage'=>'enable-ok', 'out'=>mb_substr($out, -2000)];

        // pager off (multiple variants)
        foreach (["terminal length 0","screen-length 0 temporary","no page"] as $pg) {
            $write($pg . "\r\n");
            $out = $read_until($prompt_regex);
        }
        if ($debug) $steps[] = ['stage'=>'pager-off', 'out'=>mb_substr($out, -2000)];
    }

    // ---- run commands ----
    $full = '';
    foreach ((array)$commands as $cmd) {
        if (!$cmd) continue;
        $write($cmd . "\r\n");
        $chunk = $read_until($prompt_regex);
        $full .= $chunk;
        if ($debug) $steps[] = ['cmd'=>$cmd, 'out'=>mb_substr($chunk, -4000)];
    }

    @fclose($fp);
    return ['ok'=>true, 'output'=>$full, 'steps'=>$debug?$steps:[]];
}
