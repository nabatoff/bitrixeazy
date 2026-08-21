#!/usr/bin/env python3
import paramiko
import sys

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=sys.argv[1], timeout=20)

php = r"""<?php
$_SERVER['DOCUMENT_ROOT'] = '/home/bitrix/www';
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
\Bitrix\Main\Loader::includeModule('im');
global $DB, $USER;

function out($s){ echo $s."\n"; }

out('=== recent oga in b_file ===');
$rs = $DB->Query("SELECT ID, FILE_NAME, CONTENT_TYPE, FILE_SIZE, SUBDIR FROM b_file WHERE FILE_NAME LIKE '%.oga' OR CONTENT_TYPE LIKE 'audio%' ORDER BY ID DESC LIMIT 5");
$files = [];
while ($r = $rs->Fetch()) { $files[] = $r; out(json_encode($r, JSON_UNESCAPED_UNICODE)); }

out('=== im file rows for recent ===');
foreach ($files as $f) {
  $id = (int)$f['ID'];
  $row = $DB->Query("SELECT ID, CHAT_ID, MESSAGE_ID, DISK_FILE_ID, FILE_ID FROM b_im_file WHERE ID=$id OR FILE_ID=$id OR DISK_FILE_ID=$id ORDER BY ID DESC LIMIT 3");
  while ($x = $row->Fetch()) out('im_file '.json_encode($x, JSON_UNESCAPED_UNICODE));
}

// pick chat 328616 from screenshot context / lead chats with voice
out('=== messages with FILE near chat 328616 ===');
$rs = $DB->Query("SELECT m.ID, m.CHAT_ID, m.MESSAGE, m.DATE_CREATE FROM b_im_message m WHERE m.CHAT_ID=328616 AND (m.MESSAGE LIKE '%FILE%' OR m.MESSAGE LIKE '%DISK%') ORDER BY m.ID DESC LIMIT 8");
while ($r = $rs->Fetch()) out(json_encode($r, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

out('DONE');
"""

sftp = c.open_sftp()
with sftp.file("/tmp/probe_audio_src.php", "w") as f:
    f.write(php)
sftp.close()
_, o, _ = c.exec_command("php /tmp/probe_audio_src.php 2>&1", timeout=60)
print(o.read().decode("utf-8", "replace"))

# Check mobile.php early path + simulate curl to media endpoint without auth
cmds = [
    "head -n 30 /home/bitrix/www/local/custom_chat/mobile.php",
    # find a recent audio file id and abs path
    "php -r '$d=\"/home/bitrix/www\"; $_SERVER[\"DOCUMENT_ROOT\"]=$d; define(\"NO_KEEP_STATISTIC\",1); define(\"NOT_CHECK_PERMISSIONS\",1); require \"$d/bitrix/modules/main/include/prolog_before.php\"; global $DB; $r=$DB->Query(\"SELECT ID, SUBDIR, FILE_NAME, CONTENT_TYPE FROM b_file WHERE FILE_NAME LIKE \\\"%.oga\\\" ORDER BY ID DESC LIMIT 1\")->Fetch(); var_export($r); $p=$d.\"/upload/\".$r[\"SUBDIR\"].\"/\".$r[\"FILE_NAME\"]; echo \"\\nexists=\".(is_file($p)?\"Y\":\"N\").\" path=$p\\n\";'",
]
for cmd in cmds:
    print("===", cmd[:80])
    _, o, _ = c.exec_command(cmd, timeout=40)
    print(o.read().decode("utf-8", "replace")[:3000])

c.exec_command("rm -f /tmp/probe_audio_src.php")
c.close()
