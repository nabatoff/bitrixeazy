#!/usr/bin/env python3
import sys, paramiko, os
PASSWORD = sys.argv[1]
ROOT = os.path.normpath(os.path.join(os.path.dirname(__file__), ".."))
out = os.path.join(ROOT, "tools", "_uf_labels_utf8.txt")
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=20)
php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true); define("NOT_CHECK_PERMISSIONS",true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("crm");
global $USER_FIELD_MANAGER;
$fields=$USER_FIELD_MANAGER->GetUserFields("CRM_DEAL",0,"ru");
$ids=[
"UF_CRM_1764332847245","UF_CRM_1764577842986","UF_CRM_1764578603013","UF_CRM_1764577192130",
"UF_CRM_1783485774093","UF_CRM_1783486791226","UF_CRM_1784524115744","UF_CRM_1784636341021",
"UF_CRM_1785324070","UF_CRM_1785325552","UF_CRM_1785326361467",
"UF_CRM_1787123117","UF_CRM_1787123174","UF_CRM_1782797106378"
];
foreach($ids as $id){
  if(!isset($fields[$id])){file_put_contents("php://stdout",$id." MISSING\n");continue;}
  $f=$fields[$id];
  $label=is_array($f["EDIT_FORM_LABEL"]??null)?($f["EDIT_FORM_LABEL"]["ru"]??(string)reset($f["EDIT_FORM_LABEL"])):(string)($f["EDIT_FORM_LABEL"]??"");
  $line=$id."\t".$f["USER_TYPE_ID"]."\t".$label;
  if(($f["USER_TYPE_ID"]??"")==="enumeration"){
    $rs=CUserFieldEnum::GetList([],["USER_FIELD_ID"=>(int)$f["ID"]]); $e=[];
    while($r=$rs->Fetch()) $e[]=$r["ID"]."=".$r["VALUE"];
    $line.="\t".implode("; ",$e);
  }
  file_put_contents("php://stdout",$line."\n");
}
'''
sftp=c.open_sftp()
with sftp.file("/tmp/wa_uf_map3.php","w") as f: f.write(php)
sftp.close()
_,o,_=c.exec_command("php /tmp/wa_uf_map3.php > /tmp/wa_uf_labels.txt 2>&1; wc -c /tmp/wa_uf_labels.txt", timeout=40)
print(o.read().decode())
sftp=c.open_sftp()
sftp.get("/tmp/wa_uf_labels.txt", out)
sftp.close()
c.exec_command("rm -f /tmp/wa_uf_map3.php /tmp/wa_uf_labels.txt")
c.close()
print("saved", out)
print(open(out, encoding="utf-8").read())
