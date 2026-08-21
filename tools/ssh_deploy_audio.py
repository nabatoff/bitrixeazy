#!/usr/bin/env python3
import os
import paramiko
import sys

HOST = "crm.artflowers.kz"
USER = "bitrix"
PASSWORD = sys.argv[1]
LOCAL = os.path.normpath(os.path.join(os.path.dirname(__file__), "..", "local", "custom_chat", "index.php"))
REMOTE = "/home/bitrix/www/local/custom_chat/index.php"

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect(HOST, username=USER, password=PASSWORD, timeout=20)
sftp = c.open_sftp()
sftp.put(LOCAL, REMOTE)
sftp.close()
print("ok index.php")

_, o, _ = c.exec_command(
    "grep -c 'wa-voice-player\\|bindMobileVoicePlayer\\|wa-voice-play' /home/bitrix/www/local/custom_chat/index.php"
)
print("markers:", o.read().decode().strip())
c.close()
