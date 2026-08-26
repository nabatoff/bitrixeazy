#!/usr/bin/env python3
import sys, paramiko
PASSWORD=sys.argv[1]
c=paramiko.SSHClient(); c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("crm.artflowers.kz", username="bitrix", password=PASSWORD, timeout=25)
_,o,_=c.exec_command(r"""php -r '
$_SERVER["DOCUMENT_ROOT"]="/home/bitrix/www";
define("NO_KEEP_STATISTIC",true); define("NOT_CHECK_PERMISSIONS",true);
require "/home/bitrix/www/bitrix/modules/main/include/prolog_before.php";
$c="\\Bitrix\\Main\\ORM\\EventResult";
echo "class=$c exists=".(class_exists($c)?"Y":"N")."\n";
if(class_exists($c)){
  $r=new ReflectionClass($c);
  foreach($r->getMethods() as $m){
    if(stripos($m->getName(),"field")!==false || stripos($m->getName(),"modif")!==false || stripos($m->getName(),"unset")!==false){
      echo $m->getName()."\n";
    }
  }
  echo "parent=".$r->getParentClass()->getName()."\n";
}
$c2="\\Bitrix\\Main\\Entity\\EventResult";
echo "entity exists=".(class_exists($c2)?"Y":"N")."\n";
'
""")
print(o.read().decode("utf-8","replace"))
c.close()
