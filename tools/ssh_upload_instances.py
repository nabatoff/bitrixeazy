#!/usr/bin/env python3
import os
import paramiko
import sys

HOST = "crm.artflowers.kz"
USER = "bitrix"
PASSWORD = sys.argv[1]
LOCAL = os.path.join(
    os.path.dirname(__file__), "..", "local", "custom_chat", "app", "green_api_instances.local.php"
)
REMOTE = "/home/bitrix/www/local/custom_chat/app/green_api_instances.local.php"

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect(HOST, username=USER, password=PASSWORD, timeout=20)
sftp = c.open_sftp()
sftp.put(os.path.normpath(LOCAL), REMOTE)
sftp.chmod(REMOTE, 0o640)
sftp.close()
_, stdout, stderr = c.exec_command(
    "ls -la /home/bitrix/www/local/custom_chat/app/green_api_instances.local.php && php -r "
    "'$c=include \"/home/bitrix/www/local/custom_chat/app/green_api_instances.local.php\"; "
    "echo count($c[\"lines\"]??[]);'",
    timeout=30,
)
print(stdout.read().decode())
print(stderr.read().decode())
c.close()
print("uploaded")
