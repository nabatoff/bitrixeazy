#!/usr/bin/env python3
import os, sys, paramiko
PASSWORD = sys.argv[1]
ROOT = os.path.normpath(os.path.join(os.path.dirname(__file__), ".."))
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=20)
sftp = c.open_sftp()
for rel in ["app/wa_ticks.php", "index.php"]:
    local = os.path.join(ROOT, "local", "custom_chat", *rel.split("/"))
    remote = "/home/bitrix/www/local/custom_chat/" + rel
    sftp.put(local, remote)
    print("ok", rel, sftp.stat(remote).st_size)
sftp.close()
_, o, _ = c.exec_command("php -l /home/bitrix/www/local/custom_chat/app/wa_ticks.php; grep -n 'force\\|2500\\|startTicksBurst\\|minInterval' /home/bitrix/www/local/custom_chat/app/wa_ticks.php /home/bitrix/www/local/custom_chat/index.php | head -30")
print(o.read().decode("utf-8", "replace"))
c.close()
