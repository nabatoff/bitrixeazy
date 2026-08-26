#!/usr/bin/env python3
import os, sys, paramiko
PASSWORD=sys.argv[1]
c=paramiko.SSHClient(); c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
_,o,_=c.exec_command(r"""python3 - <<'PY'
from pathlib import Path
p=Path('/home/bitrix/www/bitrix/php_interface/classes/DealStageGuard.php')
text=p.read_text(encoding='utf-8', errors='replace')
keys=['bypass','skip','IsAdmin','field_perm','dsg_field','1764332847245','1786008089','onBeforeUpdateLegacy','modifyFields','SetFrom','automation','robot']
for k in keys:
    idxs=[]
    start=0
    while True:
        i=text.find(k, start)
        if i<0: break
        idxs.append(i)
        start=i+1
    print(f'--- {k} n={len(idxs)} ---')
    for i in idxs[:8]:
        line=text[:i].count('\n')+1
        snippet=text[max(0,i-80):i+180].replace('\n',' | ')
        print(f'L{line}: {snippet[:260]}')
print('\n==== RULES BLOCK 240-320 ====')
print('\n'.join(text.splitlines()[239:320]))
print('\n==== onBeforeUpdateLegacy ====')
# find function
i=text.find('function onBeforeUpdateLegacy')
print(text[i:i+2500])
PY""")
print(o.read().decode('utf-8','replace')[:16000])
c.close()
