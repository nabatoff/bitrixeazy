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
$tables = $conn->query("SHOW TABLES LIKE '%voximplant%'");
echo "=== tables ===\n";
while ($t = $tables->fetch()) {
    echo implode(" | ", $t) . "\n";
}
foreach (["b_voximplant_sip", "b_voximplant_config", "b_voximplant_line"] as $table) {
    try {
        echo "\n=== $table ===\n";
        $cols = $conn->query("SHOW COLUMNS FROM $table");
        $names = [];
        while ($c = $cols->fetch()) {
            $names[] = $c["Field"];
        }
        echo "cols: " . implode(",", $names) . "\n";
        $res = $conn->query("SELECT * FROM $table");
        $n = 0;
        while ($row = $res->fetch()) {
            $n++;
            $safe = $row;
            foreach ($safe as $k => $v) {
                if (stripos($k, "PASS") !== false || stripos($k, "SECRET") !== false) {
                    $safe[$k] = $v === "" || $v === null ? "(empty)" : "(set:" . strlen((string)$v) . ")";
                }
            }
            echo json_encode($safe, JSON_UNESCAPED_UNICODE) . "\n";
            if ($n >= 40) {
                echo "... truncated\n";
                break;
            }
        }
    } catch (\Throwable $e) {
        echo "skip $table: " . $e->getMessage() . "\n";
    }
}
'''
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", key_filename=KEY, timeout=25)
sftp = c.open_sftp()
with sftp.file("/tmp/vi_sip.php", "w") as f:
    f.write(php)
sftp.close()
_, o, e = c.exec_command("php /tmp/vi_sip.php; rm -f /tmp/vi_sip.php", timeout=60)
sys.stdout.buffer.write(o.read())
sys.stderr.buffer.write(e.read())
c.close()
