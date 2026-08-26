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
\Bitrix\Main\Loader::includeModule("im");
$conn = \Bitrix\Main\Application::getConnection();

echo "=== msgs around 16:02-16:05 with ttest/воронке ===\n";
$r = $conn->query("SELECT m.ID, m.CHAT_ID, m.AUTHOR_ID, m.DATE_CREATE, LEFT(m.MESSAGE,120) MSG
 FROM b_im_message m
 WHERE m.DATE_CREATE >= '2026-08-24 15:55:00' AND m.DATE_CREATE <= '2026-08-24 16:10:00'
 AND (m.MESSAGE LIKE '%ттестт%' OR m.MESSAGE LIKE '%воронке%' OR m.MESSAGE LIKE '%Тест%')
 ORDER BY m.ID");
while ($x = $r->fetch()) {
  echo $x["ID"]." chat=".$x["CHAT_ID"]." author=".$x["AUTHOR_ID"]." ".$x["DATE_CREATE"]." ".$x["MSG"]."\n";
  $p = $conn->query("SELECT PARAM_NAME, PARAM_VALUE FROM b_im_message_param WHERE MESSAGE_ID=".(int)$x["ID"]." ORDER BY PARAM_NAME");
  while ($y = $p->fetch()) echo "  ".$y["PARAM_NAME"]."=".$y["PARAM_VALUE"]."\n";
}

echo "\n=== CIMMessenger Add fields (grep via reflection) ===\n";
$rf = new ReflectionMethod("CIMMessenger", "Add");
echo $rf->getFileName().":".$rf->getStartLine()."\n";
'''
sftp = c.open_sftp()
with sftp.file("/tmp/wa_reply_params.php", "w") as f:
    f.write(php)
sftp.close()
_, o, e = c.exec_command("php /tmp/wa_reply_params.php 2>&1; rm -f /tmp/wa_reply_params.php", timeout=90)
sys.stdout.buffer.write(o.read()[:12000])
sys.stdout.buffer.write(e.read()[:2000])
c.close()
