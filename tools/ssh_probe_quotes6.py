#!/usr/bin/env python3
import sys, paramiko
PASSWORD=sys.argv[1]
c=paramiko.SSHClient(); c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
def run(cmd, timeout=20):
    print("===", cmd[:180])
    _,o,e=c.exec_command(cmd, timeout=timeout)
    o.channel.settimeout(timeout)
    try: print(o.read().decode("utf-8","replace")[:8000])
    except Exception as ex: print("timeout", ex)
run("grep -n SILENT_CONNECTOR /home/bitrix/www/bitrix/modules/im/classes/general/im_messenger.php | head")
run("grep -n SILENT_CONNECTOR /home/bitrix/www/bitrix/modules/imopenlines/lib/*.php /home/bitrix/www/bitrix/modules/imopenlines/lib/*/*.php 2>/dev/null | head -30")
run("grep -n \"SKIP_CONNECTOR\\|SILENT_CONNECTOR\\|REPLY_ID\" /home/bitrix/www/bitrix/modules/im/lib/V2/Service/Messenger.php 2>/dev/null | head")
run("grep -n quotedMessage /home/bitrix/www/local/custom_chat/index.php | head")
c.close()
