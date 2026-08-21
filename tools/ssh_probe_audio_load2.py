#!/usr/bin/env python3
import paramiko
import sys

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=sys.argv[1], timeout=25)

cmds = [
    "which ffmpeg; ffmpeg -version 2>&1 | head -2",
    "ls -la /home/bitrix/www/upload/imconnector/4fe/v01o1l6gc8e5eqs0d6pnzqx7wpttqk1v/ 2>/dev/null | head -5",
    # create a tiny PHP that streams media as if mobile.php with MEDIA_AUTHED
]

php = r"""<?php
$_SERVER['DOCUMENT_ROOT'] = '/home/bitrix/www';
$_GET['wa_media'] = '3748162';
$_GET['wa_aid'] = 'test';
$GLOBALS['WA_CC_MEDIA_AUTHED'] = true;
$GLOBALS['WA_CC_FORCED_USER_ID'] = 1;
// bypass mobile auth: call stream path directly
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
$fileId = 3748162;
$fileArray = CFile::GetFileArray($fileId);
$path = $fileArray ? ($_SERVER['DOCUMENT_ROOT'] . CFile::GetPath($fileId)) : '';
echo "file=".json_encode($fileArray?['ID'=>$fileArray['ID'],'CONTENT_TYPE'=>$fileArray['CONTENT_TYPE'],'FILE_NAME'=>$fileArray['FILE_NAME'],'SRC'=>$fileArray['SRC']]:null)."\n";
echo "path=$path exists=".(is_file($path)?'Y':'N')."\n";
if (is_file($path)) {
  $fh = fopen($path, 'rb');
  $magic = bin2hex(fread($fh, 8));
  fclose($fh);
  echo "magic=$magic\n";
}
echo "ffmpeg=";
passthru('which ffmpeg 2>/dev/null');
"""

sftp = c.open_sftp()
with sftp.file("/tmp/probe_media2.php", "w") as f:
    f.write(php)
sftp.close()

for cmd in [
    "php /tmp/probe_media2.php 2>&1 | head -40",
    "which ffmpeg; ffmpeg -version 2>&1 | head -2",
    # curl media through mobile.php WITHOUT aid -> expect 401 plaintext
    "curl -sI 'https://crm.artflowers.kz/local/custom_chat/mobile.php?wa_media=3748162' | head -20",
    "curl -sI 'https://crm.artflowers.kz/local/custom_chat/index.php?wa_media=3748162' | head -20",
]:
    print("===", cmd)
    _, o, _ = c.exec_command(cmd, timeout=40)
    print(o.read().decode("utf-8", "replace")[:2500])

c.exec_command("rm -f /tmp/probe_media2.php")
c.close()
