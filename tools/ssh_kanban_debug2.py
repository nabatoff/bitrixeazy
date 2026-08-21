#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=20)

cmds = [
    "sed -n '1210,1240p' /home/bitrix/www/bitrix/js/main/kanban/css/kanban.css",
    "grep -n 'background' /home/bitrix/www/bitrix/js/main/kanban/css/kanban.css | grep -i 'item\\|wrapper' | head -40",
    "grep -n 'background' /home/bitrix/www/bitrix/js/crm/kanban/css/*.css 2>/dev/null | head -40",
    # how CRM button injects - does OnEpilog Asset work?
    "sed -n '100,180p' /home/bitrix/www/local/custom_chat/include_crm_button.php",
    # check B_PROLOG when init loads
    "grep -n B_PROLOG /home/bitrix/www/bitrix/modules/main/include/prolog_before.php | head -10",
    # actual deal kanban URL structure in bitrix
    "find /home/bitrix/www/crm -name '*kanban*' 2>/dev/null | head -20",
    "ls -la /home/bitrix/www/crm/deal/; file /home/bitrix/www/crm/deal/index.php; head -30 /home/bitrix/www/crm/deal/index.php",
]
for cmd in cmds:
    print("===", cmd[:80])
    _, o, e = c.exec_command(cmd, timeout=40)
    print(o.read().decode("utf-8", "replace")[:3500])
c.close()
