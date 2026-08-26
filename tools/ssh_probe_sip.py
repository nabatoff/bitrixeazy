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

echo "=== modules telephony/voximplant ===\n";
foreach (["voximplant","voximplant.sip","im","crm"] as $m) {
  $ok = \Bitrix\Main\Loader::includeModule($m);
  echo "$m=".($ok?"1":"0")."\n";
}

echo "\n=== voximplant tables ===\n";
$r=$conn->query("SHOW TABLES LIKE '%vox%'");
while($x=$r->fetch()) echo implode("",$x)."\n";
$r=$conn->query("SHOW TABLES LIKE '%sip%'");
while($x=$r->fetch()) echo implode("",$x)."\n";

echo "\n=== b_voximplant_sip ===\n";
try {
  $r=$conn->query("SHOW COLUMNS FROM b_voximplant_sip");
  while($x=$r->fetch()) echo $x["Field"]." ".$x["Type"]."\n";
  echo "--- rows ---\n";
  $r=$conn->query("SELECT ID, TITLE, SERVER, LOGIN, TYPE FROM b_voximplant_sip LIMIT 15");
  while($x=$r->fetch()) echo json_encode($x, JSON_UNESCAPED_UNICODE)."\n";
} catch (\Throwable $e) { echo $e->getMessage()."\n"; }

echo "\n=== b_voximplant_user_right / config / phone ===\n";
foreach (["b_voximplant_user_right","b_voximplant_config","b_voximplant_phone","b_voximplant_statistic","b_voximplant_number","b_voximplant_line"] as $tb) {
  try {
    $c=$conn->query("SHOW COLUMNS FROM $tb");
    echo "$tb: ";
    $cols=[]; while($x=$c->fetch()) $cols[]=$x["Field"];
    echo implode(",", $cols)."\n";
  } catch (\Throwable $e) { echo "no $tb\n"; }
}

echo "\n=== user sip bindings sample ===\n";
try {
  $r=$conn->query("SELECT * FROM b_voximplant_user_right LIMIT 8");
  while($x=$r->fetch()) {
    foreach ($x as $k=>$v) if (is_string($v)&&strlen($v)>80) $x[$k]=substr($v,0,80)."...";
    echo json_encode($x, JSON_UNESCAPED_UNICODE)."\n";
  }
} catch (\Throwable $e) { echo $e->getMessage()."\n"; }

echo "\n=== config lines ===\n";
try {
  $r=$conn->query("SELECT ID, PORTAL_MODE, SEARCH_ID, PHONE_NAME, CRM, DIRECT_CODE, RECORDING, LINE_TYPE FROM b_voximplant_config LIMIT 20");
  while($x=$r->fetch()) echo json_encode($x, JSON_UNESCAPED_UNICODE)."\n";
} catch (\Throwable $e) { echo $e->getMessage()."\n"; }

echo "\n=== user option sip / inner phone ===\n";
$r=$conn->query("SELECT UF_PHONE_INNER, ID, NAME, LAST_NAME FROM b_user WHERE ACTIVE='Y' AND UF_PHONE_INNER<>'' AND UF_PHONE_INNER IS NOT NULL LIMIT 8");
try {
  while($x=$r->fetch()) echo json_encode($x, JSON_UNESCAPED_UNICODE)."\n";
} catch (\Throwable $e) {
  echo "UF_PHONE_INNER? ".$e->getMessage()."\n";
  $r2=$conn->query("SHOW COLUMNS FROM b_uts_user LIKE '%PHONE%'");
  while($x=$r2->fetch()) echo json_encode($x)."\n";
}

echo "\n=== rest methods vox/telephony ===\n";
if (class_exists("CRestUtil") || true) {
  $files = glob("/home/bitrix/www/bitrix/modules/voximplant/lib/rest/*.php");
  echo "rest files=".count($files)."\n";
  foreach ($files as $f) echo basename($f)."\n";
}
'''
sftp = c.open_sftp()
with sftp.file("/tmp/wa_sip.php", "w") as f:
    f.write(php)
sftp.close()
_, o, e = c.exec_command("php /tmp/wa_sip.php 2>&1; rm -f /tmp/wa_sip.php", timeout=60)
sys.stdout.buffer.write(o.read()[:14000])
sys.stdout.buffer.write(e.read()[:2000])

print("\n=== grep StartCall / voximplant js ===")
_, o, _ = c.exec_command(
    "ls /home/bitrix/www/bitrix/js/voximplant/ 2>/dev/null | head; "
    "grep -rn 'startCall\\|StartCall\\|BX.VoxImplant\\|phoneTo' "
    "/home/bitrix/www/bitrix/js/voximplant/*.js "
    "/home/bitrix/www/bitrix/modules/voximplant/install/js "
    "2>/dev/null | head -25",
    timeout=20)
sys.stdout.buffer.write(o.read()[:4000])
c.close()
