#!/usr/bin/env python3
import sys, paramiko
PASSWORD=sys.argv[1]
c=paramiko.SSHClient(); c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
php=r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true); define("NOT_CHECK_PERMISSIONS",true);
require $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("crm");
global $USER;
$USER->Authorize(69);
$fields=[
  "TITLE"=>"ACL smoke",
  "UF_CRM_1764332847245"=>"Y", // prepay — manager cannot
  "UF_CRM_1782798174634"=>"1", // country — manager CAN (dept 251)
  "UF_CRM_1784636341021"=>"914", // invoice — manager cannot
];
$ref=new ReflectionClass("DealStageGuard");
$m=$ref->getMethod("stripForbiddenIncomingFields");
$m->setAccessible(true);
$codes=$m->invoke(null, 0, $fields, "smoke-add");
echo "stripped=".json_encode($codes, JSON_UNESCAPED_UNICODE)."\n";
echo "left_prepay=".(array_key_exists("UF_CRM_1764332847245",$fields)?"Y":"N")."\n";
echo "left_invoice=".(array_key_exists("UF_CRM_1784636341021",$fields)?"Y":"N")."\n";
echo "left_country=".(array_key_exists("UF_CRM_1782798174634",$fields)?"Y":"N")."\n";
echo "can_prepay=".(DealStageGuard::userCanEditField("UF_CRM_1764332847245",69)?"Y":"N")."\n";
echo "can_country=".(DealStageGuard::userCanEditField("UF_CRM_1782798174634",69)?"Y":"N")."\n";
echo "locked_cnt=".count(DealStageGuard::getLockedFieldsForCurrentUser())."\n";

// auto-take as accountant 295096 with prepay in fields
$USER->authorize(295096);
$f=["ID"=>215868,"UF_CRM_1764332847245"=>"Y"];
$got=waDealAutoTake_applyToFields($f);
echo "autotake295096=".json_encode($got)." fields=".json_encode(array_intersect_key($f,array_flip($got?:[])))."\n";

// manager trigger should NOT stamp accountant
$USER->authorize(69);
$f2=["ID"=>215868,"UF_CRM_1764332847245"=>"Y"];
$got2=waDealAutoTake_applyToFields($f2);
echo "autotake69=".json_encode($got2)."\n";
'''
sftp=c.open_sftp();
with sftp.file("/tmp/wa_smoke_acl.php","w") as f: f.write(php)
sftp.close()
_,o,_=c.exec_command("php /tmp/wa_smoke_acl.php 2>&1")
print(o.read().decode("utf-8","replace")[:5000])
c.exec_command("rm -f /tmp/wa_smoke_acl.php")
c.close()
