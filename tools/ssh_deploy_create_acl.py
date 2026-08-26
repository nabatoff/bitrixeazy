#!/usr/bin/env python3
"""Deploy ACL-on-Add + auto-take + history + UI lock. Backup first."""
import os, sys, time, paramiko
PASSWORD = sys.argv[1]
ROOT = os.path.dirname(os.path.dirname(__file__))
HOST, USER = "crm.artflowers.kz", "bitrix"
ts = time.strftime("%Y%m%d_%H%M%S")
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect(HOST, username=USER, password=PASSWORD, timeout=25)

files = [
    (os.path.join(ROOT, "tools", "_DealStageGuard.php"),
     "/home/bitrix/www/bitrix/php_interface/classes/DealStageGuard.php"),
    (os.path.join(ROOT, "local", "crm", "include_deal_auto_take.php"),
     "/home/bitrix/www/local/crm/include_deal_auto_take.php"),
    (os.path.join(ROOT, "local", "crm", "include_deal_uf_history.php"),
     "/home/bitrix/www/local/crm/include_deal_uf_history.php"),
    (os.path.join(ROOT, "local", "crm", "include_deal_uf_lock.php"),
     "/home/bitrix/www/local/crm/include_deal_uf_lock.php"),
    (os.path.join(ROOT, "local", "crm", "deal_uf_lock.js"),
     "/home/bitrix/www/local/crm/deal_uf_lock.js"),
]

# backup
cmds = []
for _, remote in files:
    bak = remote + ".bak_" + ts
    cmds.append(f'cp -a "{remote}" "{bak}" && echo BAK {bak}')
_, o, e = c.exec_command(" && ".join(cmds))
print(o.read().decode("utf-8", "replace"))
print(e.read().decode("utf-8", "replace"))

sftp = c.open_sftp()
for local, remote in files:
    sftp.put(local, remote)
    print("PUT", remote, os.path.getsize(local))
sftp.close()

_, o, _ = c.exec_command(
    "php -l /home/bitrix/www/bitrix/php_interface/classes/DealStageGuard.php; "
    "php -l /home/bitrix/www/local/crm/include_deal_auto_take.php; "
    "php -l /home/bitrix/www/local/crm/include_deal_uf_history.php; "
    "php -l /home/bitrix/www/local/crm/include_deal_uf_lock.php"
)
print(o.read().decode("utf-8", "replace"))

# smoke: handlers registered
php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true); define("NOT_CHECK_PERMISSIONS",true);
require $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php";
echo "userCanEdit=". (method_exists("DealStageGuard","userCanEditField")?"Y":"N")."\n";
echo "locked69=";
// simulate as guest first
echo json_encode(\DealStageGuard::getLockedFieldsForCurrentUser())."\n";
$evs=[];
foreach(GetModuleEvents("crm","OnBeforeCrmDealAdd",true) as $h){
  $evs[]=($h["TO_CLASS"]?$h["TO_CLASS"]."::".$h["TO_METHOD"]:$h["TO_NAME"]);
}
echo "OnBeforeCrmDealAdd=".implode(" | ",$evs)."\n";
$em=\Bitrix\Main\EventManager::getInstance();
$h=$em->findEventHandlers("crm","\\Bitrix\\Crm\\DealTable::OnBeforeAdd");
echo "ORM OnBeforeAdd n=".count($h)."\n";
foreach($h as $x) echo "  ".json_encode($x["TO_NAME"]??$x["CALLBACK"])."\n";
'''
sftp=c.open_sftp()
with sftp.file("/tmp/wa_deploy_check.php","w") as f: f.write(php)
sftp.close()
_,o,_=c.exec_command("php /tmp/wa_deploy_check.php 2>&1")
print(o.read().decode("utf-8","replace"))
c.exec_command("rm -f /tmp/wa_deploy_check.php")
c.close()
print("DONE", ts)
