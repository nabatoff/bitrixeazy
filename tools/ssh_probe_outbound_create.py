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
echo "=== voximplant options ===\n";
$opt = $conn->query("SELECT NAME, LEFT(VALUE,180) V FROM b_option WHERE MODULE_ID='voximplant' ORDER BY NAME");
while ($x = $opt->fetch()) echo $x["NAME"]."=".$x["V"]."\n";
echo "\n=== user default line options ===\n";
$uo = $conn->query("SELECT USER_ID, NAME, LEFT(VALUE,80) V FROM b_user_option WHERE NAME LIKE '%line%' OR NAME LIKE '%phone%' OR NAME LIKE '%vox%' OR CATEGORY LIKE '%vox%' LIMIT 40");
try {
    while ($x = $uo->fetch()) echo $x["USER_ID"]." ".$x["NAME"]."=".$x["V"]."\n";
} catch (\Throwable $e) { echo $e->getMessage()."\n"; }
echo "\n=== b_user_option categories vox ===\n";
try {
    $c = $conn->query("SELECT CATEGORY, NAME, COUNT(*) CNT FROM b_user_option WHERE CATEGORY LIKE '%vox%' OR CATEGORY LIKE '%im%' GROUP BY CATEGORY, NAME ORDER BY CNT DESC LIMIT 30");
    while ($x = $c->fetch()) echo $x["CATEGORY"]." / ".$x["NAME"]." = ".$x["CNT"]."\n";
} catch (\Throwable $e) { echo $e->getMessage()."\n"; }
echo "\n=== queues ===\n";
$q = $conn->query("SELECT ID, NAME FROM b_voximplant_queue ORDER BY ID");
while ($x = $q->fetch()) echo $x["ID"]." ".$x["NAME"]."\n";
echo "\n=== config queue map ===\n";
$m = $conn->query("SELECT c.ID, c.PHONE_NAME, c.QUEUE_ID, c.CAN_BE_SELECTED, c.BACKUP_LINE FROM b_voximplant_config c ORDER BY c.ID");
while ($x = $m->fetch()) echo $x["ID"]." ".$x["PHONE_NAME"]." queue=".$x["QUEUE_ID"]." sel=".$x["CAN_BE_SELECTED"]." backup=".$x["BACKUP_LINE"]."\n";
'''
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", key_filename=KEY, timeout=25)
sftp = c.open_sftp()
with sftp.file("/tmp/vi_def.php", "w") as f:
    f.write(php)
sftp.close()
_, o, e = c.exec_command("php /tmp/vi_def.php; rm -f /tmp/vi_def.php", timeout=40)
sys.stdout.buffer.write(o.read())
sys.stderr.buffer.write(e.read())
c.close()
