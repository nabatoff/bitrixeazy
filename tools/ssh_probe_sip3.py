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

echo "=== line_access all (user vs dept) ===\n";
$r=$conn->query("SELECT ACCESS_CODE, COUNT(*) C FROM b_voximplant_line_access GROUP BY ACCESS_CODE ORDER BY C DESC");
$n=0;
while($x=$r->fetch()) { echo $x["ACCESS_CODE"]." ".$x["C"]."\n"; if(++$n>25) break; }

echo "\n=== configs with access users ===\n";
$r=$conn->query("SELECT c.ID, c.PHONE_NAME, c.SEARCH_ID, c.CAN_BE_SELECTED, GROUP_CONCAT(a.ACCESS_CODE) ACC
  FROM b_voximplant_config c
  LEFT JOIN b_voximplant_line_access a ON a.CONFIG_ID=c.ID
  WHERE c.PORTAL_MODE='SIP'
  GROUP BY c.ID");
while($x=$r->fetch()) echo json_encode($x, JSON_UNESCAPED_UNICODE)."\n";

echo "\n=== role permissions ===\n";
try {
  $r=$conn->query("SELECT * FROM b_voximplant_role_permission");
  while($x=$r->fetch()) echo json_encode($x)."\n";
} catch (\Throwable $e) { echo $e->getMessage()."\n"; }

echo "\n=== CVoxImplantOutgoing methods ===\n";
if (class_exists("CVoxImplantOutgoing")) {
  echo implode("\n", get_class_methods("CVoxImplantOutgoing"))."\n";
}
echo "\n=== Security helper ===\n";
if (class_exists("\\Bitrix\\Voximplant\\Security\\Helper")) {
  echo "Helper yes\n";
  echo implode(", ", get_class_methods("\\Bitrix\\Voximplant\\Security\\Helper"))."\n";
}
echo "\n=== User get default line ===\n";
if (class_exists("CVoxImplantUser")) {
  echo implode("\n", get_class_methods("CVoxImplantUser"))."\n";
}
'''
sftp = c.open_sftp()
with sftp.file("/tmp/wa_sip3.php", "w") as f:
    f.write(php)
sftp.close()
_, o, _ = c.exec_command("php /tmp/wa_sip3.php 2>&1; rm -f /tmp/wa_sip3.php", timeout=40)
sys.stdout.buffer.write(o.read()[:9000])

print("\n=== phoneTo fn ===")
_, o, _ = c.exec_command(
    "sed -n '795,860p' /home/bitrix/www/bitrix/js/im/im.js",
    timeout=15)
sys.stdout.buffer.write(o.read()[:3500])
c.close()
