#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=20)
cmds = [
    "ls /home/bitrix/www/bitrix/modules/crm/lib/Service/EventHistory/",
    "ls /home/bitrix/www/bitrix/modules/crm/lib/Service/EventHistory/TrackedObject/",
    "sed -n '1,120p' /home/bitrix/www/bitrix/modules/crm/lib/Service/EventHistory/TrackedObject.php",
    "grep -rn 'getTrackedFieldNames\\|TrackedFields\\|OPPORTUNITY\\|addTracked' /home/bitrix/www/bitrix/modules/crm/lib/Service/EventHistory --include='*.php' | head -40",
    "grep -rn 'EventHistory\\|TrackedObject' /home/bitrix/www/bitrix/modules/crm/lib/Service/Factory/Deal.php 2>/dev/null | head -25",
    "grep -n 'modification\\|FIELD\\|register' /home/bitrix/www/bitrix/modules/crm/lib/timeline/dealcontroller.php | head -40",
    # sample events in DB for a deal - opportunity change
    r'''php -r '
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true); define("NOT_CHECK_PERMISSIONS",true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("crm");
global $DB;
$r=$DB->Query("SELECT ID, EVENT_NAME, EVENT_TEXT_1, EVENT_TEXT_2, CREATED_BY_ID, DATE_CREATE FROM b_crm_event WHERE ENTITY_TYPE=\"DEAL\" ORDER BY ID DESC LIMIT 15");
while($row=$r->Fetch()){
  echo $row["ID"]." | u=".$row["CREATED_BY_ID"]." | ".$row["EVENT_NAME"]." | ".mb_substr(str_replace("\n"," ",$row["EVENT_TEXT_1"]??""),0,80)." | ".mb_substr(str_replace("\n"," ",$row["EVENT_TEXT_2"]??""),0,60)."\n";
}
echo "---timeline---\n";
$r2=$DB->Query("SELECT ID, TYPE_ID, TYPE_CATEGORY_ID, AUTHOR_ID, CREATED FROM b_crm_timeline ORDER BY ID DESC LIMIT 10");
while($row=$r2->Fetch()){ echo json_encode($row, JSON_UNESCAPED_UNICODE)."\n"; }
' ''',
]
for cmd in cmds:
    print("====")
    _, o, e = c.exec_command(cmd, timeout=60)
    print(o.read().decode("utf-8", "replace")[:3500])
c.close()
