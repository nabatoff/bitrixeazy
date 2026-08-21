#!/usr/bin/env python3
import paramiko
import sys

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=sys.argv[1], timeout=20)
_, o, _ = c.exec_command(
    "grep -n 'urlShow || f.urlDownload || f.urlPreview' /home/bitrix/www/local/custom_chat/index.php | head -5; "
    "grep -n 'data-proxy' /home/bitrix/www/local/custom_chat/index.php | head -5; "
    "grep -n 'proxyTried' /home/bitrix/www/local/custom_chat/index.php | head -5"
)
print(o.read().decode())
c.close()
