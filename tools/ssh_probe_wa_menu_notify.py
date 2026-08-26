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

echo "=== init includes ===\n";
$init = file_get_contents("/home/bitrix/www/bitrix/php_interface/init.php");
foreach (["include_crm_button","include_portal","custom_chat"] as $n) {
  echo $n.": ".(strpos($init,$n)!==false?"yes":"no")."\n";
}
echo "init bytes=".strlen($init)." last:\n";
echo substr($init, -800)."\n";

echo "\n=== b_im_recent cols ===\n";
try {
  $r=$conn->query("SHOW COLUMNS FROM b_im_recent");
  while($x=$r->fetch()) echo $x["Field"]." ".$x["Type"]."\n";
} catch (\Throwable $e) { echo $e->getMessage()."\n"; }

echo "\n=== b_im_relation cols ===\n";
try {
  $r=$conn->query("SHOW COLUMNS FROM b_im_relation");
  while($x=$r->fetch()) echo $x["Field"]." ".$x["Type"]."\n";
} catch (\Throwable $e) { echo $e->getMessage()."\n"; }

echo "\n=== sample unread OL ===\n";
try {
  $r=$conn->query("SELECT r.USER_ID, r.CHAT_ID, r.COUNTER, c.TYPE, c.ENTITY_TYPE, LEFT(c.ENTITY_ID,80) EID, LEFT(c.TITLE,40) T
    FROM b_im_relation r
    INNER JOIN b_im_chat c ON c.ID=r.CHAT_ID
    WHERE r.COUNTER>0 AND (c.TYPE='L' OR c.ENTITY_TYPE='LINES')
    ORDER BY r.COUNTER DESC LIMIT 8");
  while($x=$r->fetch()) echo json_encode($x, JSON_UNESCAPED_UNICODE)."\n";
} catch (\Throwable $e) { echo $e->getMessage()."\n"; }

echo "\n=== rest app 64 ===\n";
$r=$conn->query("SELECT ID, APP_NAME, URL, CODE FROM b_rest_app WHERE ID=64");
while($x=$r->fetch()) echo json_encode($x, JSON_UNESCAPED_UNICODE)."\n";
'''
sftp = c.open_sftp()
with sftp.file("/tmp/wa_menu_notify.php", "w") as f:
    f.write(php)
sftp.close()
_, o, e = c.exec_command("php /tmp/wa_menu_notify.php 2>&1; rm -f /tmp/wa_menu_notify.php", timeout=90)
sys.stdout.buffer.write(o.read()[:12000])
sys.stdout.buffer.write(e.read()[:1500])

print("\n=== left-menu js counters ===")
_, o, _ = c.exec_command(
    "ls /home/bitrix/www/bitrix/js/intranet/left-menu/ 2>/dev/null | head; "
    "ls /home/bitrix/www/bitrix/modules/intranet/install/js/intranet/left-menu/ 2>/dev/null | head; "
    "grep -n 'updateCounters\\|menu-item-index\\|menu_app_' "
    "/home/bitrix/www/bitrix/js/intranet/left-menu/left-menu.js "
    "/home/bitrix/www/bitrix/modules/intranet/install/js/intranet/left-menu/src/*.js "
    "2>/dev/null | head -40",
    timeout=25)
sys.stdout.buffer.write(o.read()[:6000])

print("\n=== menu item generation ===")
_, o, _ = c.exec_command(
    "grep -n 'menu_app_\\|MENU_NAME\\|marketplace/app' "
    "/home/bitrix/www/bitrix/modules/intranet/lib/ui/leftmenu/*.php "
    "/home/bitrix/www/bitrix/modules/intranet/lib/leftmenu/*.php "
    "2>/dev/null | head -30; "
    "grep -rn 'menu_app_' /home/bitrix/www/bitrix/modules/rest/lib/ 2>/dev/null | head -20",
    timeout=25)
sys.stdout.buffer.write(o.read()[:5000])
c.close()
