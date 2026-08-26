#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)

cmds = [
    "ls -la /home/bitrix/www/bitrix/templates/bitrix24/src/js/left-menu.js /home/bitrix/www/bitrix/templates/bitrix24/js/ 2>/dev/null | head",
    "find /home/bitrix/www/bitrix/templates/bitrix24 -name '*left-menu*' -o -name '*leftmenu*' 2>/dev/null | head",
    "grep -n 'updateCounters\\|menu-item-index\\|menu_app' /home/bitrix/www/bitrix/templates/bitrix24/src/js/left-menu.js 2>/dev/null | head -30",
    "wc -c /home/bitrix/www/bitrix/templates/bitrix24/src/js/left-menu.js; head -c 200 /home/bitrix/www/bitrix/templates/bitrix24/src/js/left-menu.js",
    "grep -n 'function getList\\|public static function getList' /home/bitrix/www/bitrix/modules/im/lib/recent.php | head",
    "sed -n '1,80p' /home/bitrix/www/bitrix/modules/im/lib/recent.php | head -80",
]
for cmd in cmds:
    print("===", cmd[:90])
    _, o, e = c.exec_command(cmd, timeout=20)
    sys.stdout.buffer.write(o.read()[:2500])
    err = e.read()[:400]
    if err: sys.stdout.buffer.write(err)

php = r'''<?php
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true); define("NOT_CHECK_PERMISSIONS",true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("im");
$ref = new ReflectionMethod("\\Bitrix\\Im\\Recent", "getList");
echo "Recent::getList ".$ref->getNumberOfParameters()." params\n";
foreach ($ref->getParameters() as $p) {
  echo " - ".$p->getName()."\n";
}
$src = file_get_contents($ref->getFileName());
$start = $ref->getStartLine();
$lines = array_slice(explode("\n", $src), $start-1, 40);
echo implode("\n", $lines)."\n";
'''
sftp = c.open_sftp()
with sftp.file("/tmp/wa_recent_sig.php", "w") as f:
    f.write(php)
sftp.close()
print("=== Recent::getList sig ===")
_, o, _ = c.exec_command("php /tmp/wa_recent_sig.php 2>&1; rm -f /tmp/wa_recent_sig.php", timeout=40)
sys.stdout.buffer.write(o.read()[:4000])
c.close()
