<?php
// ... আগের কোড (mt_connect, mt_find_secret_id, mt_find_active_id, mt_pppoe_enable/disable/kick) ...

function mt_pppoe_status($API, $name){
    $active = $API->comm('/ppp/active/print', ['?name'=>$name]);
    $secret = $API->comm('/ppp/secret/print', ['?name'=>$name]);

    $online = false; $disabled = false; $data = [];
    if (is_array($active) && isset($active[0])) {
        $a = $active[0];
        $online = true;
        $data = [
            'address' => $a['address'] ?? '',
            'uptime'  => $a['uptime'] ?? '',
            'caller'  => $a['caller-id'] ?? '',
            'in'      => $a['limit-bytes-in'] ?? ($a['bytes-in'] ?? ''),
            'out'     => $a['limit-bytes-out'] ?? ($a['bytes-out'] ?? ''),
            'service' => $a['service'] ?? 'pppoe',
        ];
    }
    if (is_array($secret) && isset($secret[0])) {
        $s = $secret[0];
        $disabled = ($s['disabled'] ?? 'false') === 'true';
        $data['profile'] = $s['profile'] ?? '';
    }
    return ['online'=>$online, 'disabled'=>$disabled, 'data'=>$data];
}

function mt_pppoe_set_password($API, $name, $newpass){
    $id = mt_find_secret_id($API,$name);
    if ($id===null) return ['ok'=>false,'error'=>'secret not found'];
    $API->comm('/ppp/secret/set', ['.id'=>$id, 'password'=>$newpass]);
    return ['ok'=>true];
}

function mt_pppoe_set_profile($API, $name, $profile){
    $id = mt_find_secret_id($API,$name);
    if ($id===null) return ['ok'=>false,'error'=>'secret not found'];
    $API->comm('/ppp/secret/set', ['.id'=>$id, 'profile'=>$profile]);
    return ['ok'=>true];
}
