#!/usr/bin/env python3
import sys, paramiko
PASSWORD=sys.argv[1]
c=paramiko.SSHClient(); c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
c.get_transport().set_keepalive(15)

def run(cmd, timeout=25):
    print("===", cmd[:160])
    _,o,e=c.exec_command(cmd, timeout=timeout)
    o.channel.settimeout(timeout)
    try: print(o.read().decode("utf-8","replace")[:12000])
    except Exception as ex: print("timeout", ex)

run("ls /home/bitrix/www/bitrix/modules/crm/classes/general | grep -i event")
run("grep -n 'function Add\\|b_crm_event\\|ENTITY_TYPE\\|RELATIONS' /home/bitrix/www/bitrix/modules/crm/classes/general/crm_event.php | head -50")
run("mysql -Nse \"SHOW TABLES LIKE '%crm_event%'\" 2>/dev/null || echo no-mysql-cli")

php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
require $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("crm");
$conn=\Bitrix\Main\Application::getConnection();
echo "=== tables ===\n";
foreach($conn->query("SHOW TABLES LIKE '%crm_event%'") as $r){ echo implode(" ",$r)."\n"; }
echo "=== timeline tables ===\n";
foreach($conn->query("SHOW TABLES LIKE '%crm_timeline%'") as $r){ echo implode(" ",$r)."\n"; }

echo "\n=== b_crm_event_relations cols ===\n";
try { foreach($conn->query("SHOW COLUMNS FROM b_crm_event_relations") as $r) echo $r["Field"]." ".$r["Type"]."\n"; } catch(Throwable $e){ echo $e->getMessage()."\n"; }

echo "\n=== last 5 crm events raw ===\n";
foreach($conn->query("SELECT * FROM b_crm_event ORDER BY ID DESC LIMIT 5") as $r){
  echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n";
}

echo "\n=== last relations ===\n";
try {
  foreach($conn->query("SELECT * FROM b_crm_event_relations ORDER BY EVENT_ID DESC LIMIT 8") as $r){
    echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n";
  }
} catch(Throwable $e){ echo $e->getMessage()."\n"; }

echo "\n=== deal 215779 ===\n";
$row=CCrmDeal::GetListEx([],["=ID"=>215779,"CHECK_PERMISSIONS"=>"N"],false,false,[
  "ID","TITLE","DATE_MODIFY","MODIFY_BY_ID","STAGE_ID",
  "UF_CRM_1764332847245","UF_CRM_1785326361467","UF_CRM_1785324070","UF_CRM_1784636341021"
])->Fetch();
echo json_encode($row, JSON_UNESCAPED_UNICODE)."\n";

echo "\n=== CCrmEvent GetList for deal 215779 ===\n";
$res=CCrmEvent::GetList(["ID"=>"DESC"], ["ENTITY_TYPE"=>"DEAL","ENTITY_ID"=>215779], false, false, [], ["nTopCount"=>15]);
$n=0;
while($r=$res->Fetch()){ $n++; echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n"; }
echo "count=$n\n";

echo "\n=== CCrmEvent GetList for deal 215667 (worked autotake) ===\n";
$res=CCrmEvent::GetList(["ID"=>"DESC"], ["ENTITY_TYPE"=>"DEAL","ENTITY_ID"=>215667], false, false, [], ["nTopCount"=>15]);
$n=0;
while($r=$res->Fetch()){ $n++; echo json_encode(["ID"=>$r["ID"]??"","NAME"=>$r["EVENT_NAME"]??"","F"=>$r["ENTITY_FIELD"]??$r["EVENT_FIELD"]??"","T1"=>$r["EVENT_TEXT_1"]??"","T2"=>$r["EVENT_TEXT_2"]??"","BY"=>$r["CREATED_BY_ID"]??"","DT"=>$r["DATE_CREATE"]??""], JSON_UNESCAPED_UNICODE)."\n"; }
echo "count=$n\n";
'''
sftp=c.open_sftp()
with sftp.file("/tmp/wa_prepay_probe3.php","w") as f: f.write(php)
sftp.close()
_,o,_=c.exec_command("php /tmp/wa_prepay_probe3.php 2>&1", timeout=40)
print(o.read().decode("utf-8","replace")[:16000])
c.exec_command("rm -f /tmp/wa_prepay_probe3.php")
c.close()
