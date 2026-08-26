#!/usr/bin/env python3
import os, sys, paramiko
PASSWORD = sys.argv[1]
BASE_LOCAL = os.path.normpath(os.path.join(os.path.dirname(__file__), "..", "local", "custom_chat"))
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
sftp = c.open_sftp()
for rel in ["portal_widget.js", "index.php"]:
    local = os.path.join(BASE_LOCAL, rel.replace("/", os.sep))
    remote = "/home/bitrix/www/local/custom_chat/" + rel
    sftp.put(local, remote)
    print("ok", rel, sftp.stat(remote).st_size)
sftp.close()
_, o, _ = c.exec_command(
    "grep -n 'scrollIntoView\\|paintMenu\\|scheduleOpenScrollKeep' "
    "/home/bitrix/www/local/custom_chat/index.php "
    "/home/bitrix/www/local/custom_chat/portal_widget.js | head -20",
    timeout=15)
print(o.read().decode("utf-8", "replace"))
c.close()
