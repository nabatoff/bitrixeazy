#!/usr/bin/env python3
import sys, paramiko
PASSWORD=sys.argv[1]
c=paramiko.SSHClient(); c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
_,o,_=c.exec_command("grep -n 'Automation\\|Bizproc\\|Robot' /home/bitrix/www/bitrix/modules/crm/lib/Service/Operation.php /home/bitrix/www/bitrix/modules/crm/lib/Service/Operation/Update.php | head -40")
print(o.read().decode()[:3000])
_,o,_=c.exec_command("grep -n 'addAction\\|Automation' /home/bitrix/www/bitrix/modules/crm/lib/Service/Factory.php | head -40")
print('FAC', o.read().decode()[:2500])
_,o,_=c.exec_command("grep -rn 'new Operation\\\\Action\\\\Automation\\|RunAutomation\\|BizProc' /home/bitrix/www/bitrix/modules/crm/lib/Service --include='*.php' | head -30")
print('ACT', o.read().decode()[:2500])
c.close()
