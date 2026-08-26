#!/usr/bin/env python3
import os, sys, paramiko
PASSWORD=sys.argv[1]
ROOT=os.path.dirname(__file__)
c=paramiko.SSHClient(); c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
php=r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true); define("NOT_CHECK_PERMISSIONS",true);
require $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php";
$conn=\Bitrix\Main\Application::getConnection();
$tpl=294;
$row=$conn->query("SELECT ID, NAME, LENGTH(TEMPLATE) L FROM b_bp_workflow_template WHERE ID=".$tpl)->fetch();
echo "TPL ".$row["ID"]." ".$row["NAME"]." len=".$row["L"]."\n";
$raw=$conn->query("SELECT TEMPLATE FROM b_bp_workflow_template WHERE ID=".$tpl)->fetch();
$t=$raw["TEMPLATE"]??"";
if(is_array($t)) $t=serialize($t);
$s=is_string($t)?$t:serialize($t);
foreach(["1764332847245","1785326361467","1785324070","Y","предоплат"] as $needle){
  echo "has[$needle]=".(strpos($s,$needle)!==false?"Y":"N")."\n";
}
# also template 361 stage robots
foreach([361,294,307] as $id){
  $raw=$conn->query("SELECT ID,NAME,TEMPLATE FROM b_bp_workflow_template WHERE ID=".$id)->fetch();
  $s=is_string($raw["TEMPLATE"])?$raw["TEMPLATE"]:serialize($raw["TEMPLATE"]);
  echo "\nT$id ".$raw["NAME"]." prepayUF=".(strpos($s,"1764332847245")!==false?"Y":"N")." taken=".(strpos($s,"1785326361467")!==false?"Y":"N")." emp=".(strpos($s,"1785324070")!==false?"Y":"N")."\n";
}
'''
sftp=c.open_sftp();
with sftp.file("/tmp/wa_tpl.php","w") as f: f.write(php)
sftp.close()
_,o,_=c.exec_command("php /tmp/wa_tpl.php 2>&1")
print(o.read().decode("utf-8","replace")[:4000])
c.exec_command("rm -f /tmp/wa_tpl.php")
c.close()
