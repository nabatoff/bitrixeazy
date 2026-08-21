#!/usr/bin/env python3
import os, sys, paramiko
PASSWORD = sys.argv[1]
ROOT = os.path.normpath(os.path.join(os.path.dirname(__file__), ".."))
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=20)
sftp = c.open_sftp()
local = os.path.join(ROOT, "local", "crm", "include_deal_uf_lock.php")
remote = "/home/bitrix/www/local/crm/include_deal_uf_lock.php"
sftp.put(local, remote)
print("up", remote, sftp.stat(remote).st_size)
sftp.close()
_, o, _ = c.exec_command("grep -A6 'waDealUfLock_fields' /home/bitrix/www/local/crm/include_deal_uf_lock.php | head -15")
print(o.read().decode("utf-8", "replace"))
c.close()
