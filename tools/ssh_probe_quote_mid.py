#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true); define("NOT_CHECK_PERMISSIONS",true);
require $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("im");
$conn = \Bitrix\Main\Application::getConnection();
echo "=== message_param names ===\n";
$r = $conn->query("SELECT PARAM_NAME, COUNT(*) CNT FROM b_im_message_param GROUP BY PARAM_NAME ORDER BY CNT DESC LIMIT 40");
while ($row = $r->fetch()) echo $row["PARAM_NAME"]." ".$row["CNT"]."\n";
echo "\n=== sample CONNECTOR* ===\n";
$r = $conn->query("SELECT MESSAGE_ID, PARAM_NAME, PARAM_VALUE FROM b_im_message_param WHERE PARAM_NAME LIKE '%CONNECTOR%' OR PARAM_NAME LIKE '%MID%' OR PARAM_NAME LIKE '%REPLY%' OR PARAM_NAME LIKE '%EXTERNAL%' ORDER BY MESSAGE_ID DESC LIMIT 30");
while ($row = $r->fetch()) echo json_encode($row, JSON_UNESCAPED_UNICODE)."\n";
echo "\n=== last OL message PARAMS ===\n";
$r = $conn->query("SELECT m.ID, m.CHAT_ID, m.AUTHOR_ID, LEFT(m.MESSAGE,80) MSG FROM b_im_message m INNER JOIN b_im_chat c ON c.ID=m.CHAT_ID WHERE c.ENTITY_TYPE='LINES' ORDER BY m.ID DESC LIMIT 3");
while ($row = $r->fetch()) {
  echo json_encode($row, JSON_UNESCAPED_UNICODE)."\n";
  $p = $conn->query("SELECT PARAM_NAME, PARAM_VALUE FROM b_im_message_param WHERE MESSAGE_ID=".(int)$row["ID"]);
  while ($x = $p->fetch()) echo "  ".$x["PARAM_NAME"]."=".$x["PARAM_VALUE"]."\n";
}
'''
sftp = c.open_sftp()
with sftp.file("/tmp/wa_probe_mid.php", "w") as f:
    f.write(php)
sftp.close()
_, o, _ = c.exec_command("php /tmp/wa_probe_mid.php 2>&1")
print(o.read().decode("utf-8", "replace")[:12000])
c.exec_command("rm -f /tmp/wa_probe_mid.php")
c.close()
