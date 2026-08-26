#!/usr/bin/env python3
import sys, paramiko
PASSWORD=sys.argv[1]
c=paramiko.SSHClient(); c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
_,o,_=c.exec_command("grep -n 'function setFromCompatibleData\\|function getCompatibleData' /home/bitrix/www/bitrix/modules/crm/lib/Item.php")
print(o.read().decode()[:800])
_,o,_=c.exec_command("grep -n 'function setFromCompatibleData' -n /home/bitrix/www/bitrix/modules/crm/lib/item.php /home/bitrix/www/bitrix/modules/crm/lib/Item.php 2>/dev/null")
print("p2", o.read().decode()[:500])
_,o,_=c.exec_command("grep -rn 'function setFromCompatibleData' /home/bitrix/www/bitrix/modules/crm/lib --include='*.php' | head")
print("p3", o.read().decode()[:800])
c.close()
