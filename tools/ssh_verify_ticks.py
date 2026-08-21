#!/usr/bin/env python3
import paramiko
import sys

HOST = "crm.artflowers.kz"
USER = "bitrix"
PASSWORD = sys.argv[1]

checks = [
    "ls -la /home/bitrix/www/local/custom_chat/app/wa_ticks.php 2>&1",
    "ls -la /home/bitrix/www/local/custom_chat/var/ 2>&1",
    "test -f /home/bitrix/www/local/custom_chat/var/wa_ticks.json && wc -c /home/bitrix/www/local/custom_chat/var/wa_ticks.json || echo 'NO wa_ticks.json'",
    "grep -n 'wa_ticks\\|wa-ticks\\|gaApplyOutgoingMessageStatus\\|outgoingMessageStatus' /home/bitrix/www/local/custom_chat/index.php | head -30",
    "grep -n 'gaApplyOutgoingMessageStatus\\|wa_ticks' /home/bitrix/www/local/custom_chat/green_api_group_sender_callback_patch.php 2>&1",
    "find /home/bitrix/www -maxdepth 6 -name 'callback.php' 2>/dev/null | head -8",
    "grep -rl 'gaApplyOutgoingMessageStatus\\|waCcTicksApplyWebhook' /home/bitrix/www 2>/dev/null | head -10",
]

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect(HOST, username=USER, password=PASSWORD, timeout=20)
for cmd in checks:
    print("=== " + cmd[:100] + " ===")
    _, stdout, stderr = c.exec_command(cmd, timeout=45)
    out = stdout.read().decode("utf-8", errors="replace")
    err = stderr.read().decode("utf-8", errors="replace")
    print(out[:4000] if out else err[:500])
    print()
c.close()

PHP = r"""<?php
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
$_SERVER['DOCUMENT_ROOT'] = '/home/bitrix/www';
require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';
require $_SERVER['DOCUMENT_ROOT'].'/local/custom_chat/app/wa_ticks.php';
$all = waCcTicksReadAll();
echo "entries=".count($all)."\n";
$sample = array_slice($all, 0, 5, true);
foreach ($sample as $k => $v) {
    echo $k.' => '.($v['status']??'?').' ts='.($v['ts']??0)."\n";
}
"""

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect(HOST, username=USER, password=PASSWORD, timeout=20)
sftp = c.open_sftp()
with sftp.file("/tmp/wa_ticks_probe.php", "w") as f:
    f.write(PHP)
sftp.close()
print("=== wa_ticks cache sample ===")
_, stdout, _ = c.exec_command("php /tmp/wa_ticks_probe.php 2>&1", timeout=30)
print(stdout.read().decode("utf-8", errors="replace"))
c.exec_command("rm -f /tmp/wa_ticks_probe.php")
c.close()
