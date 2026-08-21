#!/usr/bin/env python3
import sys, paramiko, os
PASSWORD = sys.argv[1]
ROOT = os.path.dirname(__file__)
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=20)
_, o, _ = c.exec_command("grep -n 'EVENT_NAME\\|EVENT_TEXT\\|Add(' /home/bitrix/www/bitrix/modules/crm/lib/Service/EventHistory.php | head -40; sed -n '80,220p' /home/bitrix/www/bitrix/modules/crm/lib/Service/EventHistory.php")
open(os.path.join(ROOT,"_eh.txt"),"w",encoding="utf-8").write(o.read().decode("utf-8","replace"))
print(open(os.path.join(ROOT,"_eh.txt"),encoding="utf-8").read()[:3500])
c.close()
