#!/usr/bin/env python3
import paramiko, sys
KEY = sys.argv[1] if len(sys.argv) > 1 else r"C:\Users\15bit\.ssh\id_rsa_bitrix"
script = r'''<?php
$_SERVER["DOCUMENT_ROOT"] = "/home/bitrix/www";
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
require $_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("voximplant");
\Bitrix\Main\Loader::includeModule("crm");

$callId = "D0438349E10D31E5.1787734778.20926632";
$real = "+77076474703";
$stat = \Bitrix\Voximplant\StatisticTable::getList(["filter"=>["=CALL_ID"=>$callId],"select"=>["*"]])->fetch();
if (!$stat) { echo "stat missing\n"; exit(1); }

$entityType = (string)($stat["CRM_ENTITY_TYPE"] ?? "");
$entityId = (int)($stat["CRM_ENTITY_ID"] ?? 0);
$leadOk = $entityType === "LEAD" && $entityId > 0 && (bool)\CCrmLead::GetByID($entityId, false);

if (!$leadOk) {
    $arFields = \CVoxImplantCrmHelper::getLeadFields([
        "USER_ID" => (int)($stat["PORTAL_USER_ID"] ?? 139808),
        "PHONE_NUMBER" => $real,
        "SEARCH_ID" => "sip35",
        "CRM_SOURCE" => "CALL",
        "INCOMING" => \CVoxImplantMain::CALL_INCOMING,
    ]);
    $lead = new \CCrmLead(false);
    $newId = (int)$lead->Add($arFields);
    if ($newId <= 0) {
        echo "lead add fail: " . $lead->LAST_ERROR . "\n";
        exit(2);
    }
    $entityType = "LEAD";
    $entityId = $newId;
    echo "created lead $newId\n";
} else {
    echo "lead exists $entityId\n";
}

\Bitrix\Voximplant\StatisticTable::update((int)$stat["ID"], [
    "PHONE_NUMBER" => $real,
    "CRM_ENTITY_TYPE" => $entityType,
    "CRM_ENTITY_ID" => $entityId,
]);
echo "stat patched\n";
$l = \CCrmLead::GetByID($entityId, false);
echo "lead=" . json_encode(["ID"=>$entityId,"TITLE"=>$l["TITLE"]??""], JSON_UNESCAPED_UNICODE) . "\n";
'''
c = paramiko.SSHClient(); c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", key_filename=KEY, timeout=30)
sftp = c.open_sftp()
with sftp.open("/tmp/_retro_cid2.php", "w") as f: f.write(script.encode())
sftp.close()
_, o, e = c.exec_command("php /tmp/_retro_cid2.php 2>&1", timeout=60)
print(o.read().decode()); print(e.read().decode())
c.close()
