#!/usr/bin/env python3
import sys
import time
import paramiko

KEY = sys.argv[1] if len(sys.argv) > 1 else r"C:\Users\15bit\.ssh\id_rsa_bitrix"
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="dockeradm", key_filename=KEY, timeout=25)

def run(cmd, timeout=40):
    _, o, e = c.exec_command(cmd, timeout=timeout)
    return o.read().decode("utf-8", "replace") + e.read().decode("utf-8", "replace")

print(run("docker exec asterisk-beeline asterisk -rx 'pjsip set logger on'"))
print(run("docker exec asterisk-beeline asterisk -rx 'pjsip send register beeline-3888-reg'"))
time.sleep(6)
print(run("docker logs --since 15s asterisk-beeline 2>&1 | tail -160"))
print("=== status ===")
print(run("docker exec asterisk-beeline asterisk -rx 'pjsip show registrations'"))
c.close()
