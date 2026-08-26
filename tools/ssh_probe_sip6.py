#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)

cmds = [
    "grep -n 'phoneTo\\|phoneCall\\|startCall\\|BXIM\\|lineId\\|LINE_ID\\|phoneParams' /home/bitrix/www/bitrix/modules/voximplant/install/js/voximplant/phone-calls/src/controller.js | head -60",
    "echo '==== getAllowedLines ===='",
    "sed -n '428,470p' /home/bitrix/www/bitrix/modules/voximplant/classes/general/vi_user.php",
    "echo '==== getAllowedLines body ===='",
    "sed -n '619,722p' /home/bitrix/www/bitrix/modules/voximplant/classes/general/vi_user.php",
    "echo '==== GetConfigByUserId line pick ===='",
    "sed -n '268,360p' /home/bitrix/www/bitrix/modules/voximplant/classes/general/vi_outgoing.php",
    "echo '==== crm phoneTo context ===='",
    "sed -n '1540,1590p' /home/bitrix/www/bitrix/js/crm/common.js",
    "echo '==== phoneTo override elsewhere ===='",
    "grep -rn 'phoneTo\\s*=' /home/bitrix/www/bitrix/js/im /home/bitrix/www/bitrix/modules/im/install/js /home/bitrix/www/bitrix/js/voximplant /home/bitrix/www/bitrix/modules/voximplant/install/js --include='*.js' 2>/dev/null | grep -v '.map.js' | grep -v im.min | head -30",
]
for cmd in cmds:
    print("\n#####", cmd[:70])
    _, o, _ = c.exec_command(cmd, timeout=25)
    sys.stdout.buffer.write(o.read()[:7000])
c.close()
