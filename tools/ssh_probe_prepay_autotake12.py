#!/usr/bin/env python3
import os, sys, paramiko
PASSWORD=sys.argv[1]
c=paramiko.SSHClient(); c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
php=r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true); define("NOT_CHECK_PERMISSIONS",true);
require $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php";
$raw=\Bitrix\Main\Config\Option::get("main","dsg_field_permissions","");
$cfg=json_decode($raw,true);
$want=["UF_CRM_1764332847245","UF_CRM_1785326361467","UF_CRM_1785324070"];
if(!is_array($cfg)){ echo "no_config\n"; echo substr($raw,0,200); exit; }
foreach($want as $f){
  echo $f." ".json_encode($cfg[$f]??null, JSON_UNESCAPED_UNICODE)."\n";
}
echo "keys=".implode(",", array_keys($cfg))."\n";
'''
sftp=c.open_sftp();
with sftp.file("/tmp/wa_dsg.php","w") as f: f.write(php)
sftp.close()
_,o,_=c.exec_command("php /tmp/wa_dsg.php > /tmp/wa_dsg.txt 2>&1")
sftp=c.open_sftp()
sftp.get("/tmp/wa_dsg.txt", os.path.join(os.path.dirname(__file__),"_dsg_perm.txt"))
sftp.close()
c.exec_command("rm -f /tmp/wa_dsg.php /tmp/wa_dsg.txt")
c.close()
print(open(os.path.join(os.path.dirname(__file__),"_dsg_perm.txt"),encoding="utf-8").read())
