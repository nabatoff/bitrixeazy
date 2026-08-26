#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)

cmds = [
    "sed -n '230,290p' /home/bitrix/www/bitrix/modules/im/install/js/im/v2/lib/opener/src/opener.js",
    "echo '==== opener exports ===='",
    "sed -n '1,80p' /home/bitrix/www/bitrix/modules/im/install/js/im/v2/lib/opener/src/opener.js",
    "echo '==== init.js phone ===='",
    "sed -n '50,90p' /home/bitrix/www/bitrix/modules/im/install/js/im/v2/lib/init/src/init.js",
    "echo '==== BXIM phoneTo runtime patch ===='",
    "grep -n 'phoneTo' /home/bitrix/www/bitrix/modules/im/install/js/im/v2/lib/init/src/init.js /home/bitrix/www/bitrix/modules/im/install/js/im/v2/application/core/src/*.js 2>/dev/null | head",
    "grep -n 'BXIM.phoneTo\\|prototype.phoneTo' /home/bitrix/www/bitrix/modules/im/install/js/im/v2/lib/opener/src/opener.js",
    "echo '==== opener bundle namespace ===='",
    "cat /home/bitrix/www/bitrix/modules/im/install/js/im/v2/lib/opener/bundle.config.js",
]
for cmd in cmds:
    print("\n#####", cmd[:90])
    _, o, _ = c.exec_command(cmd, timeout=15)
    sys.stdout.buffer.write(o.read()[:6000])
c.close()
