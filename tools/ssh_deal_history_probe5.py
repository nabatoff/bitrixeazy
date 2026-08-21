#!/usr/bin/env python3
import sys, paramiko, os
PASSWORD = sys.argv[1]
ROOT = os.path.dirname(__file__)
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=20)
_, o, _ = c.exec_command("sed -n '580,640p' /home/bitrix/www/bitrix/modules/crm/lib/Service/Factory/Deal.php")
text = o.read().decode("utf-8", "replace")
open(os.path.join(ROOT, "_deal_tracked.txt"), "w", encoding="utf-8").write(text)
print(text)
# also Factory.php around 1570
_, o, _ = c.exec_command("sed -n '1555,1620p' /home/bitrix/www/bitrix/modules/crm/lib/Service/Factory.php")
print("---FACTORY---")
print(o.read().decode("utf-8", "replace")[:2500])
# check if UF in events ever
_, o, _ = c.exec_command(r'''php -r '
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true);define("NOT_CHECK_PERMISSIONS",true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("crm");
global $DB;
$r=$DB->Query("SELECT COUNT(*) C FROM b_crm_event e INNER JOIN b_crm_event_relations r ON r.EVENT_ID=e.ID WHERE r.ENTITY_TYPE=\"DEAL\" AND e.EVENT_NAME LIKE \"%Значение поля%\"");
$row=$r->Fetch(); echo "field_change_events=".$row["C"]."\n";
$r=$DB->Query("SELECT e.EVENT_NAME, COUNT(*) C FROM b_crm_event e INNER JOIN b_crm_event_relations r ON r.EVENT_ID=e.ID WHERE r.ENTITY_TYPE=\"DEAL\" AND e.EVENT_NAME LIKE \"%Значение поля%\" GROUP BY e.EVENT_NAME ORDER BY C DESC LIMIT 25");
while($x=$r->Fetch()) echo $x["C"]."\t".$x["EVENT_NAME"]."\n";
' ''')
open(os.path.join(ROOT, "_event_names.txt"), "w", encoding="utf-8").write(o.read().decode("utf-8", "replace"))
print(open(os.path.join(ROOT, "_event_names.txt"), encoding="utf-8").read()[:3000])
c.close()
