#!/usr/bin/env python3
import sys, paramiko
PASSWORD=sys.argv[1]
c=paramiko.SSHClient(); c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
c.get_transport().set_keepalive(15)

php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
require $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("crm");
$conn=\Bitrix\Main\Application::getConnection();

echo "=== relations for prepay UF ===\n";
$sql="SELECT R.ID,R.ENTITY_ID,R.ENTITY_FIELD,R.EVENT_ID,R.ASSIGNED_BY_ID,E.DATE_CREATE,E.CREATED_BY_ID,E.EVENT_NAME,E.EVENT_TEXT_1,E.EVENT_TEXT_2
FROM b_crm_event_relations R
INNER JOIN b_crm_event E ON E.ID=R.EVENT_ID
WHERE R.ENTITY_TYPE='DEAL' AND R.ENTITY_FIELD='UF_CRM_1764332847245'
ORDER BY R.ID DESC LIMIT 15";
$n=0;
foreach($conn->query($sql) as $r){ $n++; echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n"; }
echo "count=$n\n";

echo "\n=== any UF_CRM_ event fields last 20 ===\n";
$sql="SELECT R.ENTITY_FIELD, COUNT(*) CNT FROM b_crm_event_relations R WHERE R.ENTITY_FIELD LIKE 'UF_CRM_%' GROUP BY R.ENTITY_FIELD ORDER BY CNT DESC LIMIT 20";
try { foreach($conn->query($sql) as $r) echo $r["ENTITY_FIELD"]." ".$r["CNT"]."\n"; }
catch(Throwable $e){ echo "skip group: ".$e->getMessage()."\n";
  $sql="SELECT R.ID,R.ENTITY_ID,R.ENTITY_FIELD,E.EVENT_NAME,E.EVENT_TEXT_1,E.EVENT_TEXT_2 FROM b_crm_event_relations R INNER JOIN b_crm_event E ON E.ID=R.EVENT_ID WHERE R.ENTITY_FIELD LIKE 'UF_CRM_%' ORDER BY R.ID DESC LIMIT 20";
  foreach($conn->query($sql) as $r) echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n";
}

echo "\n=== relations deal 215779 ===\n";
$sql="SELECT R.ENTITY_FIELD,E.EVENT_NAME,E.EVENT_TEXT_1,E.EVENT_TEXT_2,E.CREATED_BY_ID,E.DATE_CREATE FROM b_crm_event_relations R INNER JOIN b_crm_event E ON E.ID=R.EVENT_ID WHERE R.ENTITY_TYPE='DEAL' AND R.ENTITY_ID=215779 ORDER BY R.ID DESC LIMIT 20";
$n=0; foreach($conn->query($sql) as $r){ $n++; echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n"; } echo "count=$n\n";

echo "\n=== relations deal 215667 ===\n";
$sql="SELECT R.ENTITY_FIELD,E.EVENT_NAME,E.EVENT_TEXT_1,E.EVENT_TEXT_2,E.CREATED_BY_ID,E.DATE_CREATE FROM b_crm_event_relations R INNER JOIN b_crm_event E ON E.ID=R.EVENT_ID WHERE R.ENTITY_TYPE='DEAL' AND R.ENTITY_ID=215667 ORDER BY R.ID DESC LIMIT 20";
$n=0; foreach($conn->query($sql) as $r){ $n++; echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n"; } echo "count=$n\n";

echo "\n=== ORM EventResult usage in core ===\n";
'''
sftp=c.open_sftp()
with sftp.file("/tmp/wa_prepay_probe4.php","w") as f: f.write(php)
sftp.close()
_,o,_=c.exec_command("php /tmp/wa_prepay_probe4.php 2>&1", timeout=40)
print(o.read().decode("utf-8","replace")[:14000])

_,o,_=c.exec_command("grep -n 'modifyFields\\|EventResult' /home/bitrix/www/bitrix/modules/main/lib/orm/data/datamanager.php | head -20")
print("DM", o.read().decode("utf-8","replace")[:2000])
_,o,_=c.exec_command("grep -n 'function modifyFields\\|class EventResult' /home/bitrix/www/bitrix/modules/main/lib/orm/eventresult.php /home/bitrix/www/bitrix/modules/main/lib/entity/eventresult.php 2>/dev/null | head")
print("ER", o.read().decode("utf-8","replace")[:1500])
_,o,_=c.exec_command("grep -n 'getModified\\|EventResult\\|parameters' /home/bitrix/www/bitrix/modules/main/lib/orm/data/update.php 2>/dev/null | head -30")
print("UPD", o.read().decode("utf-8","replace")[:2000])

# DealStageGuard - does it wipe extra UFs?
_,o,_=c.exec_command("grep -n 'function onBeforeUpdateOrm\\|unset\\|UF_CRM' /home/bitrix/www/bitrix/php_interface/classes/DealStageGuard.php | head -40")
print("GUARD", o.read().decode("utf-8","replace")[:2500])

c.exec_command("rm -f /tmp/wa_prepay_probe4.php")
c.close()
