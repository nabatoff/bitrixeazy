#!/usr/bin/env python3
import sys
import paramiko

PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
c.get_transport().set_keepalive(15)

def run(cmd, timeout=30):
    print("===", cmd[:180], "===")
    _, o, e = c.exec_command(cmd, timeout=timeout)
    o.channel.settimeout(timeout)
    try:
        out = o.read().decode("utf-8", "replace")
    except Exception as ex:
        out = "<timeout %s>\n" % ex
    print(out[:16000])
    try:
        err = e.read().decode("utf-8", "replace")
    except Exception:
        err = ""
    if err.strip():
        print("ERR", err[:400])

run("grep -n 'include_deal_auto_take\\|include_deal_uf_history\\|include_deal_uf_lock' /home/bitrix/www/bitrix/php_interface/init.php")
run("grep -n 'OnBeforeCrmDealUpdate\\|OnAfterCrmDealUpdate\\|DealTable::On' /home/bitrix/www/local/crm/include_deal_auto_take.php /home/bitrix/www/local/crm/include_deal_uf_history.php")
run("grep -n 'modifyFields\\|EventResult\\|addEventHandler' /home/bitrix/www/local/crm/include_deal_auto_take.php")

php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
require $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("crm");
global $DB, $USER_FIELD_MANAGER;

$pre="UF_CRM_1764332847245";
$taken="UF_CRM_1785326361467";
$emp="UF_CRM_1785324070";

echo "=== UF types ===\n";
$ufs=$USER_FIELD_MANAGER->GetUserFields("CRM_DEAL", 0, "ru");
foreach ([$pre,$taken,$emp,"UF_CRM_1784636341021"] as $f) {
  $u=$ufs[$f]??null;
  echo $f." type=".($u["USER_TYPE_ID"]??"?")." id=".($u["ID"]??0)."\n";
}

echo "\n=== functions ===\n";
echo "auto=". (function_exists("waDealAutoTake_applyToFields")?"Y":"N")."\n";
echo "hist=". (function_exists("waDealUfHistory_onAfterUpdate")?"Y":"N")."\n";

echo "\n=== last events for prepay UF ===\n";
$res=$DB->Query("SELECT ID, ENTITY_ID, ENTITY_FIELD, EVENT_TYPE, DATE_CREATE, CREATED_BY_ID, EVENT_NAME, EVENT_TEXT_1, EVENT_TEXT_2 FROM b_crm_event WHERE ENTITY_TYPE='DEAL' AND ENTITY_FIELD='".$DB->ForSql($pre)."' ORDER BY ID DESC LIMIT 10");
$n=0;
while($r=$res->Fetch()){ $n++; echo $r["ID"]." deal=".$r["ENTITY_ID"]." t=".$r["EVENT_TYPE"]." by=".$r["CREATED_BY_ID"]." ".$r["DATE_CREATE"]." [".$r["EVENT_TEXT_1"]." => ".$r["EVENT_TEXT_2"]."] ".$r["EVENT_NAME"]."\n"; }
echo "count=$n\n";

echo "\n=== last ANY uf-history events ===\n";
$res=$DB->Query("SELECT ID, ENTITY_ID, ENTITY_FIELD, DATE_CREATE, CREATED_BY_ID, EVENT_NAME, EVENT_TEXT_1, EVENT_TEXT_2 FROM b_crm_event WHERE ENTITY_TYPE='DEAL' AND EVENT_NAME LIKE '%было изменено%' ORDER BY ID DESC LIMIT 12");
$n=0;
while($r=$res->Fetch()){ $n++; echo $r["ID"]." deal=".$r["ENTITY_ID"]." ".$r["ENTITY_FIELD"]." by=".$r["CREATED_BY_ID"]." ".$r["DATE_CREATE"]." [".$r["EVENT_TEXT_1"]." => ".$r["EVENT_TEXT_2"]."]\n"; }
echo "count=$n\n";

echo "\n=== deals: prepay=1, accountant take empty, last 15 ===\n";
$res=CCrmDeal::GetListEx(
  ["DATE_MODIFY"=>"DESC"],
  ["CHECK_PERMISSIONS"=>"N", "!$pre"=>false],
  false,
  ["nTopCount"=>40],
  ["ID","DATE_MODIFY","DATE_CREATE","MODIFY_BY_ID","UF_CRM_1764332847245","UF_CRM_1785326361467","UF_CRM_1785324070","UF_CRM_1784636341021","STAGE_ID"]
);
$shown=0;
while($r=$res->Fetch()){
  $pv=(string)($r[$pre]??"");
  if($pv!=="1" && strtoupper($pv)!=="Y") continue;
  $tk=(string)($r[$taken]??"");
  $em=(string)($r[$emp]??"");
  $bad = ($tk==="" || $tk==="0" || $tk!=="937") || ($em==="" || $em==="0");
  echo "ID=".$r["ID"]." mod=".$r["DATE_MODIFY"]." by=".$r["MODIFY_BY_ID"]." pre=$pv taken=$tk emp=$em inv=".$r["UF_CRM_1784636341021"]." stage=".$r["STAGE_ID"].($bad?" <<GAP":"")."\n";
  $shown++;
  if($shown>=15) break;
}

echo "\n=== handlers registered ===\n";
$em=\Bitrix\Main\EventManager::getInstance();
foreach (["OnBeforeCrmDealUpdate","OnAfterCrmDealUpdate"] as $ev) {
  $list=$em->findEventHandlers("crm",$ev);
  echo $ev.":\n";
  foreach($list as $h){
    $cb=$h["TO_CLASS"]."::".$h["TO_METHOD"].($h["TO_NAME"]??"");
    if(!$h["TO_CLASS"]) $cb=(string)($h["TO_NAME"]??$h["TO_METHOD"]??json_encode($h));
    echo "  ".$cb."\n";
  }
}
'''
sftp = c.open_sftp()
with sftp.file("/tmp/wa_prepay_probe.php", "w") as f:
    f.write(php)
sftp.close()
run("php /tmp/wa_prepay_probe.php", timeout=45)
run("rm -f /tmp/wa_prepay_probe.php")
c.close()
