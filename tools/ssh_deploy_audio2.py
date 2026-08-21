#!/usr/bin/env python3
import os
import paramiko
import sys

HOST = "crm.artflowers.kz"
USER = "bitrix"
PASSWORD = sys.argv[1]
BASE_LOCAL = os.path.normpath(os.path.join(os.path.dirname(__file__), "..", "local", "custom_chat"))
BASE_REMOTE = "/home/bitrix/www/local/custom_chat"
FILES = ["index.php", "mobile.php"]

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

_, o, _ = c.exec_command(
    "grep -n 'waIsApi\\|text/html' /home/bitrix/www/local/custom_chat/mobile.php | head -20"
)
print(o.read().decode("utf-8", "replace"))
_, o, _ = c.exec_command(
    "grep -c 'wa-voice-player\\|header_remove' /home/bitrix/www/local/custom_chat/index.php"
)
print("index markers:", o.read().decode().strip())
c.close()
