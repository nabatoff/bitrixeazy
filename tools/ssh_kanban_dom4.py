#!/usr/bin/env python3
import paramiko, sys
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=sys.argv[1], timeout=20)
_, o, _ = c.exec_command("grep -n 'data-id\\|setAttribute\\|createContainer\\|main-kanban-item' /home/bitrix/www/bitrix/js/main/kanban/item.js | head -50")
print(o.read().decode("utf-8", "replace")[:4000])
_, o, _ = c.exec_command("grep -n 'data-id\\|setAttribute\\|createContainer\\|main-kanban-column' /home/bitrix/www/bitrix/js/main/kanban/column.js | head -40")
print("---COL---\n", o.read().decode("utf-8", "replace")[:3000])
c.close()
