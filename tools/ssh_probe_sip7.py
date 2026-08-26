#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)

cmds = [
    "sed -n '795,860p' /home/bitrix/www/bitrix/js/im/im.js",
    "echo '==== phoneCall fn ===='",
    "sed -n '1470,1620p' /home/bitrix/www/bitrix/modules/voximplant/install/js/voximplant/phone-calls/src/controller.js",
    "echo '==== constructor / BXIM bind ===='",
    "grep -n 'BXIM\\|phoneTo\\|window.BX\\|init(' /home/bitrix/www/bitrix/modules/voximplant/install/js/voximplant/phone-calls/src/controller.js | head -40",
    "echo '==== how phone-calls boots ===='",
    "grep -n 'PhoneCallsController\\|phoneTo' /home/bitrix/www/bitrix/modules/im/install/js/im/src/*.js /home/bitrix/www/bitrix/modules/im/lib/*.php 2>/dev/null | head -20",
    "grep -rn 'new PhoneCallsController\\|BX.IM.prototype.phoneTo\\|phoneCall(' /home/bitrix/www/bitrix/modules/im --include='*.js' --include='*.php' 2>/dev/null | grep -v node_modules | head -25",
    "echo '==== im.v2 phone ===='",
    "grep -rn 'phoneTo' /home/bitrix/www/bitrix/modules/im/install/js/im/v2 --include='*.js' 2>/dev/null | head -20",
    "echo '==== UF_VI_BACKPHONE sample ===='",
]
for cmd in cmds:
    print("\n#####", cmd[:75])
    _, o, _ = c.exec_command(cmd, timeout=25)
    sys.stdout.buffer.write(o.read()[:6500])

php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true); define("NOT_CHECK_PERMISSIONS",true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
$conn = \Bitrix\Main\Application::getConnection();
echo "=== UF_VI_BACKPHONE ===\n";
try {
  $r=$conn->query("SHOW COLUMNS FROM b_uts_user LIKE '%VI%'");
  while($x=$r->fetch()) echo $x["Field"]."\n";
  $r=$conn->query("SELECT VALUE_ID, UF_VI_BACKPHONE FROM b_uts_user WHERE UF_VI_BACKPHONE IS NOT NULL AND UF_VI_BACKPHONE<>'' LIMIT 20");
  while($x=$r->fetch()) echo json_encode($x)."\n";
} catch (\Throwable $e) { echo $e->getMessage()."\n"; }
echo "\n=== GetPortalNumber ===\n";
\Bitrix\Main\Loader::includeModule("voximplant");
echo CVoxImplantConfig::GetPortalNumber()."\n";
echo "\n=== canSelectLine ===\n";
echo (\Bitrix\Voximplant\Limits::canSelectLine()?"Y":"N")."\n";
echo "\n=== configs 1,2,20,22,23 ===\n";
$r=$conn->query("SELECT ID, PORTAL_MODE, SEARCH_ID, PHONE_NAME, CAN_BE_SELECTED FROM b_voximplant_config WHERE ID IN (1,2,20,22,23,24)");
while($x=$r->fetch()) echo json_encode($x, JSON_UNESCAPED_UNICODE)."\n";
'''
sftp = c.open_sftp()
with sftp.file("/tmp/wa_sip6.php","w") as f:
    f.write(php)
sftp.close()
print("\n##### php")
_, o, _ = c.exec_command("php /tmp/wa_sip6.php 2>&1; rm -f /tmp/wa_sip6.php", timeout=40)
sys.stdout.buffer.write(o.read()[:5000])
c.close()
