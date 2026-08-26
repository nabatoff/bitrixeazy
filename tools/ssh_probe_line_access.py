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
echo "=== line_access cols ===\n";
try {
    $c = $conn->query("SHOW COLUMNS FROM b_voximplant_line_access");
    while ($x = $c->fetch()) echo $x["Field"]."\n";
} catch (\Throwable $e) { echo $e->getMessage()."\n"; }
echo "\n=== line_access ===\n";
try {
    $r = $conn->query("SELECT * FROM b_voximplant_line_access LIMIT 80");
    while ($x = $r->fetch()) echo json_encode($x, JSON_UNESCAPED_UNICODE)."\n";
} catch (\Throwable $e) { echo $e->getMessage()."\n"; }
echo "\n=== queue users ===\n";
$q = $conn->query("SELECT q.ID QID, q.NAME, u.USER_ID FROM b_voximplant_queue q LEFT JOIN b_voximplant_queue_user u ON u.QUEUE_ID=q.ID ORDER BY q.ID, u.USER_ID");
while ($x = $q->fetch()) echo "q".$x["QID"]." ".$x["NAME"]." user=".$x["USER_ID"]."\n";
echo "\n=== admins ===\n";
$a = $conn->query("SELECT ID, LOGIN, NAME, LAST_NAME FROM b_user WHERE ACTIVE='Y' AND ID IN (SELECT USER_ID FROM b_user_group WHERE GROUP_ID=1) ORDER BY ID LIMIT 20");
while ($x = $a->fetch()) echo $x["ID"]." ".$x["LOGIN"]." ".$x["NAME"]." ".$x["LAST_NAME"]."\n";
echo "\n=== sip users allowed fields in config ===\n";
$cols = $conn->query("SHOW COLUMNS FROM b_voximplant_config");
while ($x = $cols->fetch()) {
    if (stripos($x["Field"], "USER") !== false || stripos($x["Field"], "ACCESS") !== false || stripos($x["Field"], "SELECT") !== false)
        echo $x["Field"]."\n";
}
'''
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", key_filename=KEY, timeout=25)
sftp = c.open_sftp()
with sftp.file("/tmp/vi_acc.php", "w") as f:
    f.write(php)
sftp.close()
_, o, e = c.exec_command("php /tmp/vi_acc.php; rm -f /tmp/vi_acc.php", timeout=40)
sys.stdout.buffer.write(o.read())
sys.stderr.buffer.write(e.read())
c.close()
