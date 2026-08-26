#!/usr/bin/env python3
import os, sys, paramiko
PASSWORD=sys.argv[1]
ROOT=os.path.dirname(__file__)
c=paramiko.SSHClient(); c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
# pull DealStageGuard and grep prepay
_,o,_=c.exec_command("wc -l /home/bitrix/www/bitrix/php_interface/classes/DealStageGuard.php; grep -n -E '1764332847245|1786008089|PREPAYMENT|SetField|prepay|предоплат|UF_CRM_' /home/bitrix/www/bitrix/php_interface/classes/DealStageGuard.php | head -80")
print(o.read().decode("utf-8","replace"))
_,o,_=c.exec_command("sed -n '1,220p' /home/bitrix/www/bitrix/php_interface/classes/DealStageGuard.php")
text=o.read().decode("utf-8","replace")
open(os.path.join(ROOT,"_dsg_head.txt"),"w",encoding="utf-8").write(text)
print(text[:8000])
c.close()
