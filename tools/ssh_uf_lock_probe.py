#!/usr/bin/env python3
import sys, paramiko
PASSWORD = sys.argv[1]
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=20)
cmds = [
    # events related to entity editor / field infos
    r"grep -rn \"onEntityDetails\|PrepareEditor\|editorConfig\|FieldInfos\|getFieldsInfo\" /home/bitrix/www/bitrix/modules/crm/lib/Component/EntityDetails --include='*.php' 2>/dev/null | head -40",
    r"grep -rn \"Event('crm'\|new Event('crm'\|GetModuleEvents('crm'\" /home/bitrix/www/bitrix/modules/crm/lib/Service/EditorAdapter.php 2>/dev/null | head -20",
    r"grep -n \"Event\|editable\|USER_FIELD\" /home/bitrix/www/bitrix/modules/crm/lib/Service/EditorAdapter.php | head -50",
    # confirm UF exist on deals
    r'''php -r '
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true); define("NOT_CHECK_PERMISSIONS",true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("crm");
$ids=["UF_CRM_1782797106378","UF_CRM_1785324070","UF_CRM_1785325552","UF_CRM_1787123117"];
global $USER_FIELD_MANAGER;
$fields=$USER_FIELD_MANAGER->GetUserFields("CRM_DEAL");
foreach($ids as $id){
  if(!isset($fields[$id])){ echo "$id MISSING\n"; continue; }
  $f=$fields[$id];
  echo $id." type=".$f["USER_TYPE_ID"]." label=".($f["EDIT_FORM_LABEL"]["ru"]??$f["LIST_COLUMN_LABEL"]["ru"]??"?")."\n";
}
echo "IsAdmin_api="; var_export(method_exists($GLOBALS["USER"]??null,"IsAdmin")); echo "\n";
' ''',
    # how IsAdmin is checked + existing DealStageGuard pattern
    r"head -80 /home/bitrix/www/bitrix/php_interface/classes/DealStageGuard.php 2>/dev/null",
    r"grep -rn 'editable.*false\|enableEditInView\|onCrmEntityDetails\|EntityEditor' /home/bitrix/www/bitrix/modules/crm/install/components/bitrix/crm.deal.details --include='*.php' 2>/dev/null | head -30",
]
for cmd in cmds:
    print("====")
    _, o, e = c.exec_command(cmd, timeout=60)
    print(o.read().decode("utf-8", "replace")[:3500])
    err = e.read().decode()
    if err.strip():
        print("ERR", err[:500])
c.close()
