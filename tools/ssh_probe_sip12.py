#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)

cmds = [
    "cat /home/bitrix/www/bitrix/modules/im/install/js/im/v2/lib/phone/src/phone.js",
    "echo '==== call.js head ===='",
    "sed -n '1,80p' /home/bitrix/www/bitrix/modules/im/install/js/im/v2/lib/call/src/call.js",
    "echo '==== grep phoneTo in phone bundle ===='",
    "grep -n 'phoneTo\\|phoneCall\\|LINE_ID' /home/bitrix/www/bitrix/modules/im/install/js/im/v2/lib/phone/src/phone.js",
]
for cmd in cmds:
    print("\n#####", cmd[:80])
    _, o, _ = c.exec_command(cmd, timeout=15)
    sys.stdout.buffer.write(o.read()[:12000])
c.close()
