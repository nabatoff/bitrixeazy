#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)

cmds = [
    "grep -rn 'SILENT_MODE' /home/bitrix/www/bitrix/modules/im/lib/ /home/bitrix/www/bitrix/modules/im/classes/ 2>/dev/null | head -20",
    "grep -rn 'SILENT_CONNECTOR' /home/bitrix/www/bitrix/modules/im/lib/V2/Message/Send/SendingConfig.php | head",
    "sed -n '100,200p' /home/bitrix/www/bitrix/modules/im/lib/V2/Message/Send/SendingConfig.php",
    "grep -rn 'function commit' /home/bitrix/www/bitrix/modules/im/lib/disk.php | head",
    "grep -rn 'SILENT_CONNECTOR\\|SKIP_CONNECTOR\\|silentConnector' /home/bitrix/www/bitrix/modules/im/lib/disk.php | head -20",
]
for cmd in cmds:
    print("=== " + cmd[:110] + " ===")
    _, o, e = c.exec_command(cmd, timeout=60)
    sys.stdout.buffer.write(o.read()[:6000])
    err = e.read()
    if err.strip():
        sys.stdout.buffer.write(b"ERR " + err[:300])
    print()
c.close()
