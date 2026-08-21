#!/usr/bin/env python3
import paramiko, sys
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=sys.argv[1], timeout=20)
cmds = [
    "grep -n \"data-id\\|setAttribute\\|getContainer\\|main-kanban-item\\|crm-kanban-item\" /home/bitrix/www/bitrix/js/crm/kanban/item.js | head -40",
    "grep -n \"data-id\\|setAttribute\\|getId\" /home/bitrix/www/bitrix/js/kanban/item.js 2>/dev/null | head -30",
    "ls /home/bitrix/www/bitrix/js/kanban 2>/dev/null | head -20",
    "head -n 5 /home/bitrix/www/bitrix/php_interface/init.php; wc -l /home/bitrix/www/bitrix/php_interface/init.php",
]
for cmd in cmds:
    print("===", cmd)
    _, o, _ = c.exec_command(cmd, timeout=40)
    print(o.read().decode("utf-8", "replace")[:3500])
c.close()
