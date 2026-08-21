#!/usr/bin/env python3
import os, sys, paramiko

PASSWORD = sys.argv[1]
ROOT = os.path.normpath(os.path.join(os.path.dirname(__file__), ".."))
FILES = [
    ("local/crm/include_deal_uf_lock.php", "/home/bitrix/www/local/crm/include_deal_uf_lock.php"),
    ("local/crm/deal_uf_lock.js", "/home/bitrix/www/local/crm/deal_uf_lock.js"),
]
INIT_SNIP = """
$waDealUfLock = $_SERVER['DOCUMENT_ROOT'] . '/local/crm/include_deal_uf_lock.php';
if (is_file($waDealUfLock)) {
    require_once $waDealUfLock;
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
if "include_deal_uf_lock.php" not in init:
    if not init.endswith("\n"):
        init += "\n"
    init += "\n" + INIT_SNIP.strip() + "\n"
    with sftp.file(init_path, "w") as f:
        f.write(init)
    print("init patched")
else:
    print("init already ok")
sftp.close()

php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true);
define("NOT_CHECK_PERMISSIONS",true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
require_once "/home/bitrix/www/local/crm/include_deal_uf_lock.php";
echo "fields=".implode(",", waDealUfLock_fields())."\n";
echo "admin_fn=". (function_exists("waDealUfLock_isPortalAdmin")?"1":"0")."\n";
$f=["ID"=>1,"TITLE"=>"x","UF_CRM_1785324070"=>5,"OPPORTUNITY"=>10];
waDealUfLock_stripFields($f);
echo "stripped=".json_encode($f)."\n";
passthru("php -l /home/bitrix/www/local/crm/include_deal_uf_lock.php");
echo (strpos(file_get_contents("/home/bitrix/www/bitrix/php_interface/init.php"),"include_deal_uf_lock.php")!==false?"INIT_OK\n":"INIT_MISS\n");
'''
sftp=c.open_sftp()
with sftp.file("/tmp/wa_uf_lock_v.php","w") as f: f.write(php)
sftp.close()
_,o,_=c.exec_command("php /tmp/wa_uf_lock_v.php 2>&1", timeout=40)
print(o.read().decode("utf-8","replace")[:2500])
c.exec_command("rm -f /tmp/wa_uf_lock_v.php")
c.close()
print("DONE")
