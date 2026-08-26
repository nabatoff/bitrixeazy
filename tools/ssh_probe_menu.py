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
\Bitrix\Main\Loader::includeModule("rest");
$conn = \Bitrix\Main\Application::getConnection();

echo "=== rest apps custom_chat ===\n";
$r = $conn->query("SELECT ID, CLIENT_ID, CODE, APP_NAME, URL, URL_INSTALL, STATUS, ACTIVE, SCOPE FROM b_rest_app WHERE URL LIKE '%custom_chat%' OR CODE LIKE '%whats%' OR APP_NAME LIKE '%Whats%' OR APP_NAME LIKE '%ватсап%' OR URL LIKE '%wa%' LIMIT 20");
while ($x = $r->fetch()) echo json_encode($x, JSON_UNESCAPED_UNICODE)."\n";

echo "\n=== rest app lang/icon if table ===\n";
$tables = [];
$t = $conn->query("SHOW TABLES LIKE 'b_rest%'");
while ($x = $t->fetch()) { $tables[] = implode("", $x); }
echo implode(", ", $tables)."\n";

if (in_array("b_rest_app_lang", $tables) || in_array("b_rest_lang", $tables)) {
  echo "lang table exists\n";
}

echo "\n=== placements ===\n";
if (class_exists("\\Bitrix\\Rest\\PlacementTable")) {
  $res = \Bitrix\Rest\PlacementTable::getList(["filter"=>[], "select"=>["ID","APP_ID","PLACEMENT","TITLE","ADDITIONAL","OPTIONS"], "limit"=>80]);
  while ($row = $res->fetch()) {
    $blob = strtolower(json_encode($row, JSON_UNESCAPED_UNICODE));
    if (strpos($blob, "whats") !== false || strpos($blob, "custom_chat") !== false || strpos($blob, "wa") !== false) {
      echo json_encode($row, JSON_UNESCAPED_UNICODE)."\n";
    }
  }
}

echo "\n=== left menu custom items (b_user_option / intranet) ===\n";
foreach (["b_intranet_custom_section","b_intranet_left_menu","b_app_placement"] as $tb) {
  try { $conn->query("SELECT 1 FROM $tb LIMIT 1"); echo "table $tb exists\n"; } catch (\Throwable $e) { echo "no $tb\n"; }
}

$r = $conn->query("SELECT NAME, COUNT(*) CNT FROM b_user_option WHERE NAME LIKE '%left_menu%' OR NAME LIKE '%menu%' GROUP BY NAME ORDER BY CNT DESC LIMIT 20");
while ($x = $r->fetch()) echo $x["NAME"]." ".$x["CNT"]."\n";

echo "\n=== sample left_menu option ===\n";
$r = $conn->query("SELECT USER_ID, NAME, LEFT(VALUE,400) V FROM b_user_option WHERE NAME IN ('left_menu_self_items','left_menu_preset','left_menu_sorted_items') AND VALUE LIKE '%hatsapp%' OR (NAME='left_menu_self_items' AND VALUE LIKE '%custom_chat%') LIMIT 8");
while ($x = $r->fetch()) echo "u=".$x["USER_ID"]." ".$x["NAME"]." ".$x["V"]."\n";

echo "\n=== grep self items whatsapp ===\n";
$r = $conn->query("SELECT USER_ID, LEFT(VALUE,800) V FROM b_user_option WHERE NAME='left_menu_self_items' AND (VALUE LIKE '%Whats%' OR VALUE LIKE '%ватсап%' OR VALUE LIKE '%custom_chat%' OR VALUE LIKE '%WhatsApp%') LIMIT 5");
while ($x = $r->fetch()) echo "u=".$x["USER_ID"]." ".$x["V"]."\n";
'''
sftp = c.open_sftp()
with sftp.file("/tmp/wa_menu_probe.php", "w") as f:
    f.write(php)
sftp.close()
_, o, e = c.exec_command("php /tmp/wa_menu_probe.php 2>&1; rm -f /tmp/wa_menu_probe.php", timeout=90)
sys.stdout.buffer.write(o.read()[:14000])
sys.stdout.buffer.write(e.read()[:2000])
c.close()
