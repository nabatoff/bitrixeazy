#!/usr/bin/env python3
"""Full verification for sales plan module."""
import paramiko, sys
KEY = sys.argv[1] if len(sys.argv) > 1 else r'C:\Users\15bit\.ssh\id_rsa_bitrix'
script = r'''<?php
$_SERVER['DOCUMENT_ROOT'] = '/home/bitrix/www';
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
\Bitrix\Main\Loader::includeModule('artflowers.salesplan');
\Bitrix\Main\Loader::includeModule('crm');

function testUser(int $uid): void {
    $access = new \Artflowers\Salesplan\Internal\AccessService($uid);
    $rs = \CUser::GetByID($uid);
    $u = $rs->Fetch();
    $name = $u ? trim($u['NAME'].' '.$u['LAST_NAME']) : '?';
    echo "USER $uid ($name) admin=" . ($access->isAdmin()?'Y':'N') . " read=" . ($access->canReadSaleTarget()?'Y':'N') . " edit=" . ($access->canEditSaleTarget()?'Y':'N') . " branch=" . ($access->resolveDefaultBranchId()?:'-') . "\n";
    foreach (\Artflowers\Salesplan\Config\BranchConfig::getAll() as $b) {
        $bid = (string)$b['id'];
        echo "  $bid view=" . ($access->canViewBranch($bid)?'Y':'N') . " edit=" . ($access->canEditBranchPlans($bid)?'Y':'N') . " head=" . ($access->isBranchHead($bid)?'Y':'N') . "\n";
    }
    if ($access->canReadSaleTarget()) {
        $bid = $access->resolveDefaultBranchId() ?: 'almaty';
        if ($access->canViewBranch($bid)) {
            $dash = \Artflowers\Salesplan\Internal\PlanRepository::buildDashboard($access, $bid, (int)date('Y'), (int)date('n'), 'all');
            echo "  dashboard $bid actual=" . $dash['branch']['actual'] . " managers=" . count($dash['managers']) . "\n";
        }
    }
}

foreach (['almaty','astana','uralsk'] as $bid) {
    $a = \Artflowers\Salesplan\Internal\ActualsService::fetchAggregated($bid, (int)date('Y'), (int)date('n'), 'all', null);
    echo "ACTUALS $bid sum={$a['sum']} cnt={$a['count']}\n";
}

testUser(1);
testUser(139808);
'''
c = paramiko.SSHClient(); c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect('crm.artflowers.kz', username='bitrix', key_filename=KEY, timeout=30)
sftp = c.open_sftp()
with sftp.open('/tmp/af_sp_verify3.php','w') as f: f.write(script.encode())
sftp.close()
_, o, e = c.exec_command('php /tmp/af_sp_verify3.php 2>&1', timeout=120)
print(o.read().decode()); print(e.read().decode())
c.close()
