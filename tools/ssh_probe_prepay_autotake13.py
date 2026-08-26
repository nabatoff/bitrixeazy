#!/usr/bin/env python3
import os, sys, paramiko
PASSWORD=sys.argv[1]
c=paramiko.SSHClient(); c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
php=r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true); define("NOT_CHECK_PERMISSIONS",true);
require $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php";
echo "boot\n";
foreach([77,273106,297782,76] as $id){
  echo "id=$id groups=".implode(",", CUser::GetUserGroup($id))."\n";
  $rs=CUser::GetByID($id); $u=$rs->Fetch();
  echo "  uf_dep=".json_encode($u["UF_DEPARTMENT"]??null)."\n";
}
echo "done\n";
'''
sftp=c.open_sftp();
with sftp.file("/tmp/wa_deps.php","w") as f: f.write(php)
sftp.close()
_,o,_=c.exec_command("php /tmp/wa_deps.php 2>&1 | head -c 4000")
open(os.path.join(os.path.dirname(__file__),"_deps.txt"),"w",encoding="utf-8").write(o.read().decode("utf-8","replace"))
print(open(os.path.join(os.path.dirname(__file__),"_deps.txt"),encoding="utf-8").read())
c.exec_command("rm -f /tmp/wa_deps.php")
c.close()
