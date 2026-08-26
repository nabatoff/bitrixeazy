#!/usr/bin/env python3
import os, sys, paramiko
PASSWORD = sys.argv[1]
ROOT = os.path.dirname(__file__)
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
c.get_transport().set_keepalive(15)
php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
require $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("crm");
$id=215868;
$conn=\Bitrix\Main\Application::getConnection();

$row=CCrmDeal::GetListEx([],["=ID"=>$id,"CHECK_PERMISSIONS"=>"N"],false,false,[
  "ID","TITLE","STAGE_ID","DATE_CREATE","DATE_MODIFY","CREATED_BY_ID","MODIFY_BY_ID","ASSIGNED_BY_ID",
  "UF_CRM_1764332847245","UF_CRM_1764332899326","UF_CRM_1785326361467","UF_CRM_1785324070",
  "UF_CRM_1784636341021","UF_CRM_1764577842986","UF_CRM_1783486791226","UF_CRM_1783485774093",
  "UF_CRM_1785325552","UF_CRM_1784524115744"
])->Fetch();
echo "DEAL ".json_encode($row, JSON_UNESCAPED_UNICODE)."\n";

echo "\nEVENTS\n";
$sql="SELECT R.ID rel, R.ENTITY_FIELD, R.ASSIGNED_BY_ID, E.ID ev, E.CREATED_BY_ID, E.EVENT_TYPE, E.EVENT_NAME, E.EVENT_TEXT_1, E.EVENT_TEXT_2
FROM b_crm_event_relations R
INNER JOIN b_crm_event E ON E.ID=R.EVENT_ID
WHERE R.ENTITY_TYPE='DEAL' AND R.ENTITY_ID=".$id."
ORDER BY R.ID DESC LIMIT 40";
foreach($conn->query($sql) as $r){
  echo $r["rel"]." field=".$r["ENTITY_FIELD"]." by=".$r["CREATED_BY_ID"]." type=".$r["EVENT_TYPE"]." | ".$r["EVENT_NAME"]." | ".$r["EVENT_TEXT_1"]." => ".$r["EVENT_TEXT_2"]."\n";
}

echo "\nTIMELINE\n";
try {
  $sql="SELECT ID, TYPE_ID, TYPE_CATEGORY_ID, AUTHOR_ID, CREATED FROM b_crm_timeline WHERE ASSOCIATED_ENTITY_TYPE_ID=2 AND ASSOCIATED_ENTITY_ID=".$id." ORDER BY ID DESC LIMIT 20";
  foreach($conn->query($sql) as $r) echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n";
} catch(Throwable $e) {
  echo "tl_err ".$e->getMessage()."\n";
  try {
    $sql="SHOW COLUMNS FROM b_crm_timeline";
    foreach($conn->query($sql) as $r) echo $r["Field"]."\n";
  } catch(Throwable $e2) { echo $e2->getMessage()."\n"; }
}

echo "\nUF raw\n";
try {
  $sql="SHOW TABLES LIKE 'b_uts_crm_deal'";
  foreach($conn->query($sql) as $r) echo implode(" ",$r)."\n";
  $sql="SELECT VALUE_ID, UF_CRM_1764332847245, UF_CRM_1785326361467, UF_CRM_1785324070, UF_CRM_1764332899326 FROM b_uts_crm_deal WHERE VALUE_ID=".$id;
  foreach($conn->query($sql) as $r) echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n";
} catch(Throwable $e) { echo $e->getMessage()."\n"; }
'''
sftp=c.open_sftp()
with sftp.file("/tmp/wa_deal_215868.php","w") as f:
    f.write(php)
sftp.close()
_,o,_=c.exec_command("php /tmp/wa_deal_215868.php > /tmp/wa_deal_215868.txt 2>&1; echo EXIT:$?")
print(o.read().decode("utf-8","replace"))
sftp=c.open_sftp()
out=os.path.join(ROOT,"_deal_215868.txt")
sftp.get("/tmp/wa_deal_215868.txt", out)
sftp.close()
c.exec_command("rm -f /tmp/wa_deal_215868.php /tmp/wa_deal_215868.txt")
c.close()
print(open(out,encoding="utf-8").read())
