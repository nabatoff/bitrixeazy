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

print("=== pjsip errors ===")
print(run("docker logs asterisk-beeline 2>&1 | grep -iE 'pjsip.conf|registration|invalid|unknown option|failed to create' | tail -50"))
print("=== show registrations verbose ===")
print(run("docker exec asterisk-beeline asterisk -rx 'pjsip show registrations'"))
print(run("docker exec asterisk-beeline asterisk -rx 'config show pjsip' | head"))
print("=== grep registration in conf ===")
print(run("grep -n 'type=registration\\|line=\\|outbound_proxy\\|auth_rejection' /home/dockeradm/asterisk/config/pjsip.conf"))
c.close()
