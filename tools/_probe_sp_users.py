#!/usr/bin/env python3
import paramiko
KEY = r'C:\Users\15bit\.ssh\id_rsa_bitrix'
s = r'''<?php
$_SERVER['DOCUMENT_ROOT']='/home/bitrix/www';
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
require '/home/bitrix/www/bitrix/modules/main/include/prolog_before.php';
\Bitrix\Main\Loader::includeModule('artflowers.salesplan');
foreach([50,75,251937] as $uid){
  $a=new \Artflowers\Salesplan\Internal\AccessService($uid);
  echo "USER $uid branch=".$a->resolveDefaultBranchId()." almaty_view=".($a->canViewBranch('almaty')?'Y':'N')." almaty_edit=".($a->canEditBranchPlans('almaty')?'Y':'N')." head=".($a->isBranchHead('almaty')?'Y':'N')."\n";
  if($a->canViewBranch($a->resolveDefaultBranchId()?:'almaty')){
    $bid=$a->resolveDefaultBranchId()?:'almaty';
    $d=\Artflowers\Salesplan\Internal\PlanRepository::buildDashboard($a,$bid,(int)date('Y'),(int)date('n'),'all');
    echo "  managers=".count($d['managers'])." can_edit=".($d['permissions']['can_edit']?'Y':'N')."\n";
  }
}
'''
c=paramiko.SSHClient(); c.set_missing_host_key_policy(paramiko.AutoAddPolicy()); c.connect('crm.artflowers.kz',username='bitrix',key_filename=KEY)
sftp=c.open_sftp()
with sftp.open('/tmp/heads2.php','w') as f: f.write(s.encode())
sftp.close()
_,o,_=c.exec_command('php /tmp/heads2.php'); print(o.read().decode())
