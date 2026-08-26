#!/usr/bin/env python3
import sys
import paramiko

KEY = sys.argv[1] if len(sys.argv) > 1 else r"C:\Users\15bit\.ssh\id_rsa_bitrix"
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="dockeradm", key_filename=KEY, timeout=25)

def run(cmd, timeout=40):
    _, o, e = c.exec_command(cmd, timeout=timeout)
    return o.read().decode("utf-8", "replace") + e.read().decode("utf-8", "replace")

print(run("docker exec asterisk-beeline asterisk -rx 'pjsip set logger off'"))
print("=== registrations ===")
print(run("docker exec asterisk-beeline asterisk -rx 'pjsip show registrations'"))
print("=== contacts ===")
print(run("docker exec asterisk-beeline asterisk -rx 'pjsip show contacts'"))
print("=== ps ===")
print(run("docker ps --filter name=asterisk-beeline --format '{{.Names}} {{.Status}}'"))
print("=== udp ===")
print(run("netstat -lun | grep 5060 || true"))
sftp = c.open_sftp()
sftp.put(r"d:\project\bitrixeazy\tools\asterisk\README.md", "/home/dockeradm/asterisk/README.md")
sftp.close()
c.close()

php = """<?php
$_SERVER['DOCUMENT_ROOT']='/home/bitrix/www';
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
require '/home/bitrix/www/bitrix/modules/main/include/prolog_before.php';
$r=\\Bitrix\\Main\\Application::getConnection()->query('SELECT ID,LOGIN,SERVER,TYPE FROM b_voximplant_sip WHERE ID IN (35,36)');
while($x=$r->fetch()) echo $x['ID'].' '.$x['LOGIN'].' '.$x['SERVER'].' '.$x['TYPE'].PHP_EOL;
"""
c2 = paramiko.SSHClient()
c2.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c2.connect("crm.artflowers.kz", username="bitrix", key_filename=KEY, timeout=25)
sftp = c2.open_sftp()
with sftp.file("/tmp/vi_check.php", "w") as fh:
    fh.write(php)
sftp.close()
_, o, e = c2.exec_command("php /tmp/vi_check.php; rm -f /tmp/vi_check.php", timeout=40)
print("=== bitrix sip ===")
sys.stdout.buffer.write(o.read())
sys.stderr.buffer.write(e.read())
c2.close()
