#!/usr/bin/env python3
import paramiko
import sys

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=sys.argv[1], timeout=20)

php = """<?php
$_SERVER['DOCUMENT_ROOT'] = '/home/bitrix/www';
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
$leadId = 332986;
$list = olLineLeadsGetChatsForLead($leadId);
echo 'lead=' . $leadId . ' chats=' . count($list) . "\\n";
foreach ($list as $item) {
    echo '  chat=' . ($item['CHAT_ID'] ?? '?') . ' line=' . ($item['LINE_ID'] ?? '?') . ' key=' . ($item['KEY'] ?? '') . "\\n";
}
"""

sftp = c.open_sftp()
with sftp.file("/tmp/ol_probe2.php", "w") as f:
    f.write(php)
sftp.close()

_, o, _ = c.exec_command("php /tmp/ol_probe2.php 2>&1", timeout=60)
print(o.read().decode("utf-8", "replace"))

_, o, _ = c.exec_command(
    "grep -n 'Green API / OL' /home/bitrix/www/local/custom_chat/include_ol_line_leads.php"
)
print("grep step5:", o.read().decode())

_, o, _ = c.exec_command(
    "grep -n openCrmViaNativeEntity /home/bitrix/www/local/custom_chat/index.php | head -3"
)
print("mobile fn:", o.read().decode())

c.exec_command("rm -f /tmp/ol_probe2.php")
c.close()
