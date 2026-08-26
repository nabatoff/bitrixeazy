#!/usr/bin/env python3
import os, sys, paramiko
PASSWORD=sys.argv[1]
c=paramiko.SSHClient(); c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
path="/home/bitrix/www/bitrix/modules/crm/lib/Service/Operation/Action/Compatible/SendEvent/WithCancel/Update.php"
_,o,_=c.exec_command("wc -l "+path+"; cat "+path, timeout=20)
data=o.read().decode("utf-8","replace")
open(os.path.join(os.path.dirname(__file__),"_sendevent_update.txt"),"w",encoding="utf-8").write(data)
print(data[:6000])
# parent class
_,o,_=c.exec_command("ls /home/bitrix/www/bitrix/modules/crm/lib/Service/Operation/Action/Compatible/SendEvent/WithCancel/; grep -n 'arFields\\|setFromCompatible\\|item->set' /home/bitrix/www/bitrix/modules/crm/lib/Service/Operation/Action/Compatible/SendEvent/*.php /home/bitrix/www/bitrix/modules/crm/lib/Service/Operation/Action/Compatible/SendEvent/*/*.php 2>/dev/null | head -40")
print("----")
print(o.read().decode("utf-8","replace")[:4000])
c.close()
