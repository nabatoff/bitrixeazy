#!/usr/bin/env python3
import os, sys, paramiko
PASSWORD=sys.argv[1]
c=paramiko.SSHClient(); c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
require $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("crm");
header("Content-Type: text/plain; charset=utf-8");
function uname($id){
  $rs=CUser::GetByID((int)$id); $u=$rs->Fetch();
  if(!$u) return "missing";
  return $u["LOGIN"]."|".$u["NAME"]." ".$u["LAST_NAME"]."|ext=".$u["EXTERNAL_AUTH_ID"];
}
foreach([77,69,76,72,273106,297782,295096,98562] as $id){
  echo $id." ".uname($id)."\n";
}
$conn=\Bitrix\Main\Application::getConnection();
echo "GAP_PREPAY\n";
$ids="215779,215760,215141,215542,215543,215544,215432";
$sql="SELECT R.ENTITY_ID deal, E.CREATED_BY_ID by_id FROM b_crm_event_relations R INNER JOIN b_crm_event E ON E.ID=R.EVENT_ID WHERE R.ENTITY_TYPE='DEAL' AND R.ENTITY_FIELD='UF_CRM_1764332847245' AND R.ENTITY_ID IN ($ids)";
foreach($conn->query($sql) as $r) echo $r["deal"]." by=".$r["by_id"]."\n";
'''
sftp=c.open_sftp()
with sftp.file("/tmp/wa_prepay_probe5.php","w") as f: f.write(php)
sftp.close()
_,o,_=c.exec_command("php /tmp/wa_prepay_probe5.php > /tmp/wa_prepay_probe5.txt 2>&1; python3 -c 'print(open(\"/tmp/wa_prepay_probe5.txt\",\"rb\").read().decode(\"utf-8\",\"replace\"))'")
raw=o.read().decode("utf-8","replace")
out=os.path.join(os.path.dirname(__file__), "_prepay_users.txt")
open(out,"w",encoding="utf-8").write(raw)
print("wrote", out, "len", len(raw))
c.exec_command("rm -f /tmp/wa_prepay_probe5.php /tmp/wa_prepay_probe5.txt")
c.close()
