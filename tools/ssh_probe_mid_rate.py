#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)

php = r'''<?php
$_SERVER["DOCUMENT_ROOT"] = "/home/bitrix/www";
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("im");
$conn = \Bitrix\Main\Application::getConnection();

$r = $conn->query("SELECT MAX(ID) M FROM b_im_message");
$row = $r->fetch();
$maxId = (int)($row["M"] ?? 0);
$from = $maxId - 5000;
echo "max_msg_id=$maxId from=$from\n";

$r = $conn->query("SELECT PARAM_NAME, COUNT(*) CNT FROM b_im_message_param WHERE MESSAGE_ID > $from GROUP BY PARAM_NAME ORDER BY CNT DESC LIMIT 25");
echo "=== param names in last 5k ===\n";
while ($x = $r->fetch()) { echo $x["PARAM_NAME"] . " " . $x["CNT"] . "\n"; }

$r = $conn->query("SELECT COUNT(*) CNT FROM b_im_message_param WHERE MESSAGE_ID > $from AND PARAM_NAME = 'CONNECTOR_MID' AND PARAM_VALUE <> ''");
$row = $r->fetch();
echo "\nCONNECTOR_MID filled (last 5k): " . (int)($row["CNT"] ?? 0) . "\n";

$r = $conn->query("SELECT MESSAGE_ID, LEFT(PARAM_VALUE, 60) V FROM b_im_message_param WHERE PARAM_NAME = 'CONNECTOR_MID' AND PARAM_VALUE <> '' ORDER BY MESSAGE_ID DESC LIMIT 8");
echo "=== samples ===\n";
while ($x = $r->fetch()) { echo $x["MESSAGE_ID"] . " = " . $x["V"] . "\n"; }
'''

sftp = c.open_sftp()
with sftp.file("/tmp/wa_mid_rate.php", "w") as f:
    f.write(php)
sftp.close()
_, o, e = c.exec_command("php /tmp/wa_mid_rate.php 2>&1; rm -f /tmp/wa_mid_rate.php", timeout=90)
sys.stdout.buffer.write(o.read()[:8000])
sys.stdout.buffer.write(e.read()[:2000])
c.close()
