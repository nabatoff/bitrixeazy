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

echo "=== chat types with counter>0 ===\n";
$r=$conn->query("SELECT c.TYPE, c.ENTITY_TYPE, COUNT(*) CNT, SUM(r.COUNTER) SC
  FROM b_im_relation r INNER JOIN b_im_chat c ON c.ID=r.CHAT_ID
  WHERE r.COUNTER>0 GROUP BY c.TYPE, c.ENTITY_TYPE ORDER BY CNT DESC LIMIT 20");
while($x=$r->fetch()) echo json_encode($x, JSON_UNESCAPED_UNICODE)."\n";

echo "\n=== OL entity_id samples ===\n";
$r=$conn->query("SELECT ID, TYPE, ENTITY_TYPE, LEFT(ENTITY_ID,90) EID, LEFT(TITLE,40) T FROM b_im_chat
  WHERE TYPE='L' OR ENTITY_TYPE='LINES' OR ENTITY_ID LIKE '%fos%' OR ENTITY_ID LIKE '%@c.us%'
  ORDER BY ID DESC LIMIT 8");
while($x=$r->fetch()) echo json_encode($x, JSON_UNESCAPED_UNICODE)."\n";

echo "\n=== unread via recent UNREAD=Y ===\n";
try {
  $r=$conn->query("SELECT rec.USER_ID, rec.ITEM_TYPE, rec.ITEM_CID, rec.UNREAD, rec.ITEM_MID, LEFT(c.TITLE,40) T, c.TYPE, LEFT(c.ENTITY_ID,70) EID
    FROM b_im_recent rec
    LEFT JOIN b_im_chat c ON c.ID=rec.ITEM_CID
    WHERE rec.UNREAD='Y' AND rec.ITEM_TYPE IN ('L','O')
    LIMIT 10");
  while($x=$r->fetch()) echo json_encode($x, JSON_UNESCAPED_UNICODE)."\n";
} catch (\Throwable $e) { echo $e->getMessage()."\n"; }

echo "\n=== recent ITEM_TYPE dist unread ===\n";
$r=$conn->query("SELECT ITEM_TYPE, UNREAD, COUNT(*) CNT FROM b_im_recent GROUP BY ITEM_TYPE, UNREAD");
while($x=$r->fetch()) echo json_encode($x)."\n";

echo "\n=== relation COUNTER>0 by MESSAGE_TYPE ===\n";
$r=$conn->query("SELECT MESSAGE_TYPE, COUNT(*) CNT, SUM(COUNTER) S FROM b_im_relation WHERE COUNTER>0 GROUP BY MESSAGE_TYPE");
while($x=$r->fetch()) echo json_encode($x)."\n";

echo "\n=== init crm_button line ===\n";
$init = file_get_contents("/home/bitrix/www/bitrix/php_interface/init.php");
if (preg_match_all('/.{0,80}include_crm_button.{0,80}/', $init, $m)) {
  foreach ($m[0] as $x) echo $x."\n";
}
'''
sftp = c.open_sftp()
with sftp.file("/tmp/wa_unread2.php", "w") as f:
    f.write(php)
sftp.close()
_, o, e = c.exec_command("php /tmp/wa_unread2.php 2>&1; rm -f /tmp/wa_unread2.php", timeout=90)
sys.stdout.buffer.write(o.read()[:14000])
sys.stdout.buffer.write(e.read()[:1000])

print("\n=== find left-menu ===")
_, o, _ = c.exec_command(
    "find /home/bitrix/www/bitrix -iname '*left-menu*' -o -iname '*leftmenu*' 2>/dev/null | head -40; "
    "grep -rl 'updateCounters' /home/bitrix/www/bitrix/js/intranet /home/bitrix/www/bitrix/modules/intranet/install/js 2>/dev/null | head -15; "
    "grep -rl 'menu_app_' /home/bitrix/www/bitrix/modules/intranet /home/bitrix/www/bitrix/modules/rest 2>/dev/null | head -15",
    timeout=40)
sys.stdout.buffer.write(o.read()[:7000])
c.close()
