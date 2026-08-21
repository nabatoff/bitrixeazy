#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=20)
_, o, _ = c.exec_command("tail -n 25 /home/bitrix/www/bitrix/php_interface/init.php")
print(o.read().decode("utf-8", "replace"))
c.close()
