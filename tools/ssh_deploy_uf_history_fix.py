#!/usr/bin/env python3
import os, sys, paramiko
PASSWORD = sys.argv[1]
ROOT = os.path.normpath(os.path.join(os.path.dirname(__file__), ".."))
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=20)
sftp = c.open_sftp()
sftp.put(os.path.join(ROOT, "local", "crm", "include_deal_uf_history.php"), "/home/bitrix/www/local/crm/include_deal_uf_history.php")
print("up", sftp.stat("/home/bitrix/www/local/crm/include_deal_uf_history.php").st_size)
sftp.close()
_, o, _ = c.exec_command("php -l /home/bitrix/www/local/crm/include_deal_uf_history.php; php -r '$_SERVER[\"DOCUMENT_ROOT\"]=\"/home/bitrix/www\"; require \"/home/bitrix/www/local/crm/include_deal_uf_history.php\"; echo waDealUfHistory_same(\"UF_CRM_1764332847245\",\"0\",\"\")?\"bool_same \":\"bool_diff \"; echo waDealUfHistory_same(\"UF_CRM_1764332847245\",\"0\",\"1\")?\"yes_diff_bad\":\"yes_ok\"; echo \"\\n\";'")
print(o.read().decode("utf-8", "replace"))
c.close()
