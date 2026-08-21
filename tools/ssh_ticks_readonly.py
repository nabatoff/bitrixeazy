#!/usr/bin/env python3
"""Read-only probe of wa_ticks store + how statuses look."""
import sys, json, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=20)

cmds = [
    "ls -la /home/bitrix/www/local/custom_chat/var/wa_ticks*.json 2>/dev/null",
    "python3 - <<'PY'\nimport json\np='/home/bitrix/www/local/custom_chat/var/wa_ticks.json'\nd=json.load(open(p))\nprint('entries',len(d))\nfrom collections import Counter\nc=Counter((v.get('status') if isinstance(v,dict) else '?') for v in d.values())\nprint('by_status',dict(c))\n# sample 15 newest by ts\nrows=[]\nfor k,v in d.items():\n  if isinstance(v,dict): rows.append((int(v.get('ts') or 0),k,v))\nrows.sort(reverse=True)\nfor ts,k,v in rows[:20]:\n  print(ts,k,v.get('status'),v.get('idMessage','')[:24],v.get('chatId',''))\nPY",
    # check if webhook path applies ticks
    "grep -rn waCcTicksApplyWebhook /home/bitrix/www/local/custom_chat --include='*.php' | head -20",
    "grep -n 'waChatTickStatus\\|opponentReadMessageId\\|refreshReadReceipts' /home/bitrix/www/local/custom_chat/index.php | head -40",
]
for cmd in cmds:
    print("====")
    _, o, e = c.exec_command(cmd, timeout=40)
    print(o.read().decode("utf-8", "replace")[:5000])
    err = e.read().decode("utf-8", "replace")
    if err.strip():
        print("ERR", err[:800])
c.close()
