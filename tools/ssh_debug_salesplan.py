#!/usr/bin/env python3
import paramiko, sys
KEY = sys.argv[1] if len(sys.argv) > 1 else r'C:\Users\15bit\.ssh\id_rsa_bitrix'
script = r'''<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
$_SERVER['DOCUMENT_ROOT'] = '/home/bitrix/www';
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
\Bitrix\Main\Loader::includeModule('artflowers.salesplan');
\Bitrix\Main\Loader::includeModule('crm');
try {
    $a = \Artflowers\Salesplan\Internal\ActualsService::fetchAggregated('almaty', 2026, 8, 'all', null);
    echo json_encode($a, JSON_UNESCAPED_UNICODE) . "\n";
    $access = new \Artflowers\Salesplan\Internal\AccessService(139808);
    echo 'perms read=' . ($access->canReadSaleTarget() ? 'Y' : 'N') . "\n";
    $dash = \Artflowers\Salesplan\Internal\PlanRepository::buildDashboard($access, 'almaty', 2026, 8, 'all');
    echo 'managers=' . count($dash['managers']) . ' actual=' . $dash['branch']['actual'] . "\n";
} catch (Throwable $e) {
    echo 'ERR: ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString() . "\n";
}
'''
c = paramiko.SSHClient(); c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect('crm.artflowers.kz', username='bitrix', key_filename=KEY, timeout=30)
sftp = c.open_sftp()
with sftp.open('/tmp/af_sp_debug.php','w') as f: f.write(script.encode())
sftp.close()
_, o, e = c.exec_command('php /tmp/af_sp_debug.php 2>&1', timeout=120)
print(o.read().decode()); print(e.read().decode())
c.close()
