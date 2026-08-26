#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)

cmds = [
    r"grep -n 'REPLY_ID' /home/bitrix/www/bitrix/modules/im/lib/V2/Message.php | head -30",
    r"grep -n 'REPLY_ID' /home/bitrix/www/bitrix/modules/im/lib/V2/Rest/RestAdapter.php /home/bitrix/www/bitrix/modules/im/lib/V2/Controller/Chat/Message.php 2>/dev/null | head -20",
    r"grep -rn 'getParams\|PARAMS' /home/bitrix/www/bitrix/modules/im/lib/V2/Rest/ 2>/dev/null | grep -i 'reply\|whitelist\|allowed' | head -20",
    r"sed -n '1000,1080p' /home/bitrix/www/bitrix/modules/im/lib/V2/Message.php",
]
for cmd in cmds:
    print("===", cmd[:90], "===")
    _, o, e = c.exec_command(cmd, timeout=30)
    sys.stdout.buffer.write(o.read()[:5000])
    print()

php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true); define("NOT_CHECK_PERMISSIONS",true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("im");

$id = 13732761;
$params = \CIMMessageParam::Get($id);
echo "CIMMessageParam::Get($id)=\n";
var_export($params);
echo "\n";

$msg = \Bitrix\Im\Model\MessageTable::getById($id)->fetch();
echo "MESSAGE=".$msg["MESSAGE"]." CHAT=".$msg["CHAT_ID"]."\n";

$orig = 13628549;
$om = \Bitrix\Im\Model\MessageTable::getById($orig)->fetch();
if ($om) {
  echo "orig $orig chat=".$om["CHAT_ID"]." author=".$om["AUTHOR_ID"]." date=".$om["DATE_CREATE"]." msg=".substr(strip_tags($om["MESSAGE"]),0,80)."\n";
} else {
  echo "orig $orig NOT FOUND\n";
}

/* rest-like params via V2 */
if (class_exists("Bitrix\\Im\\V2\\Message")) {
  $m = new \Bitrix\Im\V2\Message($id);
  $arr = $m->toRestFormat();
  echo "toRestFormat params=";
  echo json_encode($arr["params"] ?? $arr["PARAMS"] ?? $arr, JSON_UNESCAPED_UNICODE);
  echo "\n";
}
'''
sftp = c.open_sftp()
with sftp.file("/tmp/wa_reply_rest.php", "w") as f:
    f.write(php)
sftp.close()
_, o, e = c.exec_command("php /tmp/wa_reply_rest.php 2>&1; rm -f /tmp/wa_reply_rest.php", timeout=90)
print("=== rest format ===")
sys.stdout.buffer.write(o.read()[:8000])
sys.stdout.buffer.write(e.read()[:2000])
c.close()
