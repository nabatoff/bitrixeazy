#!/usr/bin/env python3
import sys, paramiko
PASSWORD=sys.argv[1]
c=paramiko.SSHClient(); c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
_,o,_=c.exec_command("sed -n '740,920p' /home/bitrix/www/bitrix/modules/crm/lib/item.php", timeout=20)
print(o.read().decode("utf-8","replace")[:7000])
c.close()
