#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)

cmds = [
    "grep -rn 'phoneTo' /home/bitrix/www/bitrix/modules/im/install/js/im/v2 --include='*.js' 2>/dev/null | grep -v '.map.js' | head -40",
    "echo '==== MessengerProxy ===='",
    "grep -rn 'phoneTo\\|telephonyController\\|PhoneCallsController' /home/bitrix/www/bitrix/js/im --include='*.js' 2>/dev/null | grep -v min.js | grep -v map.js | head -40",
    "echo '==== find MessengerProxy file ===='",
    "find /home/bitrix/www/bitrix -name '*messenger*proxy*' -o -name '*MessengerProxy*' 2>/dev/null | head",
    "grep -rn 'prototype.phoneTo\\|BXIM.phoneTo\\s*=' /home/bitrix/www/bitrix --include='*.js' 2>/dev/null | grep -v min.js | grep -v map.js | grep -v node_modules | head -30",
    "echo '==== im.v2 application phone ===='",
    "grep -rn 'PhoneCallsController' /home/bitrix/www/bitrix/modules --include='*.js' --include='*.php' 2>/dev/null | grep -v node_modules | grep -v '.map' | head -25",
]
for cmd in cmds:
    print("\n#####", cmd[:90])
    _, o, _ = c.exec_command(cmd, timeout=40)
    sys.stdout.buffer.write(o.read()[:5000])
c.close()
