#!/usr/bin/env python3
import os, sys, paramiko
PASSWORD=sys.argv[1]
ROOT=os.path.dirname(__file__)
c=paramiko.SSHClient(); c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
c.get_transport().set_keepalive(15)
php=r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true); define("NOT_CHECK_PERMISSIONS",true);
require $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("crm");
$id=215868;
$conn=\Bitrix\Main\Application::getConnection();

echo "UTS_COLS_PREPAY\n";
foreach($conn->query("SHOW COLUMNS FROM b_uts_crm_deal LIKE 'UF_CRM_176%'") as $r) echo $r["Field"]."\n";
echo "UTS_ACC\n";
foreach($conn->query("SHOW COLUMNS FROM b_uts_crm_deal LIKE 'UF_CRM_178532%'") as $r) echo $r["Field"]."\n";

echo "\nUTS_ROW\n";
$cols=[];
foreach($conn->query("SHOW COLUMNS FROM b_uts_crm_deal") as $r) $cols[]=$r["Field"];
$need=["VALUE_ID","UF_CRM_1764332847245","UF_CRM_1785326361467","UF_CRM_1785324070","UF_CRM_1784636341021","UF_CRM_1786008089","UF_CRM_1764577192130"];
$sel=[];
foreach($need as $c) if(in_array($c,$cols,true)) $sel[]=$c;
$sql="SELECT ".implode(",",$sel)." FROM b_uts_crm_deal WHERE VALUE_ID=".$id;
foreach($conn->query($sql) as $r) echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n";

echo "\nPREPAY_EVENTS_ANY\n";
$sql="SELECT COUNT(*) CNT FROM b_crm_event_relations WHERE ENTITY_TYPE='DEAL' AND ENTITY_ID=".$id." AND ENTITY_FIELD='UF_CRM_1764332847245'";
foreach($conn->query($sql) as $r) echo "cnt=".$r["CNT"]."\n";

echo "\nSTAGE_NOW\n";
$d=CCrmDeal::GetByID($id, false);
echo "STAGE=".$d["STAGE_ID"]." CAT=".$d["CATEGORY_ID"]." MODIFY_BY=".$d["MODIFY_BY_ID"]." DATE_MODIFY=".$d["DATE_MODIFY"]."\n";

echo "\nBP_TASKS\n";
try {
  foreach($conn->query("SHOW TABLES LIKE 'b_bp%'") as $r) { /* skip */ }
  $sql="SELECT ID, WORKFLOW_ID, MODIFIED, USER_ID, STATUS FROM b_bp_tracking WHERE WORKFLOW_ID IN (SELECT WORKFLOW_ID FROM b_bp_workflow_state WHERE DOCUMENT_ID LIKE '%215868%') ORDER BY ID DESC LIMIT 15";
} catch(Throwable $e) { echo $e->getMessage()."\n"; }
try {
  $sql="SELECT ID, STATE, DOCUMENT_ID, MODIFIED FROM b_bp_workflow_state WHERE DOCUMENT_ID LIKE '%215868%' ORDER BY ID DESC LIMIT 10";
  foreach($conn->query($sql) as $r) echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n";
} catch(Throwable $e) { echo "bp ".$e->getMessage()."\n"; }

echo "\nHANDLERS_ADD\n";
$em=\Bitrix\Main\EventManager::getInstance();
foreach(["OnAfterCrmDealAdd","OnBeforeCrmDealAdd"] as $ev){
  echo $ev."\n";
  foreach($em->findEventHandlers("crm",$ev) as $h) echo "  ".($h["TO_NAME"]??json_encode($h))."\n";
}
'''
sftp=c.open_sftp();
with sftp.file("/tmp/wa_deal_215868b.php","w") as f: f.write(php)
sftp.close()
_,o,_=c.exec_command("php /tmp/wa_deal_215868b.php 2>&1")
open(os.path.join(ROOT,"_deal_215868b.txt"),"w",encoding="utf-8").write(o.read().decode("utf-8","replace"))
print(open(os.path.join(ROOT,"_deal_215868b.txt"),encoding="utf-8").read())
c.exec_command("rm -f /tmp/wa_deal_215868b.php")
c.close()
