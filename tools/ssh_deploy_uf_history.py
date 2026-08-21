#!/usr/bin/env python3
import os, sys, paramiko

PASSWORD = sys.argv[1]
ROOT = os.path.normpath(os.path.join(os.path.dirname(__file__), ".."))
FILES = [
    ("local/crm/include_deal_uf_history.php", "/home/bitrix/www/local/crm/include_deal_uf_history.php"),
]
SNIP = """
$waDealUfHist = $_SERVER['DOCUMENT_ROOT'] . '/local/crm/include_deal_uf_history.php';
if (is_file($waDealUfHist)) {
    require_once $waDealUfHist;
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
if "include_deal_uf_history.php" not in init:
    if not init.endswith("\n"):
        init += "\n"
    init += "\n" + SNIP.strip() + "\n"
    with sftp.file(init_path, "w") as f:
        f.write(init)
    print("init patched")
else:
    print("init ok")
sftp.close()

php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true); define("NOT_CHECK_PERMISSIONS",true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("crm");
require_once "/home/bitrix/www/local/crm/include_deal_uf_history.php";
passthru("php -l /home/bitrix/www/local/crm/include_deal_uf_history.php");
echo "fields=".count(waDealUfHistory_fields())."\n";
$meta=waDealUfHistory_loadMeta();
foreach(waDealUfHistory_fields() as $f){
  echo $f." => ".($meta[$f]["label"]??"?")." type=".($meta[$f]["type"]??"?")."\n";
}
echo "cap_empty=".waDealUfHistory_caption("UF_CRM_1764332847245","")."\n";
echo "cap_yes=".waDealUfHistory_caption("UF_CRM_1764332847245","1")."\n";
echo "cap_enum=".waDealUfHistory_caption("UF_CRM_1784636341021","914")."\n";
echo "cap_req=".waDealUfHistory_caption("UF_CRM_1784802244742","924")."\n";
echo (strpos(file_get_contents("/home/bitrix/www/bitrix/php_interface/init.php"),"include_deal_uf_history.php")!==false?"INIT_OK\n":"INIT_MISS\n");
'''
sftp=c.open_sftp()
with sftp.file("/tmp/wa_ufh_v.php","w") as f: f.write(php)
sftp.close()
_,o,_=c.exec_command("php /tmp/wa_ufh_v.php > /tmp/wa_ufh_v.txt 2>&1")
print(o.read().decode())
sftp=c.open_sftp()
sftp.get("/tmp/wa_ufh_v.txt", os.path.join(ROOT,"tools","_ufh_verify.txt"))
sftp.close()
print(open(os.path.join(ROOT,"tools","_ufh_verify.txt"),encoding="utf-8").read())
_,o,_=c.exec_command("tail -n 16 /home/bitrix/www/bitrix/php_interface/init.php")
print("TAIL:\n", o.read().decode("utf-8","replace"))
c.exec_command("rm -f /tmp/wa_ufh_v.php /tmp/wa_ufh_v.txt /tmp/wa_uf_hist.php /tmp/wa_uf_hist.txt")
c.close()
print("DONE")
