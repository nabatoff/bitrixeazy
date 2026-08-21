#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=20)
_, o, _ = c.exec_command("sed -n '1,100p' /home/bitrix/www/bitrix/js/crm/kanban/css/kanban.css")
print(o.read().decode()[:4000])
_, o, _ = c.exec_command("grep -n 'crm-kanban-item-color\\|main-kanban-item-wrapper\\|crm-kanban-item ' /home/bitrix/www/bitrix/js/crm/kanban/css/kanban.css | head -40")
print("---\n", o.read().decode()[:2000])
# also check SEF urls for kanban
_, o, _ = c.exec_command("grep -n 'kanban\\|SEF_URL' /home/bitrix/www/crm/deal/index.php | head -40")
print("---DEAL INDEX---\n", o.read().decode()[:2000])
c.close()
