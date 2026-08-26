#!/usr/bin/env python3
import os
import sys
import paramiko

HOST = "crm.artflowers.kz"
USER = "bitrix"
PASSWORD = sys.argv[1]
BASE_LOCAL = os.path.normpath(os.path.join(os.path.dirname(__file__), "..", "local", "custom_chat"))
BASE_REMOTE = "/home/bitrix/www/local/custom_chat"
FILES = ["index.php", "mobile.php"]

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect(HOST, username=USER, password=PASSWORD, timeout=25)
sftp = c.open_sftp()
for rel in FILES:
    local = os.path.join(BASE_LOCAL, rel.replace("/", os.sep))
    remote = BASE_REMOTE + "/" + rel
    sftp.put(local, remote)
    st = sftp.stat(remote)
    print("ok", rel, st.st_size)
sftp.close()

cmd = (
    "php -l /home/bitrix/www/local/custom_chat/index.php; "
    "php -l /home/bitrix/www/local/custom_chat/mobile.php; "
    "grep -n 'wa_bulk_zip\\|selectedMessageIds\\|downloadSelectedFiles' "
    "/home/bitrix/www/local/custom_chat/index.php "
    "/home/bitrix/www/local/custom_chat/mobile.php | head -25"
)
_, stdout, stderr = c.exec_command(cmd)
print(stdout.read().decode("utf-8", "replace"))
err = stderr.read().decode("utf-8", "replace")
if err.strip():
    print("STDERR", err)
c.close()
