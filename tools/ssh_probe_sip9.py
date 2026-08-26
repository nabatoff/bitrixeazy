#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)

cmds = [
    "grep -rn 'phoneTo' /home/bitrix/www/bitrix/modules/im --include='*.js' --include='*.ts' 2>/dev/null | grep -v node_modules | grep -v '.min.js' | grep -v '.map.js' | head -40",
    "echo '==== voximplant phoneTo ===='",
    "grep -rn 'phoneTo' /home/bitrix/www/bitrix/modules/voximplant --include='*.js' --include='*.php' 2>/dev/null | grep -v node_modules | grep -v '.min.js' | grep -v '.map' | head -30",
    "echo '==== BX.Voximplant public ===='",
    "grep -n 'phoneCall\\|phoneTo\\|BX.Voximplant' /home/bitrix/www/bitrix/js/voximplant/voximplant.js /home/bitrix/www/bitrix/modules/voximplant/install/js/voximplant/src/*.js 2>/dev/null | head -30",
    "ls /home/bitrix/www/bitrix/modules/voximplant/install/js/voximplant/",
    "echo '==== im.v2 call service ===='",
    "grep -rn 'PhoneCallsController\\|telephonyController\\|phoneCall(' /home/bitrix/www/bitrix/modules/im/install/js --include='*.js' 2>/dev/null | grep -v node_modules | grep -v '.min.js' | head -30",
]
for cmd in cmds:
    print("\n#####", cmd[:90])
    _, o, _ = c.exec_command(cmd, timeout=30)
    sys.stdout.buffer.write(o.read()[:6000])
c.close()
