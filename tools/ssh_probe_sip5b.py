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
\Bitrix\Main\Loader::includeModule("voximplant");

echo "=== U139808 line_access rows ===\n";
$r=$conn->query("SELECT * FROM b_voximplant_line_access WHERE ACCESS_CODE IN ('U139808','U1','U844','U74736','G1','DR1')");
while($x=$r->fetch()) echo json_encode($x)."\n";

echo "\n=== all line_access ===\n";
$r=$conn->query("SELECT a.CONFIG_ID, a.ACCESS_CODE, c.PHONE_NAME, c.SEARCH_ID FROM b_voximplant_line_access a LEFT JOIN b_voximplant_config c ON c.ID=a.CONFIG_ID ORDER BY a.CONFIG_ID");
while($x=$r->fetch()) echo json_encode($x, JSON_UNESCAPED_UNICODE)."\n";

echo "\n=== default outgoing option ===\n";
$r=$conn->query("SELECT * FROM b_option WHERE MODULE_ID='voximplant' AND (NAME LIKE '%LINE%' OR NAME LIKE '%OUTGOING%' OR NAME LIKE '%DEFAULT%')");
while($x=$r->fetch()) echo json_encode($x, JSON_UNESCAPED_UNICODE)."\n";

echo "\n=== user options outgoing ===\n";
try {
  $r=$conn->query("SELECT USER_ID, NAME, VALUE FROM b_user_option WHERE NAME LIKE '%vox%' OR NAME LIKE '%phone%' OR MODULE_ID='voximplant' LIMIT 20");
  while($x=$r->fetch()) echo json_encode($x)."\n";
} catch (\Throwable $e) { echo $e->getMessage()."\n"; }

echo "\n=== rest apps telephony ===\n";
$r=$conn->query("SELECT ID, CLIENT_ID, APP_NAME, STATUS FROM b_rest_app WHERE ACTIVE='Y'");
while($x=$r->fetch()) {
  $n=strtolower($x["APP_NAME"]." ".$x["CLIENT_ID"]);
  if (strpos($n,"tel")!==false || strpos($n,"vox")!==false || strpos($n,"sip")!==false || strpos($n,"call")!==false || $x["ID"]=="1")
    echo json_encode($x, JSON_UNESCAPED_UNICODE)."\n";
}

echo "\n=== getAllowedLines as source peek via Reflection ===\n";
$rf = new ReflectionMethod("CVoxImplantUser", "getAllowedLines");
echo $rf->getFileName().":".$rf->getStartLine()."-".$rf->getEndLine()."\n";
'''
sftp = c.open_sftp()
with sftp.file("/tmp/wa_sip5.php","w") as f:
    f.write(php)
sftp.close()
_, o, _ = c.exec_command("php /tmp/wa_sip5.php 2>&1; rm -f /tmp/wa_sip5.php", timeout=40)
sys.stdout.buffer.write(o.read()[:9000])
c.close()
