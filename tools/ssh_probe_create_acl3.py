#!/usr/bin/env python3
import sys, paramiko
PASSWORD=sys.argv[1]
c=paramiko.SSHClient(); c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)

_,o,_=c.exec_command("cat /home/bitrix/www/local/crm/deal_uf_lock.js")
print("===== deal_uf_lock.js =====")
print(o.read().decode("utf-8","replace"))

_,o,_=c.exec_command("grep -rl 'dsg_field_permissions' /home/bitrix/www/local /home/bitrix/www/bitrix/php_interface /home/bitrix/www/admin 2>/dev/null")
print("===== grep option =====")
print(o.read().decode("utf-8","replace"))

_,o,_=c.exec_command("ls /home/bitrix/www/local/; echo '---'; find /home/bitrix/www/local /home/bitrix/www/bitrix/php_interface -iname '*deal*' -o -iname '*dsg*' -o -iname '*guard*' 2>/dev/null")
print("===== local/php_interface files =====")
print(o.read().decode("utf-8","replace"))

_,o,_=c.exec_command("grep -n 'OnBeforeCrmDealAdd\\|DealTable::OnBeforeAdd\\|evaluateFieldEditPermissions' /home/bitrix/www/bitrix/php_interface/classes/DealStageGuard.php | head")
print("===== DSG refs =====")
print(o.read().decode("utf-8","replace"))

# conversion: does lead copy UFs?
_,o,_=c.exec_command("grep -n 'USER_FIELD\\|UF_CRM\\|mapUserFields\\|convert' /home/bitrix/www/bitrix/modules/crm/lib/conversion/leadconversionwizard.php 2>/dev/null | head -30")
print("===== lead conversion wizard =====")
print(o.read().decode("utf-8","replace"))

_,o,_=c.exec_command("php -r 'echo \"ok\";' 2>&1 | head")
print("php ok")
c.close()
