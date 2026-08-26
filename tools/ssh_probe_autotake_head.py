#!/usr/bin/env python3
import sys, paramiko
PASSWORD=sys.argv[1]
c=paramiko.SSHClient(); c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
_,o,_=c.exec_command("sed -n '12,50p' /home/bitrix/www/local/crm/include_deal_auto_take.php; echo '---'; grep -c 1764332847245 /home/bitrix/www/local/crm/include_deal_auto_take.php /home/bitrix/www/local/crm/include_deal_uf_history.php")
print(o.read().decode('utf-8','replace'))
c.close()
