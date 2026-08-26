#!/usr/bin/env python3
import paramiko
import sys

KEY = sys.argv[1] if len(sys.argv) > 1 else r"C:\Users\15bit\.ssh\id_rsa_bitrix"

def run(user, cmd, timeout=120):
    c = paramiko.SSHClient()
    c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    c.connect("crm.artflowers.kz", username=user, key_filename=KEY, timeout=30)
    _, o, e = c.exec_command(cmd, timeout=timeout)
    out = o.read().decode("utf-8", "replace")
    err = e.read().decode("utf-8", "replace")
    c.close()
    return out + err

probe = r'''<?php
$_SERVER["DOCUMENT_ROOT"] = "/home/bitrix/www";
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
require $_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("voximplant");
\Bitrix\Main\Loader::includeModule("crm");

echo "=== VOX LAST 12 ===\n";
$rows = \Bitrix\Voximplant\StatisticTable::getList([
    "order" => ["ID" => "DESC"],
    "limit" => 12,
    "select" => [
        "ID","CALL_ID","PHONE_NUMBER","PORTAL_NUMBER","CALL_START_DATE",
        "CRM_ENTITY_TYPE","CRM_ENTITY_ID","INCOMING","CALL_FAILED_CODE",
        "CALL_FAILED_REASON","CALL_DURATION","PORTAL_USER_ID","CRM_ACTIVITY_ID",
    ],
])->fetchAll();
foreach ($rows as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "=== LEADS LAST 8 ===\n";
$leads = \CCrmLead::GetListEx(
    ["ID" => "DESC"],
    ["CHECK_PERMISSIONS" => "N"],
    false,
    ["nTopCount" => 8],
    ["ID","TITLE","DATE_CREATE","SOURCE_ID","STATUS_ID"]
);
while ($l = $leads->Fetch()) {
    $phones = [];
    $rs = \CCrmFieldMulti::GetList([], ["ENTITY_ID"=>"LEAD","ELEMENT_ID"=>$l["ID"],"TYPE_ID"=>"PHONE"]);
    while ($p = $rs->Fetch()) { $phones[] = $p["VALUE"]; }
    $l["PHONES"] = $phones;
    echo json_encode($l, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "=== SIP LINES (3888/sip35) ===\n";
if (class_exists("\\Bitrix\\Voximplant\\ConfigTable")) {
    $cfg = \Bitrix\Voximplant\ConfigTable::getList([
        "filter" => ["%SEARCH_ID" => ["3888","8099","sip35","sip36"]],
        "select" => ["ID","SEARCH_ID","PHONE_NAME","CRM_CREATE","CRM_CREATE_CALL_TYPE","CRM_FORWARD","CRM_RULE","PORTAL_MODE"],
    ])->fetchAll();
    foreach ($cfg as $c) echo json_encode($c, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "=== BRIDGE LOG ===\n";
$f = "/home/bitrix/www/local/crm/cid-bridge/recent.jsonl";
if (is_file($f)) {
    $lines = file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach (array_slice($lines, -5) as $line) echo $line . "\n";
}
'''

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", key_filename=KEY, timeout=30)
sftp = c.open_sftp()
with sftp.open("/tmp/_probe_vox_leads2.php", "w") as f:
    f.write(probe.encode("utf-8"))
sftp.close()
c.close()

print(run("bitrix", "php /tmp/_probe_vox_leads2.php 2>&1"))

print("=== ASTERISK last IN Beeline ===")
print(run("dockeradm", "docker logs --since 45m asterisk-beeline 2>&1 | grep 'IN Beeline' | tail -5"))

print("=== ASTERISK last Dial sip35 ===")
print(run("dockeradm", "docker logs --since 45m asterisk-beeline 2>&1 | grep -i sip35 | tail -15"))
