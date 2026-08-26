#!/usr/bin/env python3
import os, sys, paramiko
PASSWORD = sys.argv[1]
BASE = os.path.normpath(os.path.join(os.path.dirname(__file__), "..", "local", "custom_chat"))
FILES = [
    "portal_widget.js",
    "portal_widget.css",
    "include_portal_widget.php",
    "portal_unread.php",
]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
sftp = c.open_sftp()
for rel in FILES:
    local = os.path.join(BASE, rel.replace("/", os.sep))
    remote = "/home/bitrix/www/local/custom_chat/" + rel
    sftp.put(local, remote)
    print("ok", rel, sftp.stat(remote).st_size)
sftp.close()
_, o, e = c.exec_command(
    "php -l /home/bitrix/www/local/custom_chat/include_portal_widget.php; "
    "php -l /home/bitrix/www/local/custom_chat/portal_unread.php; "
    "grep -n 'font-size: 0\\|setInterval(fetchUnreadCount, 8000)\\|skipGrowToast\\|onPullEvent' "
    "/home/bitrix/www/local/custom_chat/portal_widget.js /home/bitrix/www/local/custom_chat/portal_widget.css | head -25",
    timeout=30)
print(o.read().decode("utf-8", "replace"))
err = e.read().decode("utf-8", "replace")
if err.strip():
    print("STDERR", err[:1000])
c.close()
