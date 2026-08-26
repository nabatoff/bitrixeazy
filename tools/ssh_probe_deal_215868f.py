#!/usr/bin/env python3
import os, sys, paramiko
PASSWORD=sys.argv[1]
ROOT=os.path.dirname(__file__)
c=paramiko.SSHClient(); c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
php=r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true); define("NOT_CHECK_PERMISSIONS",true);
require $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php";
$conn=\Bitrix\Main\Application::getConnection();

echo "==== INIT SNIPPET DSG ====\n";
$init=file_get_contents("/home/bitrix/www/bitrix/php_interface/init.php");
$pos=strpos($init,"DealStageGuard");
echo "pos=$pos\n";
echo substr($init, max(0,$pos-400), 1200)."\n";

echo "\n==== FIND DSG FILE ====\n";
passthru('grep -rl "class DealStageGuard" /home/bitrix/www/local /home/bitrix/www/bitrix/php_interface --include="*.php" 2>/dev/null | head');

echo "\n==== BP 294 STATE ====\n";
foreach($conn->query("SELECT ID, WORKFLOW_TEMPLATE_ID, STATE, STATE_TITLE FROM b_bp_workflow_state WHERE DOCUMENT_ID='DEAL_215868' AND WORKFLOW_TEMPLATE_ID IN (294,307,361,314,309)") as $r){
  echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n";
}

echo "\n==== WAREHOUSE UFS ====\n";
$row=$conn->query("SELECT UF_CRM_1784524115744, UF_CRM_1787123174, UF_CRM_1787123117, UF_CRM_1764332847245, UF_CRM_1785326361467, UF_CRM_1785324070 FROM b_uts_crm_deal WHERE VALUE_ID=215868")->fetch();
echo json_encode($row, JSON_UNESCAPED_UNICODE)."\n";

echo "\n==== EVENTS PREPAY-LIKE ====\n";
$sql="SELECT e.ID, e.EVENT_NAME, e.ENTITY_FIELD, e.EVENT_TEXT_1, e.EVENT_TEXT_2, e.CREATED_BY_ID, e.DATE_CREATE
FROM b_crm_event e
INNER JOIN b_crm_event_relations r ON r.EVENT_ID=e.ID
WHERE r.ENTITY_TYPE='DEAL' AND r.ENTITY_ID=215868
AND (e.ENTITY_FIELD LIKE 'UF_CRM_%' OR e.EVENT_NAME LIKE '%редоплат%' OR e.EVENT_NAME LIKE '%ухгалт%')
ORDER BY e.ID";
foreach($conn->query($sql) as $r) echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n";

echo "\n==== ALL UF EVENTS ====\n";
$sql="SELECT e.ID, e.ENTITY_FIELD, e.EVENT_NAME, LEFT(e.EVENT_TEXT_1,40) A, LEFT(e.EVENT_TEXT_2,40) B, e.CREATED_BY_ID, e.DATE_CREATE
FROM b_crm_event e INNER JOIN b_crm_event_relations r ON r.EVENT_ID=e.ID
WHERE r.ENTITY_TYPE='DEAL' AND r.ENTITY_ID=215868 AND e.ENTITY_FIELD LIKE 'UF%'
ORDER BY e.ID";
foreach($conn->query($sql) as $r) echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n";

echo "\n==== TPL294 FULL CONDITIONS ====\n";
$raw=$conn->query("SELECT TEMPLATE FROM b_bp_workflow_template WHERE ID=294")->fetch();
$t=$raw["TEMPLATE"];
if(is_string($t)){ $u=@unserialize($t); if($u===false){ $z=@gzuncompress($t); if($z) $u=@unserialize($z);} $t=$u?:$t; }
echo json_encode($t, JSON_UNESCAPED_UNICODE)."\n";
'''
sftp=c.open_sftp()
with sftp.file("/tmp/wa_deal_215868f.php","w") as f: f.write(php)
sftp.close()
_,o,_=c.exec_command("php /tmp/wa_deal_215868f.php 2>&1")
out=o.read().decode("utf-8","replace")
open(os.path.join(ROOT,"_deal_215868f.txt"),"w",encoding="utf-8").write(out)
print(out[:16000])
c.exec_command("rm -f /tmp/wa_deal_215868f.php")
c.close()
