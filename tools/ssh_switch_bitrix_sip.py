#!/usr/bin/env python3
"""Switch Bitrix office SIP 3888/8099 outgoing server to Asterisk. Pass --rollback to restore Beeline."""
from __future__ import annotations

import sys

import paramiko

KEY = sys.argv[1] if len(sys.argv) > 1 else r"C:\Users\15bit\.ssh\id_rsa_bitrix"
rollback = "--rollback" in sys.argv[2:]
server = "46.227.186.231:6050" if rollback else "185.253.8.33:5060"

php = r'''<?php
$_SERVER["DOCUMENT_ROOT"] = "/home/bitrix/www";
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("voximplant");
$newServer = "__SERVER__";
$ids = [35, 36];
echo "target=" . $newServer . "\n";
foreach ($ids as $id) {
    $row = null;
    if (class_exists("\\Bitrix\\Voximplant\\Model\\SipTable")) {
        $row = \Bitrix\Voximplant\Model\SipTable::getById($id)->fetch();
    } elseif (class_exists("\\Bitrix\\Voximplant\\SipTable")) {
        $row = \Bitrix\Voximplant\SipTable::getById($id)->fetch();
    }
    if (!$row) {
        $conn = \Bitrix\Main\Application::getConnection();
        $row = $conn->query("SELECT * FROM b_voximplant_sip WHERE ID=" . (int)$id)->fetch();
    }
    if (!$row) {
        echo "missing id=$id\n";
        continue;
    }
    echo "before id=$id login=" . $row["LOGIN"] . " server=" . $row["SERVER"] . " type=" . $row["TYPE"] . "\n";
    $ok = false;
    $err = "";
    if (class_exists("CVoxImplantSip")) {
        try {
            $sip = new \CVoxImplantSip();
            $payload = $row;
            $payload["ID"] = (int)$id;
            $payload["SERVER"] = $newServer;
            $res = $sip->Update($payload);
            $ok = (bool)$res;
            if (!$ok) {
                $err = method_exists($sip, "GetError") ? (string)$sip->GetError() : "CVoxImplantSip::Update false";
            }
        } catch (\Throwable $e) {
            $err = $e->getMessage();
        }
    }
    if (!$ok) {
        $conn = \Bitrix\Main\Application::getConnection();
        $conn->queryExecute(
            "UPDATE b_voximplant_sip SET SERVER='" . $conn->getSqlHelper()->forSql($newServer) . "' WHERE ID=" . (int)$id
        );
        $ok = true;
        $err = $err !== "" ? ($err . " | sql-fallback") : "sql";
    }
    $after = \Bitrix\Main\Application::getConnection()
        ->query("SELECT ID, LOGIN, SERVER, TYPE FROM b_voximplant_sip WHERE ID=" . (int)$id)
        ->fetch();
    echo "after id=$id login=" . $after["LOGIN"] . " server=" . $after["SERVER"] . " ok=" . ($ok ? "yes" : "no") . " via=" . $err . "\n";
}
'''
php = php.replace("__SERVER__", server.replace("\\", "\\\\"))

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", key_filename=KEY, timeout=25)
sftp = c.open_sftp()
with sftp.file("/tmp/vi_switch_sip.php", "w") as fh:
    fh.write(php)
sftp.close()
_, o, e = c.exec_command("php /tmp/vi_switch_sip.php; rm -f /tmp/vi_switch_sip.php", timeout=60)
sys.stdout.buffer.write(o.read())
sys.stderr.buffer.write(e.read())
c.close()
