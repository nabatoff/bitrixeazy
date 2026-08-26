#!/usr/bin/env python3
import sys
import paramiko

KEY = sys.argv[1] if len(sys.argv) > 1 else r"C:\Users\15bit\.ssh\id_rsa_bitrix"
php = r'''<?php
$_SERVER["DOCUMENT_ROOT"] = "/home/bitrix/www";
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("voximplant");
$conn = \Bitrix\Main\Application::getConnection();
$uid = 139808;
echo "portal_number=".COption::GetOptionString("voximplant","portal_number")."\n";
if (class_exists("CVoxImplantUser")) {
    $u = new CVoxImplantUser();
    foreach (["GetPhoneActive","GetUserPhone","GetDefaultLine"] as $m) {
        if (method_exists($u, $m)) echo "CVoxImplantUser::$m exists\n";
    }
}
if (class_exists("CVoxImplantConfig")) {
    foreach (["GetPortalNumber","GetDefaultLine","getLines"] as $m) {
        if (method_exists("CVoxImplantConfig", $m)) echo "CVoxImplantConfig::$m exists\n";
    }
    if (method_exists("CVoxImplantConfig","GetPortalNumber")) {
        echo "GetPortalNumber=".CVoxImplantConfig::GetPortalNumber()."\n";
    }
}
echo "\n=== user 139808 options ===\n";
$uo = $conn->query("SELECT CATEGORY, NAME, LEFT(VALUE,200) V FROM b_user_option WHERE USER_ID=139808 AND (CATEGORY LIKE '%vox%' OR NAME LIKE '%line%' OR NAME LIKE '%phone%' OR NAME LIKE '%sip%' OR VALUE LIKE '%sip35%' OR VALUE LIKE '%3888%')");
while ($x = $uo->fetch()) echo $x["CATEGORY"]." / ".$x["NAME"]." = ".$x["V"]."\n";
echo "\n=== access 139808 ===\n";
$a = $conn->query("SELECT a.CONFIG_ID, c.PHONE_NAME, c.SEARCH_ID, a.ACCESS_CODE FROM b_voximplant_line_access a LEFT JOIN b_voximplant_config c ON c.ID=a.CONFIG_ID WHERE a.ACCESS_CODE IN ('U139808','DR','G1') OR a.ACCESS_CODE LIKE 'U139808%'");
while ($x = $a->fetch()) echo "cfg ".$x["CONFIG_ID"]." ".$x["PHONE_NAME"]." ".$x["SEARCH_ID"]." ".$x["ACCESS_CODE"]."\n";
echo "\n=== sip 35/36 now ===\n";
$s = $conn->query("SELECT ID, LOGIN, SERVER, TYPE FROM b_voximplant_sip WHERE ID IN (35,36)");
while ($x = $s->fetch()) echo $x["ID"]." ".$x["LOGIN"]." ".$x["SERVER"]." ".$x["TYPE"]."\n";
'''
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", key_filename=KEY, timeout=25)
sftp = c.open_sftp()
with sftp.file("/tmp/vi_userline.php", "w") as f:
    f.write(php)
sftp.close()
_, o, e = c.exec_command("php /tmp/vi_userline.php; rm -f /tmp/vi_userline.php", timeout=40)
sys.stdout.buffer.write(o.read())
sys.stderr.buffer.write(e.read())
c.close()
