#!/usr/bin/env python3
import paramiko
import sys

KEY = sys.argv[1] if len(sys.argv) > 1 else r"C:\Users\15bit\.ssh\id_rsa_bitrix"

def run(client, cmd, timeout=90):
    _, o, e = client.exec_command(cmd, timeout=timeout)
    out = o.read().decode("utf-8", "replace")
    err = e.read().decode("utf-8", "replace")
    return out + err

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="dockeradm", key_filename=KEY, timeout=30)

print("=== REGISTRATIONS ===")
print(run(c, "docker exec asterisk-beeline asterisk -rx 'pjsip show registrations'"))

print("=== SIP35 ===")
print(run(c, "docker exec asterisk-beeline asterisk -rx 'pjsip show endpoint sip35'"))

print("=== RECENT ASTERISK LOG (3888/sip35) ===")
print(run(c, "docker logs --tail 400 asterisk-beeline 2>&1 | grep -E '3888|8099|sip35|IN Beeline|CID|Dial|INVITE|Hangup|1787734778' | tail -80"))

c.close()

c2 = paramiko.SSHClient()
c2.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c2.connect("crm.artflowers.kz", username="bitrix", key_filename=KEY, timeout=30)

probe = r'''<?php
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
require $_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("voximplant");
\Bitrix\Main\Loader::includeModule("crm");
$rows = \Bitrix\Voximplant\StatisticTable::getList([
    "order" => ["ID" => "DESC"],
    "limit" => 8,
    "select" => ["ID","CALL_ID","PHONE_NUMBER","PORTAL_NUMBER","CALL_START_DATE","CRM_ENTITY_TYPE","CRM_ENTITY_ID","INCOMING","CALL_FAILED_CODE","CALL_FAILED_REASON"],
])->fetchAll();
echo "=== VOX LAST 8 ===\n";
foreach ($rows as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}
$leads = \CCrmLead::GetListEx(["ID"=>"DESC"], ["CHECK_PERMISSIONS"=>"N"], false, ["nTopCount"=>5], ["ID","TITLE","DATE_CREATE","SOURCE_ID"]);
echo "=== LEADS LAST 5 ===\n";
while ($l = $leads->Fetch()) {
    echo json_encode($l, JSON_UNESCAPED_UNICODE) . "\n";
}
'''

sftp = c2.open_sftp()
with sftp.open("/tmp/_probe_lead334008.php", "w") as f:
    f.write(probe.encode("utf-8"))
sftp.close()
print(run(c2, "php /tmp/_probe_lead334008.php 2>&1"))
c2.close()
