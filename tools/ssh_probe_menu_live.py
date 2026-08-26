#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)

cmds = [
    "grep -n 'ItemRestApplication\\|rest.app\\|marketplace/app\\|MENU_NAME\\|getItems' /home/bitrix/www/bitrix/modules/intranet/lib/UI/LeftMenu/Menu.php | head -40",
    "sed -n '90,180p' /home/bitrix/www/bitrix/modules/intranet/lib/UI/LeftMenu/Menu.php",
    "grep -rn 'ItemRestApplication\\|REST_APP\\|marketplace/app' /home/bitrix/www/bitrix/modules/intranet/lib/UI/LeftMenu /home/bitrix/www/bitrix/modules/intranet/lib/Internal/Service/LeftMenu 2>/dev/null | head -40",
    "ls /home/bitrix/www/bitrix/modules/intranet/install/templates/bitrix24/components/bitrix/menu/left_vertical/ 2>/dev/null | head",
    "find /home/bitrix/www/bitrix/templates/bitrix24 -iname '*left*' -name '*.js' | head -20",
    "grep -n 'OnEpilog\\|composite' /home/bitrix/www/bitrix/php_interface/init.php | head",
    "grep -n 'include_crm_button\\|include_portal' /home/bitrix/www/bitrix/php_interface/init.php",
]
for cmd in cmds:
    print("===", cmd[:100])
    _, o, e = c.exec_command(cmd, timeout=20)
    sys.stdout.buffer.write(o.read()[:3500])
    print()

php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true); define("NOT_CHECK_PERMISSIONS",true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
$conn = \Bitrix\Main\Application::getConnection();

echo "=== rest app 64 ===\n";
$r=$conn->query("SELECT ID, APP_NAME, URL, CODE, SCOPE FROM b_rest_app WHERE ID=64");
while($x=$r->fetch()) echo json_encode($x, JSON_UNESCAPED_UNICODE)."\n";

echo "\n=== b_rest_app_lang ===\n";
$r=$conn->query("SELECT * FROM b_rest_app_lang WHERE APP_ID=64");
while($x=$r->fetch()) echo json_encode($x, JSON_UNESCAPED_UNICODE)."\n";

echo "\n=== left_menu_self_items with 64/ватсап/custom_chat ===\n";
$r=$conn->query("SELECT USER_ID, NAME, LEFT(VALUE,500) V FROM b_user_option WHERE (VALUE LIKE '%menu_app_64%' OR VALUE LIKE '%ватсап%' OR VALUE LIKE '%custom_chat%' OR VALUE LIKE '%marketplace/app/64%' OR VALUE LIKE '%local.6a7b%') AND NAME LIKE '%menu%' LIMIT 8");
while($x=$r->fetch()) echo "u=".$x["USER_ID"]." ".$x["NAME"]." ".$x["V"]."\n";

echo "\n=== option names left_menu ===\n";
$r=$conn->query("SELECT NAME, COUNT(*) C FROM b_user_option WHERE NAME LIKE '%left_menu%' GROUP BY NAME ORDER BY C DESC LIMIT 15");
while($x=$r->fetch()) echo $x["NAME"]." ".$x["C"]."\n";
'''
sftp = c.open_sftp()
with sftp.file("/tmp/wa_menu_html.php", "w") as f:
    f.write(php)
sftp.close()
print("=== php ===")
_, o, e = c.exec_command("php /tmp/wa_menu_html.php 2>&1; rm -f /tmp/wa_menu_html.php", timeout=60)
sys.stdout.buffer.write(o.read()[:8000])
c.close()
