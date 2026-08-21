#!/usr/bin/env python3
"""Debug why kanban paint doesn't show."""
import sys, paramiko, json

PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=20)

cmds = [
    # find deals from screenshot titles
    r'''php -r '
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true); define("NOT_CHECK_PERMISSIONS",true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("crm");
$titles=["Lalu Cake","Orange","Amilya","Амилия","Султан"];
$uf=["UF_CRM_1764332847245","UF_CRM_1783486791226","UF_CRM_1764577842986","UF_CRM_1784524115744"];
foreach($titles as $t){
  $res=CCrmDeal::GetListEx(["ID"=>"DESC"],["%TITLE"=>$t,"CHECK_PERMISSIONS"=>"N"],false,["nTopCount"=>5],array_merge(["ID","TITLE","STAGE_ID","CATEGORY_ID"],$uf));
  while($r=$res->Fetch()){
    echo $t." => #".$r["ID"]." cat=".$r["CATEGORY_ID"]." stage=".$r["STAGE_ID"]." title=".mb_substr($r["TITLE"],0,60)
      ." pre=".$r["UF_CRM_1764332847245"]." buy=".$r["UF_CRM_1783486791226"]." pay=".$r["UF_CRM_1764577842986"]." iss=".$r["UF_CRM_1784524115744"]."\n";
  }
}
' ''',
    "grep -n include_kanban /home/bitrix/www/bitrix/php_interface/init.php",
    "ls -la /home/bitrix/www/local/crm/",
    # how bitrix crm pages are routed - maybe SPA shell
    "ls /home/bitrix/www/crm/deal/ 2>/dev/null | head",
    "head -c 500 /home/bitrix/www/crm/deal/kanban/index.php 2>/dev/null; echo; ls /home/bitrix/www/crm/deal/kanban/ 2>/dev/null",
    # check if Asset addJs works on OnEpilog for crm
    "grep -n OnEpilog /home/bitrix/www/local/crm/include_kanban_deal_paint.php; grep -n OnEpilog /home/bitrix/www/local/custom_chat/include_crm_button.php | head -5",
    # look at card CSS that might override bg
    "grep -n 'main-kanban-item' /home/bitrix/www/bitrix/js/main/kanban/css/kanban.css 2>/dev/null | head -20; ls /home/bitrix/www/bitrix/js/main/kanban/css/ 2>/dev/null",
]
for cmd in cmds:
    print("===CMD===")
    _, o, e = c.exec_command(cmd, timeout=90)
    out = o.read().decode("utf-8", "replace")
    err = e.read().decode("utf-8", "replace")
    print(out[:5000])
    if err.strip():
        print("ERR", err[:1000])
c.close()
