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

echo "=== roles ===\n";
$r=$conn->query("SELECT * FROM b_voximplant_role");
while($x=$r->fetch()) echo json_encode($x, JSON_UNESCAPED_UNICODE)."\n";
echo "=== role_access ===\n";
$r=$conn->query("SELECT * FROM b_voximplant_role_access");
while($x=$r->fetch()) echo json_encode($x, JSON_UNESCAPED_UNICODE)."\n";

echo "\n=== sample users with lines ===\n";
$ids = [55,69,70,72,99447,265631,269428,270479,139808,1];
$vi = new CVoxImplantUser();
foreach ($ids as $uid) {
  $u = \CUser::GetByID($uid)->Fetch();
  $name = $u ? trim($u["NAME"]." ".$u["LAST_NAME"])." active=".$u["ACTIVE"] : "NOUSER";
  $admin = false;
  try { $admin = \CUser::GetByID($uid)->Fetch(); } catch(\Throwable $e){}
  $isAdmin = ($u && ($u["ID"]==1 || (int)$u["UF_DEPARTMENT"]));
  $groups = \CUser::GetUserGroup($uid);
  $outLine = method_exists($vi,"getUserOutgoingLine") ? $vi->getUserOutgoingLine($uid) : "?";
  $allowed = method_exists($vi,"getAllowedLines") ? $vi->getAllowedLines($uid) : "?";
  echo "U$uid $name groups=".json_encode($groups)." out=".json_encode($outLine,JSON_UNESCAPED_UNICODE)." allowed=".json_encode($allowed,JSON_UNESCAPED_UNICODE)."\n";
}

echo "\n=== Helper as user 55 / 1 / 139808 ===\n";
foreach ([1,55,139808,99447] as $uid) {
  $GLOBALS["USER"] = new CUser;
  // can't really login; just print
  echo "skip login $uid\n";
}

echo "\n=== CVoxImplantOutgoing::GetConfigByUserId 55 ===\n";
try {
  $cfg = CVoxImplantOutgoing::GetConfigByUserId(55);
  echo json_encode($cfg, JSON_UNESCAPED_UNICODE|JSON_PARTIAL_OUTPUT_ON_ERROR)."\n";
} catch (\Throwable $e) { echo $e->getMessage()."\n"; }

echo "\n=== CVoxImplantOutgoing::GetConfigByUserId 1 ===\n";
try {
  $cfg = CVoxImplantOutgoing::GetConfigByUserId(1);
  $keys = is_array($cfg)?array_keys($cfg):[];
  echo "keys=".implode(",",$keys)."\n";
  if (is_array($cfg)) {
    foreach (["ID","PHONE_NAME","SEARCH_ID","PORTAL_MODE","LINE_TYPE"] as $k)
      if (isset($cfg[$k])) echo "$k=".$cfg[$k]."\n";
  }
} catch (\Throwable $e) { echo $e->getMessage()."\n"; }

echo "\n=== users without personal line_access but in G1 (admins) ===\n";
$r=$conn->query("SELECT ug.USER_ID, u.NAME, u.LAST_NAME, u.ACTIVE FROM b_user_group ug JOIN b_user u ON u.ID=ug.USER_ID WHERE ug.GROUP_ID=1 AND u.ACTIVE='Y' LIMIT 20");
while($x=$r->fetch()) {
  $uid=(int)$x["USER_ID"];
  $has=$conn->query("SELECT COUNT(*) C FROM b_voximplant_line_access WHERE ACCESS_CODE='U".$uid."'")->fetch();
  echo json_encode($x,JSON_UNESCAPED_UNICODE)." lines=".$has["C"]."\n";
}

echo "\n=== sip REG status ===\n";
$r=$conn->query("SELECT s.CONFIG_ID, s.LOGIN, s.REGISTRATION_STATUS_CODE, c.PHONE_NAME FROM b_voximplant_sip s JOIN b_voximplant_config c ON c.ID=s.CONFIG_ID");
while($x=$r->fetch()) echo json_encode($x, JSON_UNESCAPED_UNICODE)."\n";
'''
sftp = c.open_sftp()
with sftp.file("/tmp/wa_sip4.php", "w") as f:
    f.write(php)
sftp.close()
_, o, _ = c.exec_command("php /tmp/wa_sip4.php 2>&1; rm -f /tmp/wa_sip4.php", timeout=50)
sys.stdout.buffer.write(o.read()[:14000])

print("\n=== JS phoneTo real ===")
cmds = [
    "grep -n 'phoneTo' /home/bitrix/www/bitrix/js/im/im.js | head -20",
    "grep -rn 'prototype.phoneTo' /home/bitrix/www/bitrix/js/im /home/bitrix/www/bitrix/modules/im /home/bitrix/www/bitrix/js/voximplant /home/bitrix/www/bitrix/modules/voximplant 2>/dev/null | head -25",
    "grep -rn 'startCall(' /home/bitrix/www/bitrix/modules/voximplant/install/js 2>/dev/null | head -20",
    "ls /home/bitrix/www/bitrix/js/voximplant/ 2>/dev/null; ls /home/bitrix/www/bitrix/modules/voximplant/install/js 2>/dev/null",
]
for cmd in cmds:
    print("\n---", cmd[:80], "---")
    _, o, _ = c.exec_command(cmd, timeout=20)
    sys.stdout.buffer.write(o.read()[:4000])
c.close()
