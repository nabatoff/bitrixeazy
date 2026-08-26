#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)

cmds = [
    "grep -n 'telephonyController' /home/bitrix/www/bitrix/js/im/im.js | head -40",
    "echo '==== messenger proxy phoneTo ===='",
    "grep -rn 'phoneTo' /home/bitrix/www/bitrix/js/im --include='*.js' | grep -v im.min | grep -v '.map.js' | head -40",
    "echo '==== PhoneCallsController window export ===='",
    "grep -n 'phoneTo\\|BX.IM\\|window\\.BXIM\\|Events.on' /home/bitrix/www/bitrix/modules/voximplant/install/js/voximplant/phone-calls/src/controller.js | head -50",
    "echo '==== controller constructor start ===='",
    "sed -n '1,200p' /home/bitrix/www/bitrix/modules/voximplant/install/js/voximplant/phone-calls/src/controller.js",
]
for cmd in cmds:
    print("\n#####", cmd[:80])
    _, o, _ = c.exec_command(cmd, timeout=25)
    sys.stdout.buffer.write(o.read()[:8000])
c.close()
