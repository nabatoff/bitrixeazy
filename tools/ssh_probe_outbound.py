#!/usr/bin/env python3
import sys
import paramiko

KEY = sys.argv[1] if len(sys.argv) > 1 else r"C:\Users\15bit\.ssh\id_rsa_bitrix"
php = r'''<?php
$_SERVER["DOCUMENT_ROOT"] = "/home/bitrix/www";
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
$conn = \Bitrix\Main\Application::getConnection();
echo "=== portal_number ===\n";
$opt = $conn->query("SELECT NAME, VALUE FROM b_option WHERE MODULE_ID='voximplant' AND NAME IN ('portal_number','default_line','interface_chat_enabled')");
while ($x = $opt->fetch()) echo $x["NAME"]."=".$x["VALUE"]."\n";
echo "\n=== lines ===\n";
$r = $conn->query("SELECT c.ID, c.SEARCH_ID, c.PHONE_NAME, c.CAN_BE_SELECTED, c.LINE_PREFIX, s.TYPE, s.LOGIN, s.SERVER
FROM b_voximplant_config c
LEFT JOIN b_voximplant_sip s ON s.CONFIG_ID=c.ID
ORDER BY c.ID");
while ($x = $r->fetch()) {
    echo $x["ID"]." ".$x["SEARCH_ID"]." [".$x["PHONE_NAME"]."] sel=".$x["CAN_BE_SELECTED"]." prefix=".$x["LINE_PREFIX"]." type=".($x["TYPE"]??"")." login=".($x["LOGIN"]??"")."\n";
}
echo "\n=== line_access sample ===\n";
$a = $conn->query("SELECT LINE_ID, COUNT(*) CNT FROM b_voximplant_line_access GROUP BY LINE_ID");
while ($x = $a->fetch()) echo "line ".$x["LINE_ID"]." users=".$x["CNT"]."\n";
'''
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", key_filename=KEY, timeout=25)
sftp = c.open_sftp()
with sftp.file("/tmp/vi_lines.php", "w") as f:
    f.write(php)
sftp.close()
_, o, e = c.exec_command("php /tmp/vi_lines.php; rm -f /tmp/vi_lines.php", timeout=40)
sys.stdout.buffer.write(o.read())
sys.stderr.buffer.write(e.read())
c.close()
