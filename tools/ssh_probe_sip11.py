#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)

cmds = [
    "grep -n phoneTo /home/bitrix/www/bitrix/modules/im/install/js/im/v2/application/core/src/*.js 2>/dev/null | head",
    "grep -n PhoneCallsController /home/bitrix/www/bitrix/modules/im/install/js/im/v2/lib/call/src/*.js 2>/dev/null | head",
    "ls /home/bitrix/www/bitrix/modules/im/install/js/im/v2/lib 2>/dev/null",
    "find /home/bitrix/www/bitrix/modules/im/install/js/im/v2 -iname '*phone*' -o -iname '*call*' 2>/dev/null | head -40",
    "grep -l PhoneCallsController /home/bitrix/www/bitrix/modules/im/install/js/im/v2 -r --include='*.js' 2>/dev/null | head",
    "grep -l 'prototype.phoneTo' /home/bitrix/www/bitrix/modules/im/install/js -r --include='*.js' 2>/dev/null | head",
    "grep -n 'phoneTo' /home/bitrix/www/bitrix/modules/im/install/js/im/public/*.js /home/bitrix/www/bitrix/js/im/public.js 2>/dev/null | head",
    "ls /home/bitrix/www/bitrix/modules/im/install/js/im/v2/ 2>/dev/null | head",
]
for cmd in cmds:
    print("\n#####", cmd[:100])
    _, o, _ = c.exec_command(cmd, timeout=20)
    sys.stdout.buffer.write(o.read()[:4000])
c.close()
