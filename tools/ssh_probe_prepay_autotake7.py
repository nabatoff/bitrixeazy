#!/usr/bin/env python3
import os, sys, paramiko
PASSWORD=sys.argv[1]
c=paramiko.SSHClient(); c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
_,o,_=c.exec_command("sed -n '640,760p' /home/bitrix/www/bitrix/modules/crm/lib/Service/Factory/Deal.php", timeout=20)
data=o.read().decode("utf-8","replace")
open(os.path.join(os.path.dirname(__file__),"_factory_deal.txt"),"w",encoding="utf-8").write(data)
print("lines", data.count("\n"))
# also check rest crm.deal.update
_,o,_=c.exec_command("grep -n 'OnBeforeCrmDealUpdate\\|CCrmDeal::Update' /home/bitrix/www/bitrix/modules/crm/lib/controller/item.php /home/bitrix/www/bitrix/modules/crm/lib/controller/deal.php 2>/dev/null | head -20")
print("CTRL", o.read().decode("utf-8","replace")[:1500])
_,o,_=c.exec_command("grep -n 'function crmDealUpdate\\|OnBeforeCrmDealUpdate' /home/bitrix/www/bitrix/modules/crm/classes/general/crm_deal.php | head -20")
print("DEAL", o.read().decode("utf-8","replace")[:1500])
c.close()
print(data[:4000])
