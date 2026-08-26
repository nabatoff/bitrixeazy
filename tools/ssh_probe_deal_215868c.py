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
\Bitrix\Main\Loader::includeModule("crm");
\Bitrix\Main\Loader::includeModule("bizproc");
$conn=\Bitrix\Main\Application::getConnection();
$id=215868;

echo "UF_DEFAULT\n";
global $USER_FIELD_MANAGER;
$ufs=$USER_FIELD_MANAGER->GetUserFields("CRM_DEAL",0,"ru");
$f=$ufs["UF_CRM_1764332847245"]??[];
echo "type=".$f["USER_TYPE_ID"]." mandatory=".$f["MANDATORY"]." default=".json_encode($f["SETTINGS"]??null)."\n";

echo "\nBP_STATE\n";
foreach($conn->query("SELECT ID, WORKFLOW_TEMPLATE_ID, STATE, STATE_TITLE, MODIFIED FROM b_bp_workflow_state WHERE DOCUMENT_ID='DEAL_215868' ORDER BY ID DESC LIMIT 20") as $r){
  echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n";
}

echo "\nBP_TEMPLATES_USED\n";
$sql="SELECT DISTINCT s.WORKFLOW_TEMPLATE_ID, t.NAME, t.MODULE_ID, t.ENTITY, t.DOCUMENT_STATUS
FROM b_bp_workflow_state s
LEFT JOIN b_bp_workflow_template t ON t.ID=s.WORKFLOW_TEMPLATE_ID
WHERE s.DOCUMENT_ID='DEAL_215868'";
try {
  foreach($conn->query($sql) as $r) echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n";
} catch(Throwable $e) {
  echo $e->getMessage()."\n";
  foreach($conn->query("SHOW COLUMNS FROM b_bp_workflow_template") as $c) echo $c["Field"]."\n";
}

echo "\nTRACKING_SETFIELD\n";
try {
  $sql="SELECT ID, TYPE, ACTION_NOTE, MODIFIED FROM b_bp_tracking WHERE WORKFLOW_ID IN (SELECT ID FROM b_bp_workflow_state WHERE DOCUMENT_ID='DEAL_215868') ORDER BY ID DESC LIMIT 40";
  foreach($conn->query($sql) as $r){
    $note=(string)$r["ACTION_NOTE"];
    if(stripos($note,"1764332847245")!==false || stripos($note,"предоплат")!==false || stripos($note,"Prepay")!==false || $r["TYPE"]=="5"){
      echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n";
    }
  }
} catch(Throwable $e) { echo "tr ".$e->getMessage()."\n"; }

echo "\nTRACKING_ALL_TYPES\n";
try {
  $sql="SELECT TYPE, COUNT(*) CNT FROM b_bp_tracking WHERE WORKFLOW_ID IN (SELECT ID FROM b_bp_workflow_state WHERE DOCUMENT_ID='DEAL_215868') GROUP BY TYPE";
  foreach($conn->query($sql) as $r) echo "type=".$r["TYPE"]." n=".$r["CNT"]."\n";
} catch(Throwable $e) { echo $e->getMessage()."\n"; }

echo "\nTRACKING_LAST\n";
try {
  $sql="SELECT ID, TYPE, ACTION_NAME, ACTION_TITLE, ACTION_NOTE FROM b_bp_tracking WHERE WORKFLOW_ID IN (SELECT ID FROM b_bp_workflow_state WHERE DOCUMENT_ID='DEAL_215868') ORDER BY ID DESC LIMIT 25";
  foreach($conn->query($sql) as $r){
    $note=mb_substr((string)$r["ACTION_NOTE"],0,180);
    echo $r["ID"]." t=".$r["TYPE"]." ".$r["ACTION_NAME"]." ".$r["ACTION_TITLE"]." | ".$note."\n";
  }
} catch(Throwable $e) {
  echo $e->getMessage()."\n";
  foreach($conn->query("SHOW COLUMNS FROM b_bp_tracking") as $c) echo $c["Field"]."\n";
}
'''
sftp=c.open_sftp();
with sftp.file("/tmp/wa_deal_215868c.php","w") as f: f.write(php)
sftp.close()
_,o,_=c.exec_command("php /tmp/wa_deal_215868c.php 2>&1")
open(os.path.join(ROOT,"_deal_215868c.txt"),"w",encoding="utf-8").write(o.read().decode("utf-8","replace"))
print(open(os.path.join(ROOT,"_deal_215868c.txt"),encoding="utf-8").read()[:12000])
c.exec_command("rm -f /tmp/wa_deal_215868c.php")
c.close()
