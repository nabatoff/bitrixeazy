#!/usr/bin/env python3
import os, sys, paramiko
PASSWORD = sys.argv[1]
ROOT = os.path.normpath(os.path.join(os.path.dirname(__file__), ".."))
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=20)
sftp = c.open_sftp()
for rel in ["kanban_deal_paint.js", "kanban_deal_paint.css"]:
    local = os.path.join(ROOT, "local", "crm", rel)
    remote = "/home/bitrix/www/local/crm/" + rel
    sftp.put(local, remote)
    print("ok", rel, sftp.stat(remote).st_size)
sftp.close()
c.close()
