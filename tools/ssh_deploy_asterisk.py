#!/usr/bin/env python3
"""Deploy Asterisk PBX for Beeline office lines 3888/8099."""
from __future__ import annotations

import os
import stat
import sys
import time

import paramiko

HOST = "crm.artflowers.kz"
USER = "dockeradm"
KEY_PATH = sys.argv[1] if len(sys.argv) > 1 else r"C:\Users\15bit\.ssh\id_rsa_bitrix"
REMOTE = "/home/dockeradm/asterisk"
LOCAL = os.path.normpath(os.path.join(os.path.dirname(__file__), "asterisk"))

BEELINE_PASSWORD = os.environ.get("BEELINE_PASSWORD", "Uralsk2026")
B24_SIP35_PASSWORD = os.environ.get("B24_SIP35_PASSWORD", "7ea7151409024662171e397654b1f53b")
B24_SIP36_PASSWORD = os.environ.get("B24_SIP36_PASSWORD", "bbc06fba8ac4c718c10cb5d9b0b64377")


def run(client: paramiko.SSHClient, cmd: str, timeout: int = 180) -> tuple[int, str, str]:
    stdin, stdout, stderr = client.exec_command(cmd, timeout=timeout)
    out = stdout.read().decode("utf-8", "replace")
    err = stderr.read().decode("utf-8", "replace")
    code = stdout.channel.recv_exit_status()
    return code, out, err


def put_dir(sftp: paramiko.SFTPClient, local: str, remote: str) -> None:
    try:
        sftp.mkdir(remote)
    except OSError:
        pass
    for name in os.listdir(local):
        lp = os.path.join(local, name)
        rp = remote + "/" + name
        if os.path.isdir(lp):
            put_dir(sftp, lp, rp)
        else:
            sftp.put(lp, rp)


def main() -> int:
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(HOST, username=USER, key_filename=KEY_PATH, timeout=25)
    sftp = client.open_sftp()

    for d in (REMOTE, REMOTE + "/config", REMOTE + "/logs"):
        try:
            sftp.mkdir(d)
        except OSError:
            pass

    for rel in (
        "docker-compose.yml",
        "config/asterisk.conf",
        "config/modules.conf",
        "config/rtp.conf",
        "config/logger.conf",
        "config/extensions.conf",
    ):
        sftp.put(os.path.join(LOCAL, rel.replace("/", os.sep)), REMOTE + "/" + rel)

    with open(os.path.join(LOCAL, "config", "pjsip.conf.tpl"), encoding="utf-8") as fh:
        tpl = fh.read()
    pjsip = (
        tpl.replace("${BEELINE_PASSWORD}", BEELINE_PASSWORD)
        .replace("${B24_SIP35_PASSWORD}", B24_SIP35_PASSWORD)
        .replace("${B24_SIP36_PASSWORD}", B24_SIP36_PASSWORD)
    )
    with sftp.file(REMOTE + "/config/pjsip.conf", "w") as fh:
        fh.write(pjsip)
    sftp.chmod(REMOTE + "/config/pjsip.conf", stat.S_IRUSR | stat.S_IWUSR)

    env = (
        "PUBLIC_IP=185.253.8.33\n"
        "BEELINE_HOST=46.227.186.231\n"
        "BEELINE_PORT=6050\n"
        f"BEELINE_PASSWORD={BEELINE_PASSWORD}\n"
        f"B24_SIP35_PASSWORD={B24_SIP35_PASSWORD}\n"
        f"B24_SIP36_PASSWORD={B24_SIP36_PASSWORD}\n"
        "B24_HOST=ip.b24-7297-1638417655.bitrixphone.com\n"
    )
    with sftp.file(REMOTE + "/.env", "w") as fh:
        fh.write(env)
    sftp.chmod(REMOTE + "/.env", stat.S_IRUSR | stat.S_IWUSR)
    sftp.close()

    reload_only = "--reload" in sys.argv[2:]
    cmds = [
        f"chmod 700 {REMOTE} {REMOTE}/config {REMOTE}/logs",
        f"chmod 600 {REMOTE}/.env {REMOTE}/config/pjsip.conf",
        f"chmod 644 {REMOTE}/docker-compose.yml "
        f"{REMOTE}/config/asterisk.conf {REMOTE}/config/modules.conf "
        f"{REMOTE}/config/rtp.conf {REMOTE}/config/logger.conf "
        f"{REMOTE}/config/extensions.conf",
    ]
    if reload_only:
        cmds.append(
            "docker exec asterisk-beeline asterisk -rx 'module reload res_pjsip.so' ; "
            "docker exec asterisk-beeline asterisk -rx 'module reload res_pjsip_outbound_registration.so' ; "
            "docker exec asterisk-beeline asterisk -rx 'dialplan reload'"
        )
    else:
        cmds.extend([
            f"cd {REMOTE} && docker compose pull",
            f"cd {REMOTE} && docker compose up -d --force-recreate",
        ])
    for cmd in cmds:
        print(">>>", cmd if "PASSWORD" not in cmd else cmd.split("&&")[0] + "&& ...")
        code, out, err = run(client, cmd, timeout=300)
        sys.stdout.write(out)
        if err.strip():
            sys.stderr.write(err)
        if code != 0:
            print("FAILED", code, cmd)
            client.close()
            return code

    for _ in range(20):
        time.sleep(3)
        code, out, err = run(client, "docker ps --filter name=asterisk-beeline --format '{{.Status}}'")
        print("status:", out.strip() or err.strip())
        if "Up" in out:
            break

    checks = [
        "docker exec asterisk-beeline asterisk -rx 'core show version'",
        "docker exec asterisk-beeline asterisk -rx 'pjsip show registrations'",
        "docker exec asterisk-beeline asterisk -rx 'pjsip show endpoints'",
        "docker exec asterisk-beeline asterisk -rx 'pjsip show transports'",
        "docker logs --tail 80 asterisk-beeline",
    ]
    for cmd in checks:
        print(">>>", cmd)
        code, out, err = run(client, cmd, timeout=60)
        sys.stdout.write(out)
        if err.strip():
            sys.stderr.write(err[:2000])
        print()

    client.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
