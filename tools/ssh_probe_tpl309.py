#!/usr/bin/env python3
import sys, paramiko
PASSWORD=sys.argv[1]
c=paramiko.SSHClient(); c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
_,o,_=c.exec_command("stat -c '%y %n' /home/bitrix/www/local/crm/include_deal_auto_take.php /home/bitrix/www/local/crm/include_deal_uf_history.php; echo '--- autotake triggers ---'; grep -n -A2 'triggers' /home/bitrix/www/local/crm/include_deal_auto_take.php | head -40")
print(o.read().decode('utf-8','replace'))
php=r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true); define("NOT_CHECK_PERMISSIONS",true);
require $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php";
$conn=\Bitrix\Main\Application::getConnection();
$raw=$conn->query("SELECT TEMPLATE FROM b_bp_workflow_template WHERE ID=309")->fetch();
$t=$raw["TEMPLATE"];
if(is_string($t)){ $u=@unserialize($t); if($u===false){ $z=@gzuncompress($t); if($z) $u=@unserialize($z);} $t=$u?:$t; }
$s=serialize($t);
echo "309 prepayUF=".(strpos($s,"1764332847245")!==false?"Y":"N")." SetField=".(strpos($s,"SetFieldActivity")!==false?"Y":"N")."\n";
function walk($n, $d=0){
  if(!is_array($n)) return;
  if(($n["Type"]??"")==="SetFieldActivity"){
    echo "SETFIELD ".$n["Properties"]["Title"]." ".json_encode($n["Properties"]["FieldValue"]??null, JSON_UNESCAPED_UNICODE)."\n";
  }
  foreach($n["Children"]??[] as $ch) walk($ch, $d+1);
}
walk($t[0]??$t);

echo "\n==== siblings 215866 215867 accountant ====\n";
foreach($conn->query("SELECT VALUE_ID, UF_CRM_1764332847245, UF_CRM_1785326361467, UF_CRM_1785324070 FROM b_uts_crm_deal WHERE VALUE_ID IN (215866,215867,215868,215873,215874)") as $r){
  echo json_encode($r)."\n";
}
'''
sftp=c.open_sftp();
with sftp.file("/tmp/wa_309.php","w") as f: f.write(php)
sftp.close()
_,o,_=c.exec_command("php /tmp/wa_309.php 2>&1")
print(o.read().decode("utf-8","replace")[:8000])
c.exec_command("rm -f /tmp/wa_309.php")
c.close()
