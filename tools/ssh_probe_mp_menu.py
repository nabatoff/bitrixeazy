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

echo "=== marketplace menu items option ===\n";
$raw = \COption::GetOptionString("intranet", "left_menu_items_marketplace_s1");
echo "len=".strlen((string)$raw)."\n";
$arr = @unserialize($raw, ["allowed_classes"=>false]);
if (!is_array($arr)) $arr = @unserialize($raw);
if (is_array($arr)) {
  echo "n=".count($arr)."\n";
  foreach ($arr as $it) {
    $blob = json_encode($it, JSON_UNESCAPED_UNICODE);
    if (stripos($blob,"64")!==false || stripos($blob,"ватсап")!==false || stripos($blob,"custom_chat")!==false || stripos($blob,"whats")!==false || stripos($blob,"6a7b")!==false) {
      echo $blob."\n";
    }
  }
  echo "--- first 3 ---\n";
  $i=0; foreach ($arr as $it) { if ($i++>=3) break; echo json_encode($it, JSON_UNESCAPED_UNICODE)."\n"; }
} else {
  echo substr((string)$raw,0,400)."\n";
}

echo "\n=== sorted items sample with marketplace/app ===\n";
$conn = \Bitrix\Main\Application::getConnection();
$r = $conn->query("SELECT USER_ID, LEFT(VALUE,2500) V FROM b_user_option WHERE NAME='left_menu_sorted_items_s1' AND VALUE LIKE '%app%' LIMIT 2");
while ($x=$r->fetch()) {
  echo "u=".$x["USER_ID"]."\n";
  if (preg_match_all('/[a-z0-9_]*app[a-z0-9_]*/i', $x["V"], $m)) echo "apps=".implode(",", array_unique($m[0]))."\n";
  echo substr($x["V"],0,800)."\n\n";
}
'''
sftp = c.open_sftp()
with sftp.file("/tmp/wa_mp_menu.php", "w") as f:
    f.write(php)
sftp.close()
_, o, e = c.exec_command("php /tmp/wa_mp_menu.php 2>&1; rm -f /tmp/wa_mp_menu.php", timeout=40)
sys.stdout.buffer.write(o.read()[:9000])

print("\n=== left_vertical template item html ===")
_, o, _ = c.exec_command(
    "ls /home/bitrix/www/bitrix/templates/bitrix24/components/bitrix/menu/left_vertical/; "
    "grep -n 'menu-item\\|counter\\|data-id\\|menu-item-icon' "
    "/home/bitrix/www/bitrix/templates/bitrix24/components/bitrix/menu/left_vertical/template.php "
    "/home/bitrix/www/bitrix/modules/intranet/install/templates/bitrix24/components/bitrix/menu/left_vertical/template.php "
    "2>/dev/null | head -40",
    timeout=15)
sys.stdout.buffer.write(o.read()[:4000])

print("\n=== air menu ===")
_, o, _ = c.exec_command(
    "find /home/bitrix/www/bitrix -iname '*air*menu*' -o -iname '*left-menu*' 2>/dev/null | grep -v lang | head -30; "
    "grep -l 'menu-item-link' /home/bitrix/www/bitrix/templates/bitrix24/components/bitrix/menu/left_vertical/* 2>/dev/null; "
    "ls /home/bitrix/www/bitrix/js/intranet/ 2>/dev/null | head",
    timeout=20)
sys.stdout.buffer.write(o.read()[:4000])
c.close()
