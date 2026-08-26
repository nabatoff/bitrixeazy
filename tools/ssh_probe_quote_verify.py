#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
cmd = r"""
php -l /home/bitrix/www/local/custom_chat/include_wa_quote_send.php
php -l /home/bitrix/www/local/custom_chat/index.php
php -l /home/bitrix/www/local/custom_chat/mobile.php
grep -n "wa_quote_send\|sendQuotedViaGreen\|waCcQuoteSendHandle\|quotedMessageId\|sendPTTByUpload\|sendFileByUpload" \
  /home/bitrix/www/local/custom_chat/include_wa_quote_send.php \
  /home/bitrix/www/local/custom_chat/index.php \
  /home/bitrix/www/local/custom_chat/mobile.php | head -40
# light CONNECTOR_MID sample
php -r '
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",1); define("NOT_CHECK_PERMISSIONS",1);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("im");
$conn=\Bitrix\Main\Application::getConnection();
$r=$conn->query("SELECT COUNT(*) CNT FROM b_im_message_param WHERE PARAM_NAME=\"CONNECTOR_MID\" AND PARAM_VALUE!=\"\" AND MESSAGE_ID>(SELECT MAX(ID)-5000 FROM b_im_message)");
$row=$r->fetch();
echo "CONNECTOR_MID filled in last ~5k msgs: ".($row["CNT"]??0)."\n";
$r=$conn->query("SELECT MESSAGE_ID, LEFT(PARAM_VALUE,40) V FROM b_im_message_param WHERE PARAM_NAME=\"CONNECTOR_MID\" AND PARAM_VALUE!=\"\" ORDER BY MESSAGE_ID DESC LIMIT 5");
while($x=$r->fetch()) echo "mid ".$x["MESSAGE_ID"]." = ".$x["V"]."\n";
'
"""
_, o, e = c.exec_command(cmd, timeout=90)
sys.stdout.buffer.write(o.read())
sys.stdout.buffer.write(e.read())
c.close()
