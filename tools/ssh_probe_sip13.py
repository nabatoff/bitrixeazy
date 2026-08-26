#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)

cmds = [
    "grep -n 'PhoneManager\\|startCall\\|phoneTo' /home/bitrix/www/bitrix/modules/im/install/js/im/v2/lib/opener/src/*.js 2>/dev/null | head -40",
    "grep -rn 'PhoneManager' /home/bitrix/www/bitrix/modules/im/install/js/im/v2 --include='*.js' 2>/dev/null | grep -v dist | grep -v map.js | head -30",
    "echo '==== messenger proxy / compatibility ===='",
    "grep -n 'phoneTo\\|PhoneManager' /home/bitrix/www/bitrix/modules/im/install/js/im/v2/lib/desktop/src/*.js 2>/dev/null | head",
    "ls /home/bitrix/www/bitrix/modules/im/install/js/im/v2/lib/phone/",
    "cat /home/bitrix/www/bitrix/modules/im/install/js/im/v2/lib/phone/bundle.config.js 2>/dev/null",
    "grep -n 'phoneTo\\|PhoneManager.startCall' /home/bitrix/www/bitrix/js/crm/common.js | head",
    "echo '==== rest voximplant.call.start ===='",
    "grep -n 'call.start\\|LINE_ID' /home/bitrix/www/bitrix/modules/voximplant/lib/rest.php 2>/dev/null | head -20",
]
for cmd in cmds:
    print("\n#####", cmd[:100])
    _, o, _ = c.exec_command(cmd, timeout=20)
    sys.stdout.buffer.write(o.read()[:5000])
c.close()
