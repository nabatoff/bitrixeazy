#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)

php = r'''<?php
$_SERVER["DOCUMENT_ROOT"] = "/home/bitrix/www";
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("im");
require_once "/home/bitrix/www/local/custom_chat/include_wa_quote_send.php";

echo "CIMMessenger::Add=" . (method_exists("CIMMessenger", "Add") ? 1 : 0) . "\n";
echo "CIMMessageParam::Set=" . (method_exists("CIMMessageParam", "Set") ? 1 : 0) . "\n";
echo "CIMMessageParam::SendPull=" . (method_exists("CIMMessageParam", "SendPull") ? 1 : 0) . "\n";
echo "fn EchoToIm=" . (function_exists("waCcQuoteSendEchoToIm") ? 1 : 0) . "\n";
echo "fn ResolveMids=" . (function_exists("waCcQuoteSendResolveMids") ? 1 : 0) . "\n";
echo "fn Handle=" . (function_exists("waCcQuoteSendHandle") ? 1 : 0) . "\n";

/* нормализация хвоста _c */
foreach (["3EB0EB09D518A50B527DD5_c", "3EB0EB09D518A50B527DD5", "ACE25D8B72F9FAE6E54F8DED552E6B70"] as $t) {
	echo "pick($t) = " . waCcQuoteSendPickMid($t) . "\n";
}
'''
sftp = c.open_sftp()
with sftp.file("/tmp/wa_imapi.php", "w") as f:
    f.write(php)
sftp.close()
_, o, e = c.exec_command("php /tmp/wa_imapi.php 2>&1; rm -f /tmp/wa_imapi.php", timeout=90)
sys.stdout.buffer.write(o.read()[:6000])
sys.stdout.buffer.write(e.read()[:2000])
c.close()
