#!/usr/bin/env python3
"""Retro-fix call 19039 / deleted lead 334008."""
import paramiko, sys
KEY = sys.argv[1] if len(sys.argv) > 1 else r"C:\Users\15bit\.ssh\id_rsa_bitrix"
script = r'''<?php
$_SERVER["DOCUMENT_ROOT"] = "/home/bitrix/www";
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
require $_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php";
require $_SERVER["DOCUMENT_ROOT"] . "/local/crm/include_vox_cid_fix.php";
\Bitrix\Main\Loader::includeModule("voximplant");
\Bitrix\Main\Loader::includeModule("crm");

$callId = "D0438349E10D31E5.1787734778.20926632";
$real = "+77076474703";
$call = \Bitrix\Voximplant\Call::load($callId);
if (!$call) { echo "call not found\n"; exit(1); }
$ok = waVoxCidFix_rebindCall($call, $real);
echo "rebind=" . ($ok ? "ok" : "fail") . "\n";
echo "entity=" . $call->getPrimaryEntityType() . ":" . $call->getPrimaryEntityId() . "\n";
$stat = \Bitrix\Voximplant\StatisticTable::getList(["filter"=>["=CALL_ID"=>$callId],"select"=>["ID","PHONE_NUMBER","CRM_ENTITY_TYPE","CRM_ENTITY_ID"]])->fetch();
echo "stat=" . json_encode($stat, JSON_UNESCAPED_UNICODE) . "\n";
'''
c = paramiko.SSHClient(); c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", key_filename=KEY, timeout=30)
sftp = c.open_sftp()
with sftp.open("/tmp/_retro_cid.php", "w") as f: f.write(script.encode())
sftp.close()
_, o, e = c.exec_command("php /tmp/_retro_cid.php 2>&1", timeout=60)
print(o.read().decode()); print(e.read().decode())
c.close()
