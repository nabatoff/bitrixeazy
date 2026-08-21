#!/usr/bin/env python3
import os
import paramiko
import sys

HOST = "crm.artflowers.kz"
USER = "bitrix"
PASSWORD = sys.argv[1]
BASE_LOCAL = os.path.normpath(os.path.join(os.path.dirname(__file__), "..", "local", "custom_chat"))
BASE_REMOTE = "/home/bitrix/www/local/custom_chat"
FILES = ["index.php", "include_ol_line_leads.php"]

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect(HOST, username=USER, password=PASSWORD, timeout=20)
sftp = c.open_sftp()
for rel in FILES:
    local = os.path.join(BASE_LOCAL, rel.replace("/", os.sep))
    remote = BASE_REMOTE + "/" + rel
    sftp.put(local, remote)
    print("ok", rel)
sftp.close()

php = r"""<?php
$_SERVER['DOCUMENT_ROOT'] = '/home/bitrix/www';
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require $_SERVER['DOCUMENT_ROOT'] . '/local/custom_chat/include_ol_line_leads.php';

$leadId = (int)($_GET['lead'] ?? 332986);
$list = olLineLeadsGetChatsForLead($leadId);
echo 'lead=' . $leadId . ' chats=' . count($list) . "\n";
foreach ($list as $item) {
    echo '  chat=' . ($item['CHAT_ID'] ?? '?')
        . ' line=' . ($item['LINE_ID'] ?? '?')
        . ' key=' . ($item['KEY'] ?? '') . "\n";
}
"""
sftp = c.open_sftp()
with sftp.file("/tmp/ol_leads_probe.php", "w") as f:
    f.write(php)
sftp.close()
_, stdout, stderr = c.exec_command("php /tmp/ol_leads_probe.php 2>&1", timeout=60)
print(stdout.read().decode("utf-8", errors="replace"))
print(stderr.read().decode("utf-8", errors="replace"))
c.exec_command("rm -f /tmp/ol_leads_probe.php")
c.close()
