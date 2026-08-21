#!/usr/bin/env python3
import os, sys, paramiko
PASSWORD = sys.argv[1]
ROOT = os.path.normpath(os.path.join(os.path.dirname(__file__), ".."))
FILES = [
    "kanban_deal_paint.js",
    "kanban_deal_paint.css",
    "kanban_deal_paint_ajax.php",
]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=20)
sftp = c.open_sftp()
for name in FILES:
    local = os.path.join(ROOT, "local", "crm", name)
    remote = "/home/bitrix/www/local/crm/" + name
    sftp.put(local, remote)
    print("up", name, sftp.stat(remote).st_size)
sftp.close()
_, o, _ = c.exec_command("grep -n 'yellow\\|1764577192130\\|f5f5a6' /home/bitrix/www/local/crm/kanban_deal_paint.js /home/bitrix/www/local/crm/kanban_deal_paint.css /home/bitrix/www/local/crm/kanban_deal_paint_ajax.php | head -20")
print(o.read().decode("utf-8", "replace"))
c.close()
