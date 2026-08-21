#!/usr/bin/env python3
import os, sys, paramiko

PASSWORD = sys.argv[1]
ROOT = os.path.normpath(os.path.join(os.path.dirname(__file__), ".."))
FILES = [
    ("local/crm/include_deal_uf_lock.php", "/home/bitrix/www/local/crm/include_deal_uf_lock.php"),
    ("local/crm/include_deal_auto_take.php", "/home/bitrix/www/local/crm/include_deal_auto_take.php"),
]
SNIP = """
$waDealAutoTake = $_SERVER['DOCUMENT_ROOT'] . '/local/crm/include_deal_auto_take.php';
if (is_file($waDealAutoTake)) {
    require_once $waDealAutoTake;
}
"""

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=20)
sftp = c.open_sftp()
for rel, rem in FILES:
    sftp.put(os.path.join(ROOT, rel.replace("/", os.sep)), rem)
    print("up", rem, sftp.stat(rem).st_size)

init_path = "/home/bitrix/www/bitrix/php_interface/init.php"
with sftp.file(init_path, "r") as f:
    init = f.read().decode("utf-8", "replace")
if "include_deal_auto_take.php" not in init:
    if not init.endswith("\n"):
        init += "\n"
    init += "\n" + SNIP.strip() + "\n"
    with sftp.file(init_path, "w") as f:
        f.write(init)
    print("init patched")
else:
    print("init already has auto_take")
sftp.close()

php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true);
define("NOT_CHECK_PERMISSIONS",true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("crm");
require_once "/home/bitrix/www/local/crm/include_deal_uf_lock.php";
require_once "/home/bitrix/www/local/crm/include_deal_auto_take.php";

// find a deal without accountant take / employee for dry logic test using real load+merge simulation
$res = CCrmDeal::GetListEx(["ID"=>"DESC"], ["CHECK_PERMISSIONS"=>"N"], false, ["nTopCount"=>30], [
  "ID","UF_CRM_1784636341021","UF_CRM_1764332847245","UF_CRM_1785326361467","UF_CRM_1785324070",
  "UF_CRM_1783486791226","UF_CRM_1783485774093","UF_CRM_1785325552",
  "UF_CRM_1784524115744","UF_CRM_1787123174","UF_CRM_1787123117",
]);
$sample=null;
while($r=$res->Fetch()){ $sample=$r; break; }
echo "sample_id=".($sample["ID"]??0)."\n";

// unit: fake apply without needing real empty deal — monkey by calling helpers
$fields = ["ID"=>(int)($sample["ID"]??0), "UF_CRM_1784636341021"=>"914"];
// simulate user
global $USER;
if (!$USER || !$USER->IsAuthorized()) {
  echo "NO_USER_IN_CLI — logic helpers only\n";
}
echo "trigger_acc=". (waDealAutoTake_inValues("914",["914"])?"1":"0")."\n";
echo "taken_no=". (waDealAutoTake_isTakenYes("", "937")?"1":"0")."\n";
echo "emp_empty=". (waDealAutoTake_isFilledEmployee("")?"1":"0")."\n";
passthru("php -l /home/bitrix/www/local/crm/include_deal_auto_take.php");
echo (strpos(file_get_contents("/home/bitrix/www/bitrix/php_interface/init.php"),"include_deal_auto_take.php")!==false?"INIT_OK\n":"INIT_MISS\n");
echo "rules=".count(waDealAutoTake_rules())."\n";
'''
sftp=c.open_sftp()
with sftp.file("/tmp/wa_auto_take_v.php","w") as f: f.write(php)
sftp.close()
_,o,_=c.exec_command("php /tmp/wa_auto_take_v.php 2>&1", timeout=40)
print(o.read().decode("utf-8","replace")[:3000])
_,o,_=c.exec_command("tail -n 20 /home/bitrix/www/bitrix/php_interface/init.php")
print("TAIL:\n", o.read().decode("utf-8","replace"))
c.exec_command("rm -f /tmp/wa_auto_take_v.php")
c.close()
print("DONE")
