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
global $DB;
echo "=== b_crm_event cols ===\n";
try {
  $res=$DB->Query("SHOW COLUMNS FROM b_crm_event");
  while($r=$res->Fetch()) echo $r["Field"]."\n";
} catch(Throwable $e){ echo "err ".$e->getMessage()."\n"; }

echo "\n=== CCrmEvent last prepay ===\n";
try {
  $res=CCrmEvent::GetList(["ID"=>"DESC"], ["ENTITY_TYPE"=>"DEAL","ENTITY_FIELD"=>"UF_CRM_1764332847245"], false, false, ["ID","ENTITY_ID","ENTITY_FIELD","EVENT_TYPE","DATE_CREATE","CREATED_BY_ID","EVENT_NAME","EVENT_TEXT_1","EVENT_TEXT_2"], ["nTopCount"=>10]);
  $n=0;
  while($r=$res->Fetch()){ $n++; echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n"; }
  echo "count=$n\n";
} catch(Throwable $e){ echo "err ".$e->getMessage()."\n"; }

echo "\n=== CCrmEvent name like ===\n";
try {
  $res=CCrmEvent::GetList(["ID"=>"DESC"], ["ENTITY_TYPE"=>"DEAL","%EVENT_NAME"=>"было изменено"], false, false, ["ID","ENTITY_ID","ENTITY_FIELD","DATE_CREATE","CREATED_BY_ID","EVENT_NAME","EVENT_TEXT_1","EVENT_TEXT_2"], ["nTopCount"=>12]);
  $n=0;
  while($r=$res->Fetch()){ $n++; echo $r["ID"]." deal=".$r["ENTITY_ID"]." ".$r["ENTITY_FIELD"]." by=".$r["CREATED_BY_ID"]." ".$r["DATE_CREATE"]." [".$r["EVENT_TEXT_1"]." => ".$r["EVENT_TEXT_2"]."]\n"; }
  echo "count=$n\n";
} catch(Throwable $e){ echo "err ".$e->getMessage()."\n"; }

echo "\n=== deals prepay yes last ===\n";
$res=CCrmDeal::GetListEx(["DATE_MODIFY"=>"DESC"], ["CHECK_PERMISSIONS"=>"N"], false, ["nTopCount"=>80], [
  "ID","DATE_MODIFY","MODIFY_BY_ID","UF_CRM_1764332847245","UF_CRM_1785326361467","UF_CRM_1785324070","UF_CRM_1784636341021","STAGE_ID"
]);
$shown=0; $gaps=0;
while($r=$res->Fetch()){
  $pv=$r["UF_CRM_1764332847245"]??"";
  $ok = ($pv===true || $pv==="1" || $pv===1 || strtoupper((string)$pv)==="Y");
  if(!$ok) continue;
  $tk=(string)($r["UF_CRM_1785326361467"]??"");
  $em=(string)($r["UF_CRM_1785324070"]??"");
  $bad = ($tk!=="937") || ($em==="" || $em==="0");
  if($bad) $gaps++;
  echo "ID=".$r["ID"]." mod=".$r["DATE_MODIFY"]." by=".$r["MODIFY_BY_ID"]." pre=".json_encode($pv)." taken=$tk emp=$em inv=".$r["UF_CRM_1784636341021"].($bad?" <<GAP":"")."\n";
  $shown++;
  if($shown>=20) break;
}
echo "shown=$shown gaps_in_shown=$gaps\n";

echo "\n=== handlers ===\n";
$em=\Bitrix\Main\EventManager::getInstance();
foreach (["OnBeforeCrmDealUpdate","OnAfterCrmDealUpdate"] as $ev) {
  echo $ev."\n";
  foreach($em->findEventHandlers("crm",$ev) as $h){
    echo "  ".json_encode($h, JSON_UNESCAPED_UNICODE)."\n";
  }
}
echo "ORM OnBeforeUpdate\n";
foreach($em->findEventHandlers("crm","\\Bitrix\\Crm\\DealTable::OnBeforeUpdate") as $h){
  echo "  ".json_encode($h, JSON_UNESCAPED_UNICODE)."\n";
}
'''
sftp=c.open_sftp()
with sftp.file("/tmp/wa_prepay_probe2.php","w") as f: f.write(php)
sftp.close()
_,o,e=c.exec_command("php /tmp/wa_prepay_probe2.php 2>&1", timeout=50)
print(o.read().decode("utf-8","replace")[:16000])
c.exec_command("rm -f /tmp/wa_prepay_probe2.php")
c.close()
