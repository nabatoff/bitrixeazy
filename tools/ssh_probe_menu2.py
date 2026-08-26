#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)

php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true); define("NOT_CHECK_PERMISSIONS",true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
$conn = \Bitrix\Main\Application::getConnection();

echo "=== b_rest_app_lang app 64 ===\n";
$r = $conn->query("SELECT * FROM b_rest_app_lang WHERE APP_ID=64");
while ($x = $r->fetch()) echo json_encode($x, JSON_UNESCAPED_UNICODE)."\n";

echo "\n=== b_rest_app_attribute 64 ===\n";
$r = $conn->query("SELECT * FROM b_rest_app_attribute WHERE APP_ID=64");
while ($x = $r->fetch()) echo json_encode($x, JSON_UNESCAPED_UNICODE)."\n";

echo "\n=== rest_app 64 full ===\n";
$r = $conn->query("SELECT * FROM b_rest_app WHERE ID=64");
while ($x = $r->fetch()) {
  foreach ($x as $k=>$v) {
    if (is_string($v) && strlen($v)>200) $v = substr($v,0,200)."...";
    echo "$k=$v\n";
  }
}

echo "\n=== left_menu_sorted sample with app/local/ватсап ===\n";
$r = $conn->query("SELECT USER_ID, LEFT(VALUE,1500) V FROM b_user_option WHERE NAME='left_menu_sorted_items_s1' AND (VALUE LIKE '%app%64%' OR VALUE LIKE '%local.6a7b%' OR VALUE LIKE '%ватсап%' OR VALUE LIKE '%WhatsApp%' OR VALUE LIKE '%custom_chat%') LIMIT 3");
while ($x = $r->fetch()) echo "u=".$x["USER_ID"]." ".$x["V"]."\n";

echo "\n=== any option containing menu_app ===\n";
$r = $conn->query("SELECT USER_ID, NAME, LEFT(VALUE,400) V FROM b_user_option WHERE VALUE LIKE '%menu_app%' AND NAME LIKE 'left_menu%' LIMIT 8");
while ($x = $r->fetch()) echo "u=".$x["USER_ID"]." ".$x["NAME"]." ".$x["V"]."\n";

echo "\n=== CUserCounter codes like menu/im/ol ===\n";
$r = $conn->query("SELECT CODE, COUNT(*) CNT, SUM(CNT) S FROM b_user_counter WHERE CODE LIKE '%menu%' OR CODE LIKE '%app%' OR CODE LIKE '%im%' OR CODE LIKE '%ol%' GROUP BY CODE ORDER BY CNT DESC LIMIT 25");
while ($x = $r->fetch()) echo $x["CODE"]." users=".$x["CNT"]." sum=".$x["S"]."\n";
'''
sftp = c.open_sftp()
with sftp.file("/tmp/wa_menu2.php", "w") as f:
    f.write(php)
sftp.close()
_, o, e = c.exec_command("php /tmp/wa_menu2.php 2>&1; rm -f /tmp/wa_menu2.php", timeout=90)
sys.stdout.buffer.write(o.read()[:12000])
sys.stdout.buffer.write(e.read()[:1500])

print("\n=== grep LeftMenu counter / app icon ===")
_, o, e = c.exec_command(
    "grep -n 'menu_app_\\|setCounter\\|updateCounters' /home/bitrix/www/bitrix/modules/intranet/install/js/intranet/left-menu/*.js 2>/dev/null | head -25; "
    "ls /home/bitrix/www/bitrix/modules/intranet/install/js/intranet/left-menu/ 2>/dev/null | head; "
    "grep -l 'LEFT_MENU' /home/bitrix/www/bitrix/modules/rest/lib/*.php 2>/dev/null | head",
    timeout=25)
sys.stdout.buffer.write(o.read()[:4000])
c.close()
