#!/usr/bin/env python3
import sys
import paramiko

KEY = sys.argv[1] if len(sys.argv) > 1 else r"C:\Users\15bit\.ssh\id_rsa_bitrix"
php = r'''<?php
$_SERVER["DOCUMENT_ROOT"] = "/home/bitrix/www";
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
$conn = \Bitrix\Main\Application::getConnection();
echo "=== sip 35/36 extra ===\n";
$r = $conn->query("SELECT ID,CONFIG_ID,TYPE,SERVER,LOGIN,AUTH_USER,OUTBOUND_PROXY,REGISTRATION_STATUS_CODE,REGISTRATION_ERROR_MESSAGE FROM b_voximplant_sip WHERE ID IN (35,36)");
while ($x = $r->fetch()) print_r($x);
echo "\n=== option/search 3075/3888/8099 ===\n";
$opt = $conn->query("SELECT NAME, LEFT(VALUE,200) V FROM b_option WHERE MODULE_ID='voximplant' AND (NAME LIKE '%sip%' OR VALUE LIKE '%3075%' OR VALUE LIKE '%3888%' OR VALUE LIKE '%CLOUDPBX%') LIMIT 40");
while ($x = $opt->fetch()) echo $x["NAME"]."=".$x["V"]."\n";
echo "\n=== distinct servers ===\n";
$s = $conn->query("SELECT DISTINCT SERVER, OUTBOUND_PROXY FROM b_voximplant_sip");
while ($x = $s->fetch()) echo $x["SERVER"]." proxy=".$x["OUTBOUND_PROXY"]."\n";
echo "\n=== config names 35/36 ===\n";
$c = $conn->query("SELECT ID, SEARCH_ID, PHONE_NAME, QUEUE_ID FROM b_voximplant_config WHERE ID IN (35,36)");
while ($x = $c->fetch()) print_r($x);
'''
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", key_filename=KEY, timeout=25)
sftp = c.open_sftp()
with sftp.file("/tmp/vi_sip2.php", "w") as f:
    f.write(php)
sftp.close()
_, o, e = c.exec_command("php /tmp/vi_sip2.php; rm -f /tmp/vi_sip2.php", timeout=40)
sys.stdout.buffer.write(o.read())
sys.stderr.buffer.write(e.read())

c2 = paramiko.SSHClient()
c2.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c2.connect("crm.artflowers.kz", username="dockeradm", key_filename=KEY, timeout=25)
for name in [
    "vpbx-company-3075.CLOUDPBX.BEELINE.KZ",
    "vpbx-company-3075.cloudpbx.beeline.kz",
    "vpbx-company-2882.CLOUDPBX.BEELINE.KZ",
    "vpbx-company-3073.CLOUDPBX.BEELINE.KZ",
    "vpbx-company-3074.CLOUDPBX.BEELINE.KZ",
    "vpbx-company-3076.CLOUDPBX.BEELINE.KZ",
]:
    _, o2, _ = c2.exec_command("getent hosts " + name + "; echo ---", timeout=15)
    print(o2.read().decode("utf-8", "replace").strip())
c2.close()
c.close()
