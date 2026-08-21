#!/usr/bin/env python3
import paramiko, sys
HOST, USER = "crm.artflowers.kz", "bitrix"
PASSWORD = sys.argv[1]
cmds = [
    "grep -r 'idInstance\\|apiTokenInstance\\|apiToken' /home/bitrix/www/local/ /home/bitrix/www/bitrix/php_interface/ 2>/dev/null | head -20",
    "grep -r 'idInstance\\|apiTokenInstance' /home/bitrix/www/upload/ 2>/dev/null | head -10",
    "mysql -e \"SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema=DATABASE() AND (TABLE_NAME LIKE '%green%' OR TABLE_NAME LIKE '%fos%')\" 2>/dev/null | head -20",
    "php -r 'require \"/home/bitrix/www/bitrix/modules/main/include/prolog_before.php\"; global $DB; $r=$DB->Query(\"SELECT ID,TITLE,ENTITY_ID FROM b_im_chat WHERE TYPE=\\\"L\\\" AND TITLE LIKE \\\"%\\u0418\\u0442\\u0441%\\\" ORDER BY ID DESC LIMIT 10\"); while($x=$r->Fetch()) print_r($x);' 2>&1",
]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect(HOST, username=USER, password=PASSWORD, timeout=20)
for cmd in cmds:
    print('===', cmd[:100], '===')
    _, stdout, stderr = c.exec_command(cmd, timeout=90)
    print(stdout.read().decode('utf-8', errors='replace')[:6000])
    e = stderr.read().decode('utf-8', errors='replace')
    if e.strip(): print('ERR', e[:300])
    print()
c.close()
