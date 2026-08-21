#!/usr/bin/env python3
import paramiko
import sys

HOST = "crm.artflowers.kz"
USER = "bitrix"
PASSWORD = sys.argv[1] if len(sys.argv) > 1 else ""

cmds = [
    "ls -la /home/bitrix/www/ | head -20",
    "ls -la /home/bitrix/ext_www/",
    "ls /home/bitrix/www/local/custom_chat/ 2>/dev/null | head -30",
    "find /home/bitrix/www/bitrix/modules -maxdepth 1 -type d | sort",
    "find /home/bitrix/www -maxdepth 4 -type d -iname '*green*' 2>/dev/null | head -20",
    "find /home/bitrix/www/local -maxdepth 3 -type f -name '*.php' 2>/dev/null | head -40",
]

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect(HOST, username=USER, password=PASSWORD, timeout=20)
for cmd in cmds:
    print("=== " + cmd + " ===")
    _, stdout, stderr = c.exec_command(cmd, timeout=90)
    print(stdout.read().decode("utf-8", errors="replace")[:8000])
    err = stderr.read().decode("utf-8", errors="replace")
    if err.strip():
        print("ERR:", err[:500])
    print()
c.close()
