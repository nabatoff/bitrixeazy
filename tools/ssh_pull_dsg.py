#!/usr/bin/env python3
import os, sys, paramiko
PASSWORD=sys.argv[1]
ROOT=os.path.dirname(os.path.dirname(__file__))
c=paramiko.SSHClient(); c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
sftp=c.open_sftp()
local=os.path.join(ROOT,"tools","_DealStageGuard.php")
sftp.get("/home/bitrix/www/bitrix/php_interface/classes/DealStageGuard.php", local)
sftp.get("/home/bitrix/www/local/crm/include_deal_auto_take.php", os.path.join(ROOT,"tools","_srv_auto_take.php"))
print("downloaded", os.path.getsize(local), os.path.getsize(os.path.join(ROOT,"tools","_srv_auto_take.php")))
sftp.close(); c.close()
