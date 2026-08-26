#!/usr/bin/env python3
import paramiko, sys
KEY = sys.argv[1] if len(sys.argv) > 1 else r"C:\Users\15bit\.ssh\id_rsa_bitrix"
probe = r'''<?php
$_SERVER["DOCUMENT_ROOT"] = "/home/bitrix/www";
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
require $_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("crm");
\Bitrix\Main\Loader::includeModule("voximplant");

$id = 334008;
$lead = \CCrmLead::GetByID($id, false);
echo "=== LEAD 334008 ===\n";
echo json_encode($lead, JSON_UNESCAPED_UNICODE) . "\n";
echo "=== PHONES ===\n";
$rs = \CCrmFieldMulti::GetList([], ["ENTITY_ID"=>"LEAD","ELEMENT_ID"=>$id,"TYPE_ID"=>"PHONE"]);
while ($p = $rs->Fetch()) echo json_encode($p, JSON_UNESCAPED_UNICODE) . "\n";

echo "=== CALLS ON 334008 ===\n";
$rows = \Bitrix\Voximplant\StatisticTable::getList([
    "filter" => ["=CRM_ENTITY_TYPE" => "LEAD", "=CRM_ENTITY_ID" => $id],
    "order" => ["ID" => "DESC"],
    "select" => ["ID","CALL_ID","PHONE_NUMBER","CALL_START_DATE","CALL_DURATION","CALL_FAILED_REASON"],
])->fetchAll();
foreach ($rows as $r) echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";

echo "=== LEAD BY PHONE +77076474703 ===\n";
$found = \CCrmLead::GetListEx([], ["CHECK_PERMISSIONS"=>"N","PHONE"=>"+77076474703"], false, false, ["ID","TITLE","DATE_CREATE"]);
while ($l = $found->Fetch()) echo json_encode($l, JSON_UNESCAPED_UNICODE) . "\n";
'''
c = paramiko.SSHClient(); c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", key_filename=KEY, timeout=30)
sftp = c.open_sftp()
with sftp.open("/tmp/_probe_334008.php", "w") as f: f.write(probe.encode())
sftp.close()
_, o, e = c.exec_command("php /tmp/_probe_334008.php 2>&1", timeout=60)
print(o.read().decode()); print(e.read().decode())
c.close()
