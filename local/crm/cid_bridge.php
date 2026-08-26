<?php
/**
 * Asterisk -> Bitrix CID bridge (append-only log).
 * GET/POST: token, did, cid, uid
 */
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('STOP_STATISTICS', true);
define('BX_SECURITY_SHOW_MESSAGE', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

const WA_CID_BRIDGE_TOKEN = 'wa_cid_bridge_20260826';
const WA_CID_BRIDGE_FILE = __DIR__ . '/cid-bridge/recent.jsonl';
const WA_CID_BRIDGE_MAX_LINES = 300;

header('Content-Type: application/json; charset=utf-8');

function waCidBridge_clientIp(): string
{
	$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
	if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
		return $ip;
	}
	return '';
}

function waCidBridge_allowedIp(string $ip): bool
{
	if ($ip === '127.0.0.1' || $ip === '::1') {
		return true;
	}
	// Asterisk on same box (host network).
	foreach (['185.253.8.33', '46.227.186.231'] as $allowed) {
		if ($ip === $allowed) {
			return true;
		}
	}
	return false;
}

function waCidBridge_normDid($did): string
{
	$d = preg_replace('/\D+/', '', (string)$did);
	if ($d === '77762393888' || $d === '3888') {
		return '3888';
	}
	if ($d === '77710888089' || $d === '8099') {
		return '8099';
	}
	return $d;
}

function waCidBridge_normCid($cid): string
{
	$c = trim((string)$cid);
	if ($c === '') {
		return '';
	}
	$digits = preg_replace('/\D+/', '', $c);
	if ($digits === '') {
		return '';
	}
	if (strlen($digits) === 11 && $digits[0] === '8') {
		$digits = '7' . substr($digits, 1);
	}
	if (strlen($digits) === 10 && $digits[0] === '7') {
		return '+' . $digits;
	}
	if (strlen($digits) === 11 && $digits[0] === '7') {
		return '+' . $digits;
	}
	if ($c[0] === '+') {
		return '+' . $digits;
	}
	return $digits;
}

$token = (string)($_REQUEST['token'] ?? '');
if ($token !== WA_CID_BRIDGE_TOKEN) {
	http_response_code(403);
	echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
	exit;
}

$ip = waCidBridge_clientIp();
if (!waCidBridge_allowedIp($ip)) {
	http_response_code(403);
	echo json_encode(['ok' => false, 'error' => 'ip'], JSON_UNESCAPED_UNICODE);
	exit;
}

$did = waCidBridge_normDid($_REQUEST['did'] ?? '');
$cid = waCidBridge_normCid($_REQUEST['cid'] ?? '');
$uid = trim((string)($_REQUEST['uid'] ?? ''));

if ($did === '' || $cid === '' || strlen(preg_replace('/\D+/', '', $cid)) < 10) {
	http_response_code(400);
	echo json_encode(['ok' => false, 'error' => 'bad_args'], JSON_UNESCAPED_UNICODE);
	exit;
}

$dir = dirname(WA_CID_BRIDGE_FILE);
if (!is_dir($dir)) {
	mkdir($dir, 0755, true);
}

$row = [
	'ts' => gmdate('c'),
	'did' => $did,
	'cid' => $cid,
	'uid' => $uid,
	'ip' => $ip,
];

$line = json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
file_put_contents(WA_CID_BRIDGE_FILE, $line, FILE_APPEND | LOCK_EX);

// Trim tail.
$lines = @file(WA_CID_BRIDGE_FILE, FILE_IGNORE_NEW_LINES);
if (is_array($lines) && count($lines) > WA_CID_BRIDGE_MAX_LINES) {
	$lines = array_slice($lines, -WA_CID_BRIDGE_MAX_LINES);
	file_put_contents(WA_CID_BRIDGE_FILE, implode("\n", $lines) . "\n", LOCK_EX);
}

echo json_encode(['ok' => true, 'did' => $did, 'cid' => $cid], JSON_UNESCAPED_UNICODE);
