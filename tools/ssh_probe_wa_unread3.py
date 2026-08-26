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
\Bitrix\Main\Loader::includeModule("im");
\Bitrix\Main\Loader::includeModule("imopenlines");

echo "=== relation L vs LAST/UNREAD ===\n";
$r=$conn->query("SELECT r.USER_ID, r.CHAT_ID, r.MESSAGE_TYPE, r.COUNTER, r.UNREAD_ID, r.LAST_ID, r.STATUS, LEFT(c.ENTITY_ID,60) EID
  FROM b_im_relation r INNER JOIN b_im_chat c ON c.ID=r.CHAT_ID
  WHERE c.TYPE='L' AND r.UNREAD_ID>0 AND r.UNREAD_ID < r.LAST_ID
  ORDER BY r.LAST_ID DESC LIMIT 8");
$n=0; while($x=$r->fetch()) { $n++; echo json_encode($x, JSON_UNESCAPED_UNICODE)."\n"; }
echo "rows=$n\n";

echo "\n=== session unread-ish ===\n";
$r=$conn->query("SHOW COLUMNS FROM b_imopenlines_session");
$cols=[]; while($x=$r->fetch()) $cols[]=$x["Field"];
echo implode(", ", $cols)."\n";

echo "\n=== Recent class methods ===\n";
if (class_exists("\\Bitrix\\Im\\Recent")) {
  $m = get_class_methods("\\Bitrix\\Im\\Recent");
  echo implode(", ", $m)."\n";
} else echo "no Recent\n";

echo "\n=== CIMContactList methods grep ===\n";
if (class_exists("CIMContactList")) {
  foreach (get_class_methods("CIMContactList") as $m) {
    if (stripos($m,"Recent")!==false || stripos($m,"Counter")!==false) echo "$m\n";
  }
}
'''
sftp = c.open_sftp()
with sftp.file("/tmp/wa_unread3.php", "w") as f:
    f.write(php)
sftp.close()
_, o, e = c.exec_command("php /tmp/wa_unread3.php 2>&1; rm -f /tmp/wa_unread3.php", timeout=60)
sys.stdout.buffer.write(o.read()[:8000])

print("\n=== left-menu.js counters/app ===")
_, o, _ = c.exec_command(
    "grep -n 'updateCounters\\|menu-item-index\\|menu_app_\\|menu-item-block\\|data-counter' "
    "/home/bitrix/www/bitrix/templates/bitrix24/src/js/left-menu.js "
    "/home/bitrix/www/bitrix/modules/intranet/install/templates/bitrix24/src/js/left-menu.js "
    "2>/dev/null | head -50",
    timeout=20)
sys.stdout.buffer.write(o.read()[:5000])

print("\n=== LeftMenu PHP rest app ===")
_, o, _ = c.exec_command(
    "grep -rn 'menu_app_\\|Rest\\\\App\\|marketplace/app' "
    "/home/bitrix/www/bitrix/modules/intranet/lib/UI/LeftMenu "
    "/home/bitrix/www/bitrix/modules/intranet/lib/Internal/Service/LeftMenu "
    "2>/dev/null | head -40",
    timeout=20)
sys.stdout.buffer.write(o.read()[:5000])
c.close()
