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

echo "=== sip rows ===\n";
$r=$conn->query("SELECT ID, CONFIG_ID, TYPE, SERVER, LOGIN, INCOMING_LOGIN, REG_ID, REGISTRATION_STATUS_CODE FROM b_voximplant_sip LIMIT 30");
while($x=$r->fetch()) echo json_encode($x, JSON_UNESCAPED_UNICODE)."\n";

echo "\n=== config ===\n";
$r=$conn->query("SELECT ID, PORTAL_MODE, SEARCH_ID, PHONE_NAME, CRM, DIRECT_CODE, CAN_BE_SELECTED, LINE_PREFIX FROM b_voximplant_config ORDER BY ID LIMIT 40");
while($x=$r->fetch()) echo json_encode($x, JSON_UNESCAPED_UNICODE)."\n";

echo "\n=== numbers ===\n";
$r=$conn->query("SELECT ID, NUMBER, NAME, CONFIG_ID FROM b_voximplant_number LIMIT 30");
while($x=$r->fetch()) echo json_encode($x, JSON_UNESCAPED_UNICODE)."\n";

echo "\n=== phones per user (b_voximplant_phone) ===\n";
$r=$conn->query("SELECT PHONE_MNEMONIC, COUNT(*) C FROM b_voximplant_phone GROUP BY PHONE_MNEMONIC");
while($x=$r->fetch()) echo json_encode($x)."\n";
$r=$conn->query("SELECT USER_ID, PHONE_NUMBER, PHONE_MNEMONIC FROM b_voximplant_phone ORDER BY USER_ID LIMIT 15");
while($x=$r->fetch()) echo json_encode($x, JSON_UNESCAPED_UNICODE)."\n";

echo "\n=== line_access ===\n";
$r=$conn->query("SHOW COLUMNS FROM b_voximplant_line_access");
while($x=$r->fetch()) echo $x["Field"]." ";
echo "\n";
$r=$conn->query("SELECT * FROM b_voximplant_line_access LIMIT 20");
while($x=$r->fetch()) echo json_encode($x, JSON_UNESCAPED_UNICODE)."\n";

echo "\n=== roles ===\n";
$r=$conn->query("SELECT * FROM b_voximplant_role");
while($x=$r->fetch()) echo json_encode($x, JSON_UNESCAPED_UNICODE)."\n";
$r=$conn->query("SELECT * FROM b_voximplant_role_access LIMIT 15");
while($x=$r->fetch()) echo json_encode($x, JSON_UNESCAPED_UNICODE)."\n";
$r=$conn->query("SELECT ROLE_ID, PERMISSION_ID, PERMISSION_VALUE FROM b_voximplant_role_permission LIMIT 40");
while($x=$r->fetch()) echo json_encode($x)."\n";

echo "\n=== UF inner phone ===\n";
try {
  $r=$conn->query("SHOW COLUMNS FROM b_uts_user");
  while($x=$r->fetch()) {
    if (stripos($x["Field"],"PHONE")!==false || stripos($x["Field"],"SIP")!==false || stripos($x["Field"],"INNER")!==false)
      echo $x["Field"]."\n";
  }
} catch (\Throwable $e) { echo $e->getMessage()."\n"; }

echo "\n=== VoxImplant PHP classes call ===\n";
foreach (["\\CVoxImplantOutgoing","\\CVoxImplantUser","\\CVoxImplantPhone","\\CVoxImplantConfig","\\Bitrix\\Voximplant\\Routing\\Router"] as $cl) {
  echo $cl." ".(class_exists($cl,true)?"yes":"no")."\n";
}
if (class_exists("CVoxImplantUser")) {
  $m=get_class_methods("CVoxImplantUser");
  foreach ($m as $x) if (stripos($x,"phone")!==false || stripos($x,"sip")!==false || stripos($x,"call")!==false || stripos($x,"line")!==false) echo " user::$x\n";
}
if (class_exists("CVoxImplantOutgoing")) {
  $m=get_class_methods("CVoxImplantOutgoing");
  echo "outgoing: ".implode(",", $m)."\n";
}
'''
sftp = c.open_sftp()
with sftp.file("/tmp/wa_sip2.php", "w") as f:
    f.write(php)
sftp.close()
_, o, e = c.exec_command("php /tmp/wa_sip2.php 2>&1; rm -f /tmp/wa_sip2.php", timeout=60)
sys.stdout.buffer.write(o.read()[:14000])

print("\n=== startCall JS ===")
_, o, _ = c.exec_command(
    "sed -n '780,930p' /home/bitrix/www/bitrix/modules/voximplant/install/js/voximplant/phone-calls/src/controller.js",
    timeout=15)
sys.stdout.buffer.write(o.read()[:5000])
print("\n=== BXIM.phoneTo grep ===")
_, o, _ = c.exec_command(
    "grep -n 'phoneTo\\|phoneCall\\|startCall(' /home/bitrix/www/bitrix/modules/im/install/js/im/im.js 2>/dev/null | head -15; "
    "grep -rn 'BX.MessengerCommon.phoneTo\\|BXIM.phoneTo\\|phoneTo:' /home/bitrix/www/bitrix/js/im /home/bitrix/www/bitrix/modules/im/install/js 2>/dev/null | head -15",
    timeout=20)
sys.stdout.buffer.write(o.read()[:2500])
c.close()
