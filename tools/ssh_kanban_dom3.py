#!/usr/bin/env python3
import paramiko, sys
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=sys.argv[1], timeout=20)
cmds = [
    "find /home/bitrix/www/bitrix/js -path '*kanban*item*' -name '*.js' | head -20",
    "grep -rn \"data-id\" /home/bitrix/www/bitrix/js/ui/kanban --include='*.js' 2>/dev/null | head -20",
    "ls /home/bitrix/www/bitrix/js/ui/kanban 2>/dev/null | head",
    "grep -n \"createLayout\\|data-id\\|getId()\" /home/bitrix/www/bitrix/js/ui/kanban/item.js 2>/dev/null | head -40",
]
for cmd in cmds:
    print("===", cmd)
    _, o, e = c.exec_command(cmd, timeout=40)
    print(o.read().decode("utf-8", "replace")[:4000])
    err = e.read().decode("utf-8", "replace")
    if err:
        print("ERR", err[:500])
c.close()
