#!/usr/bin/env python3
import os
import paramiko
import sys

HOST = "crm.artflowers.kz"
USER = "bitrix"
PASSWORD = sys.argv[1]
BASE_LOCAL = os.path.normpath(os.path.join(os.path.dirname(__file__), "..", "local", "custom_chat"))
BASE_REMOTE = "/home/bitrix/www/local/custom_chat"
FILES = ["index.php", "app/wa_ticks.php"]

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
require '/home/bitrix/www/local/custom_chat/app/wa_ticks.php';
echo function_exists('waCcTicksBestForKeysWithPoll') ? "fn=1\n" : "fn=0\n";
$ok = waCcTicksPollLine(49, 60);
echo 'poll49=' . ($ok ? '1' : '0') . "\n";
$path = waCcTicksStorePath();
echo 'file=' . (is_file($path) ? '1' : '0') . "\n";
$all = waCcTicksReadAll();
echo 'entries=' . count($all) . "\n";
$i = 0;
foreach ($all as $k => $v) {
    if ($i++ >= 3) break;
    echo substr($k, 0, 8) . '... ' . ($v['status'] ?? '?') . "\n";
}
"""
sftp = c.open_sftp()
with sftp.file("/tmp/wa_ticks_poll_test.php", "w") as f:
    f.write(php)
sftp.close()
_, stdout, _ = c.exec_command("php /tmp/wa_ticks_poll_test.php 2>&1", timeout=40)
print(stdout.read().decode("utf-8", errors="replace"))
c.exec_command("rm -f /tmp/wa_ticks_poll_test.php")
c.close()
