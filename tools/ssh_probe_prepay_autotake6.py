#!/usr/bin/env python3
import os, sys, paramiko
PASSWORD=sys.argv[1]
c=paramiko.SSHClient(); c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
cmds=[
"grep -n 'OnBeforeCrmDealUpdate\\|OnAfterCrmDealUpdate' /home/bitrix/www/bitrix/modules/crm/lib/Service/Operation/Update.php",
"grep -n 'OnBeforeCrmDealUpdate\\|OnAfterCrmDealUpdate' /home/bitrix/www/bitrix/modules/crm/lib/Service/Operation/*.php /home/bitrix/www/bitrix/modules/crm/lib/Service/Operation/*/*.php 2>/dev/null | head -40",
"grep -rn 'OnBeforeCrmDealUpdate' /home/bitrix/www/bitrix/modules/crm/lib/Service --include='*.php' | head -30",
]
for cmd in cmds:
    print("===", cmd[:160])
    _,o,_=c.exec_command(cmd, timeout=20)
    o.channel.settimeout(20)
    try:
        data=o.read().decode("utf-8","replace")
    except Exception as ex:
        data=str(ex)
    open(os.path.join(os.path.dirname(__file__),"_prepay_ops.txt"),"a",encoding="utf-8").write(cmd+"\n"+data+"\n")
    print(data[:2500])
c.close()
