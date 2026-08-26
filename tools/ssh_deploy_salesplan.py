#!/usr/bin/env python3
"""Deploy and install artflowers.salesplan module on portal."""
from __future__ import annotations

import os
import sys
import paramiko

HOST = 'crm.artflowers.kz'
USER = 'bitrix'
KEY = sys.argv[1] if len(sys.argv) > 1 else r'C:\Users\15bit\.ssh\id_rsa_bitrix'
LOCAL = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
REMOTE_WWW = '/home/bitrix/www'

INSTALL_PHP = r'''<?php
$_SERVER['DOCUMENT_ROOT'] = '/home/bitrix/www';
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
if (!\Bitrix\Main\Loader::includeModule('main')) { echo "no main\n"; exit(1); }
$moduleId = 'artflowers.salesplan';
if (\Bitrix\Main\ModuleManager::isModuleInstalled($moduleId)) {
    echo "already installed\n";
    exit(0);
}
include $_SERVER['DOCUMENT_ROOT'] . '/local/modules/artflowers.salesplan/install/index.php';
$module = new artflowers_salesplan();
if ($module->DoInstall()) {
    echo "installed ok\n";
} else {
    echo "install failed\n";
    exit(2);
}
'''

VERIFY_PHP = r'''<?php
$_SERVER['DOCUMENT_ROOT'] = '/home/bitrix/www';
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
\Bitrix\Main\Loader::includeModule('artflowers.salesplan');
\Bitrix\Main\Loader::includeModule('crm');
$access = \Artflowers\Salesplan\Internal\AccessService::forCurrentUser();
$branchId = (string)$access->resolveDefaultBranchId();
echo "user=" . $access->getUserId() . " admin=" . ($access->isAdmin() ? 'Y' : 'N') . " branch=" . $branchId . "\n";
$dash = \Artflowers\Salesplan\Internal\PlanRepository::buildDashboard($access, $branchId ?: 'almaty', (int)date('Y'), (int)date('n'), 'all');
echo "branch_plan=" . $dash['branch']['plan'] . " actual=" . $dash['branch']['actual'] . " managers=" . count($dash['managers']) . "\n";
'''


def run(client, cmd, timeout=120):
    _, o, e = client.exec_command(cmd, timeout=timeout)
    return o.read().decode('utf-8', 'replace') + e.read().decode('utf-8', 'replace')


def put_dir(sftp, local, remote):
    parts = remote.strip('/').split('/')
    path = ''
    for part in parts:
        path += '/' + part
        try:
            sftp.stat(path)
        except OSError:
            try:
                sftp.mkdir(path)
            except OSError:
                pass
    for name in os.listdir(local):
        lp = os.path.join(local, name)
        rp = remote + '/' + name
        if os.path.isdir(lp):
            put_dir(sftp, lp, rp)
        else:
            sftp.put(lp, rp)


def main():
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(HOST, username=USER, key_filename=KEY, timeout=30)
    sftp = client.open_sftp()

    paths = [
        ('local/modules/artflowers.salesplan', REMOTE_WWW + '/local/modules/artflowers.salesplan'),
        ('local/sales_plan', REMOTE_WWW + '/local/sales_plan'),
    ]
    for rel, remote in paths:
        local = os.path.join(LOCAL, rel.replace('/', os.sep))
        put_dir(sftp, local, remote)
        print('uploaded', rel)

    for script, path in [(INSTALL_PHP, '/tmp/af_sp_install.php'), (VERIFY_PHP, '/tmp/af_sp_verify.php')]:
        with sftp.open(path, 'w') as f:
            f.write(script.encode('utf-8'))

    print(run(client, 'php /tmp/af_sp_install.php'))
    print(run(client, 'php /tmp/af_sp_verify.php'))

    init_snippet = """
$afSpMenu = $_SERVER['DOCUMENT_ROOT'] . '/local/sales_plan/include_menu.php';
if (is_file($afSpMenu)) { require_once $afSpMenu; }
"""
    init_path = REMOTE_WWW + '/bitrix/php_interface/init.php'
    init = sftp.open(init_path, 'r').read().decode('utf-8', 'replace')
    if 'include_menu.php' not in init:
        with sftp.open(init_path, 'w') as f:
            f.write(init.rstrip() + '\n' + init_snippet)
        print('init.php updated')
    else:
        print('init.php already has menu include')

    sftp.close()
    client.close()
    print('done')


if __name__ == '__main__':
    main()
