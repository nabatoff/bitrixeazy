#!/usr/bin/env python3
import paramiko
import sys

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=sys.argv[1], timeout=20)
cmds = [
    "ls /home/bitrix/www/bitrix/js/crm/kanban | head -30",
    "grep -rn crm-kanban-item /home/bitrix/www/bitrix/js/crm/kanban --include='*.js' | head -15",
    "grep -rn getColumnId /home/bitrix/www/bitrix/js/crm/kanban --include='*.js' | head -15",
    "ls /home/bitrix/www/bitrix/components/bitrix/crm.kanban 2>/dev/null | head -20",
]
for cmd in cmds:
    print("===", cmd)
    _, o, _ = c.exec_command(cmd, timeout=40)
    print(o.read().decode("utf-8", "replace")[:3000])
c.close()
