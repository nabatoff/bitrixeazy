#!/usr/bin/env python3
import paramiko
import sys

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=sys.argv[1], timeout=20)

cmds = [
    "ls -la /home/bitrix/www/local/custom_chat/",
    "find /home/bitrix/www/local/custom_chat -maxdepth 3 -iname '*ffmpeg*' 2>/dev/null",
    "ls -la /home/bitrix/www/local/custom_chat/wa-ffmpeg 2>/dev/null || echo no-wa-ffmpeg-dir",
    "ls -la /home/bitrix/www/local/custom_chat/bin 2>/dev/null || echo no-bin",
    "stat -c '%y %s %n' /home/bitrix/www/local/custom_chat/index.php /home/bitrix/www/local/custom_chat/mobile.php",
    # any backups from today?
    "ls -lt /home/bitrix/www/local/custom_chat/index.php* 2>/dev/null | head -10",
    # what does media return with fake auth path - check function presence
    "grep -n \"fmt=mp3\\|waCcTranscode\\|Content-Type: text/html\\|waMediaProxyUrl\" /home/bitrix/www/local/custom_chat/index.php | head -20",
    "grep -n \"waIsApi\\|text/html\" /home/bitrix/www/local/custom_chat/mobile.php | head -15",
]
for cmd in cmds:
    print("===", cmd)
    _, o, _ = c.exec_command(cmd, timeout=40)
    print(o.read().decode("utf-8", "replace")[:2500])
c.close()
