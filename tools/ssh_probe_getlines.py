#!/usr/bin/env python3
import sys
import paramiko

KEY = sys.argv[1] if len(sys.argv) > 1 else r"C:\Users\15bit\.ssh\id_rsa_bitrix"
php = r'''<?php
$_SERVER["DOCUMENT_ROOT"] = "/home/bitrix/www";
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
global $USER;
$USER->Authorize(139808);
\Bitrix\Main\Loader::includeModule("voximplant");

echo "=== sip 35/36 ===\n";
$conn = \Bitrix\Main\Application::getConnection();
$r = $conn->query("SELECT * FROM b_voximplant_sip WHERE ID IN (35,36)");
while ($x = $r->fetch()) {
    $x["PASSWORD"] = isset($x["PASSWORD"]) && $x["PASSWORD"]!=="" ? "(set)" : "(empty)";
    $x["INCOMING_PASSWORD"] = !empty($x["INCOMING_PASSWORD"]) ? "(set)" : "(empty)";
    echo json_encode($x, JSON_UNESCAPED_UNICODE)."\n";
}
echo "\n=== config 35/36 ===\n";
$c = $conn->query("SELECT ID,SEARCH_ID,PHONE_NAME,CAN_BE_SELECTED,PHONE_VERIFIED,PORTAL_MODE FROM b_voximplant_config WHERE ID IN (30,35,36,23)");
while ($x = $c->fetch()) echo json_encode($x, JSON_UNESCAPED_UNICODE)."\n";

echo "\n=== getLines ===\n";
if (method_exists("CVoxImplantConfig", "GetList")) {
    $list = CVoxImplantConfig::GetList();
    if (is_object($list) && method_exists($list, "fetch")) {
        while ($row = $list->fetch()) {
            echo "GetList ".$row["ID"]." ".$row["SEARCH_ID"]." ".$row["PHONE_NAME"]."\n";
        }
    }
}
if (function_exists("voximplant_get_user_lines") ) echo "fn voximplant_get_user_lines\n";

$classes = [
    ["CVoxImplantConfig", "getLines"],
    ["CVoxImplantConfig", "GetLines"],
    ["CVoxImplantConfig", "GetPortalNumbers"],
    ["CVoxImplantConfig", "GetCallbackNumbers"],
    ["Bitrix\\Voximplant\\Config", "getLines"],
];
foreach ($classes as $pair) {
    if (method_exists($pair[0], $pair[1])) echo "method {$pair[0]}::{$pair[1]}\n";
}

if (method_exists("CVoxImplantConfig", "GetPortalNumbers")) {
    $nums = CVoxImplantConfig::GetPortalNumbers(true);
    echo "GetPortalNumbers=\n";
    echo json_encode($nums, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
}
if (method_exists("CVoxImplantConfig", "getLines")) {
    $lines = CVoxImplantConfig::getLines(true, true);
    echo "getLines=\n";
    echo json_encode($lines, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
}
'''
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", key_filename=KEY, timeout=25)
sftp = c.open_sftp()
with sftp.file("/tmp/vi_getlines.php", "w") as f:
    f.write(php)
sftp.close()
_, o, e = c.exec_command("php /tmp/vi_getlines.php; rm -f /tmp/vi_getlines.php", timeout=60)
sys.stdout.buffer.write(o.read()[:15000])
sys.stderr.buffer.write(e.read()[:3000])
c.close()
