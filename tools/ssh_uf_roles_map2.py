#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=20)
php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true);
define("NOT_CHECK_PERMISSIONS",true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("crm");
header("Content-Type: text/plain; charset=utf-8");
global $USER_FIELD_MANAGER;
$fields = $USER_FIELD_MANAGER->GetUserFields("CRM_DEAL", 0, "ru");
$ids = [
"UF_CRM_1764332847245","UF_CRM_1764577842986","UF_CRM_1764578603013",
"UF_CRM_1783485774093","UF_CRM_1783486791226","UF_CRM_1784524115744",
"UF_CRM_1784636341021","UF_CRM_1785324070","UF_CRM_1785325552","UF_CRM_1785326361467",
"UF_CRM_1787123117","UF_CRM_1787123174","UF_CRM_1782797106378",
"UF_CRM_1784800055339","UF_CRM_1784800394776","UF_CRM_1784800839176",
"UF_CRM_1784871379729","UF_CRM_1785309636123",
];
foreach ($ids as $id) {
  if (!isset($fields[$id])) { echo "$id MISSING\n"; continue; }
  $f=$fields[$id];
  $label = is_array($f["EDIT_FORM_LABEL"]??null) ? ($f["EDIT_FORM_LABEL"]["ru"]??reset($f["EDIT_FORM_LABEL"])) : ($f["EDIT_FORM_LABEL"]??"");
  echo $id."\t".$f["USER_TYPE_ID"]."\t".$label;
  if (($f["USER_TYPE_ID"]??"")==="enumeration") {
    $rs=CUserFieldEnum::GetList([],["USER_FIELD_ID"=>(int)$f["ID"]]);
    $e=[];
    while($row=$rs->Fetch()) $e[]=$row["ID"]."=".$row["VALUE"];
    echo "\t".implode("; ",$e);
  }
  echo "\n";
}
'''
sftp=c.open_sftp()
with sftp.file("/tmp/wa_uf_map2.php","w") as f: f.write(php)
sftp.close()
_,o,_=c.exec_command("php /tmp/wa_uf_map2.php 2>&1 | iconv -f utf-8 -t utf-8", timeout=40)
raw=o.read()
print(raw.decode("utf-8","replace"))
c.exec_command("rm -f /tmp/wa_uf_map2.php")
c.close()
