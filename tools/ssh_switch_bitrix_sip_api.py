#!/usr/bin/env python3
import sys
import paramiko

KEY = sys.argv[1] if len(sys.argv) > 1 else r"C:\Users\15bit\.ssh\id_rsa_bitrix"
php = r'''<?php
$_SERVER["DOCUMENT_ROOT"] = "/home/bitrix/www";
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("voximplant");
$sip = new \CVoxImplantSip();
foreach ([35, 36] as $id) {
    $ok = $sip->Update($id, ["SERVER" => "185.253.8.33:5060"]);
    $err = "";
    if (!$ok && isset($sip->error) && is_object($sip->error)) {
        $err = $sip->error->msg . " / " . $sip->error->code;
    }
    $row = \Bitrix\Voximplant\SipTable::getList(["filter" => ["=CONFIG_ID" => $id]])->fetch();
    echo "id=$id ok=" . ($ok ? "yes" : "no") . " err=$err server=" . ($row["SERVER"] ?? "") . "\n";
}
'''
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", key_filename=KEY, timeout=25)
sftp = c.open_sftp()
with sftp.file("/tmp/vi_sip_upd.php", "w") as f:
    f.write(php)
sftp.close()
_, o, e = c.exec_command("php /tmp/vi_sip_upd.php; rm -f /tmp/vi_sip_upd.php", timeout=60)
sys.stdout.buffer.write(o.read())
sys.stderr.buffer.write(e.read())
c.close()
