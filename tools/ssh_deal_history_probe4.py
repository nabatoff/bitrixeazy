#!/usr/bin/env python3
import sys, paramiko, os
PASSWORD = sys.argv[1]
ROOT = os.path.dirname(__file__)
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=20)
script = r'''
grep -rn "setTrackedFieldNames\|getFieldsForEventHistory\|TrackedField\|EVENT_HISTORY" /home/bitrix/www/bitrix/modules/crm/lib/Service --include="*.php" 2>/dev/null | head -50
echo "===="
grep -rn "setTrackedFieldNames\|OPPORTUNITY\|getEventHistory" /home/bitrix/www/bitrix/modules/crm/lib/Service/Factory.php 2>/dev/null | head -40
echo "==== DEAL FACTORY ===="
grep -n "Tracked\|EventHistory\|getFieldsToShow" /home/bitrix/www/bitrix/modules/crm/lib/Service/Factory/Deal.php 2>/dev/null | head -40
echo "==== CCrmEvent ===="
grep -n "Register\|AddEvent\|FIELD" /home/bitrix/www/bitrix/modules/crm/classes/general/crm_event.php 2>/dev/null | head -40
echo "==== sample opportunity events ===="
php -r '
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true);define("NOT_CHECK_PERMISSIONS",true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("crm");
global $DB;
$r=$DB->Query("SELECT e.ID, e.EVENT_NAME, e.EVENT_TEXT_1, e.EVENT_TEXT_2, e.CREATED_BY_ID FROM b_crm_event e INNER JOIN b_crm_event_relations r ON r.EVENT_ID=e.ID WHERE r.ENTITY_TYPE=\"DEAL\" AND e.EVENT_NAME LIKE \"%умм%\" ORDER BY e.ID DESC LIMIT 8");
while($row=$r->Fetch()){ echo $row["ID"]."\t".$row["CREATED_BY_ID"]."\t".$row["EVENT_NAME"]."\t".$row["EVENT_TEXT_1"]." => ".$row["EVENT_TEXT_2"]."\n"; }
$r=$DB->Query("SELECT e.ID, e.EVENT_NAME, e.CREATED_BY_ID FROM b_crm_event e INNER JOIN b_crm_event_relations r ON r.EVENT_ID=e.ID WHERE r.ENTITY_TYPE=\"DEAL\" AND (e.EVENT_NAME LIKE \"%Счет%\" OR e.EVENT_NAME LIKE \"%редоплат%\" OR e.EVENT_NAME LIKE \"%акупл%\" OR e.EVENT_NAME LIKE \"%ыдан%\") ORDER BY e.ID DESC LIMIT 10");
echo "---UF-like---\n";
while($row=$r->Fetch()){ echo $row["ID"]."\t".$row["CREATED_BY_ID"]."\t".$row["EVENT_NAME"]."\n"; }
'
'''
sftp=c.open_sftp()
with sftp.file("/tmp/wa_h4.sh","w") as f: f.write(script)
sftp.close()
_,o,_=c.exec_command("bash /tmp/wa_h4.sh > /tmp/wa_h4.txt 2>&1; wc -c /tmp/wa_h4.txt", timeout=60)
print(o.read().decode())
sftp=c.open_sftp()
sftp.get("/tmp/wa_h4.txt", os.path.join(ROOT,"_history_probe4.txt"))
sftp.close()
c.close()
print(open(os.path.join(ROOT,"_history_probe4.txt"),encoding="utf-8",errors="replace").read()[:5000])
