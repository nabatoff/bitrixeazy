#!/usr/bin/env python3
import sys, paramiko, os
PASSWORD = sys.argv[1]
ROOT = os.path.dirname(__file__)
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=20)

php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true); define("NOT_CHECK_PERMISSIONS",true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("crm");
global $USER_FIELD_MANAGER;
$f=$USER_FIELD_MANAGER->GetUserFields("CRM_DEAL",0,"ru");
$id="UF_CRM_1784802244742";
if(!isset($f[$id])){ echo "MISSING\n"; exit; }
$x=$f[$id];
$label=is_array($x["EDIT_FORM_LABEL"]??null)?($x["EDIT_FORM_LABEL"]["ru"]??reset($x["EDIT_FORM_LABEL"])):(string)($x["EDIT_FORM_LABEL"]??"");
echo $id."\t".$x["USER_TYPE_ID"]."\t".$label."\n";
if($x["USER_TYPE_ID"]==="enumeration"){
  $rs=CUserFieldEnum::GetList([],["USER_FIELD_ID"=>(int)$x["ID"]]);
  while($e=$rs->Fetch()) echo $e["ID"]."=".$e["VALUE"]."\n";
}
// CCrmEvent::Add signature sample from recent events
echo "CCrmEvent=". (class_exists("CCrmEvent")?"1":"0")."\n";
'''
sftp=c.open_sftp()
with sftp.file("/tmp/wa_uf_hist.php","w") as f: f.write(php)
sftp.close()
_,o,_=c.exec_command("php /tmp/wa_uf_hist.php > /tmp/wa_uf_hist.txt 2>&1")
print(o.read().decode())
sftp=c.open_sftp()
sftp.get("/tmp/wa_uf_hist.txt", os.path.join(ROOT,"_uf_hist_field.txt"))
# get Add method snippet
_,o,_=c.exec_command("sed -n '1,180p' /home/bitrix/www/bitrix/modules/crm/classes/general/crm_event.php")
open(os.path.join(ROOT,"_crm_event_add.txt"),"w",encoding="utf-8").write(o.read().decode("utf-8","replace"))
sftp.close()
print(open(os.path.join(ROOT,"_uf_hist_field.txt"),encoding="utf-8").read())
print(open(os.path.join(ROOT,"_crm_event_add.txt"),encoding="utf-8").read()[:2000])
c.close()
