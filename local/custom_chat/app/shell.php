<?php
/**
 * Оболочка локального приложения.
 *
 * BitrixMobile: handler БЕЗ prolog → REST userId → сразу redirect на mobile.php
 * Desktop: iframe + wa_tok.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

function waCcAppIsMobileClient()
{
	$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

	if (!empty($_REQUEST['mobile']) || !empty($_REQUEST['MOBILE']) || (string)($_REQUEST['wa_mobile'] ?? '') === '1') {
		return true;
	}
	if ((string)($_REQUEST['wa_desktop'] ?? '') === '1') {
		return false;
	}

	// Native Bitrix24 app — всегда mobile-путь, даже если WebView грузит handler как iframe
	if ($ua !== '' && preg_match('/BitrixMobile|BXMobileApp|Bitrix24\.Mobile/i', $ua)) {
		return true;
	}

	// Слайдер Bitrix / iframe локального приложения в desktop-браузере — НЕ mobile-redirect
	$secDest = strtolower((string)($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''));
	if ($secDest === 'iframe') {
		return false;
	}
	if (!empty($_SERVER['HTTP_SEC_FETCH_MODE'])
		&& strtolower((string)$_SERVER['HTTP_SEC_FETCH_MODE']) === 'navigate'
		&& $secDest === 'iframe'
	) {
		return false;
	}

	if ($ua !== '' && preg_match('/Windows NT|Macintosh|X11;|CrOS/i', $ua)
		&& !preg_match('/Mobile|Android.+Mobile|iPhone|iPod|BitrixMobile|BXMobileApp/i', $ua)
	) {
		return false;
	}

	if ($ua !== '' && preg_match(
		'/BitrixMobile|BXMobileApp|Bitrix24\.Mobile|iPhone|iPod|iPad|Android.+Mobile|Opera Mini/i',
		$ua
	)) {
		return true;
	}

	// Открытие из меню/таймлайна приложения с AUTH_ID в десктоп-слайдере
	if (!empty($_REQUEST['AUTH_ID']) || !empty($_REQUEST['auth'])) {
		return false;
	}

	return false;
}

function waCcAppIssueToken($userId)
{
	$userId = (int)$userId;
	$exp = time() + 600;
	$payload = $userId . '.' . $exp;
	$sig = hash_hmac('sha256', $payload, waCcAppTokenSecret());
	return rtrim(strtr(base64_encode($payload . '.' . $sig), '+/', '-_'), '=');
}

function waCcAppTokenSecret()
{
	$doc = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
	$host = preg_replace('/:(443|80)$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
	return hash('sha256', $doc . '|' . $host . '|wa_cc_app_v2_stable');
}

/**
 * @return int
 */
function waCcAppConsumeToken($token)
{
	$token = (string)$token;
	if ($token === '') {
		return 0;
	}
	$raw = base64_decode(strtr($token, '-_', '+/'), true);
	if ($raw === false || $raw === '') {
		return 0;
	}
	$parts = explode('.', $raw);
	if (count($parts) !== 3) {
		return 0;
	}
	$userId = (int)$parts[0];
	$exp = (int)$parts[1];
	$sig = (string)$parts[2];
	if ($userId <= 0 || $exp < time()) {
		return 0;
	}
	$payload = $userId . '.' . $exp;
	$expect = hash_hmac('sha256', $payload, waCcAppTokenSecret());
	if (!hash_equals($expect, $sig)) {
		return 0;
	}
	return $userId;
}

/**
 * @param string $path
 */
function waCcAppAbsolutizeUrl($path)
{
	$path = (string)$path;
	if ($path === '') {
		return $path;
	}
	if (preg_match('#^https?://#i', $path)) {
		return preg_replace('#^(https?://[^/:]+)(?::443)(/|$)#i', '$1$2', $path);
	}
	$host = preg_replace('/:(443|80)$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
	if ($host === '' && !empty($_SERVER['SERVER_NAME'])) {
		$host = (string)$_SERVER['SERVER_NAME'];
	}
	$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
		|| (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
		|| (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
	$scheme = $https ? 'https' : 'http';
	if ($host === '') {
		return $path;
	}
	if ($path[0] !== '/') {
		$path = '/' . $path;
	}
	return $scheme . '://' . $host . $path;
}

/**
 * @param array<string, scalar> $query
 */
function waCcAppBuildChatUrl(array $query, $userId, $mobile)
{
	$userId = (int)$userId;
	$tok = waCcAppIssueToken($userId);
	$query['wa_embed'] = '1';
	$query['wa_tok'] = $tok;
	$query['wa_from'] = 'app';
	if ($mobile) {
		$query['wa_mobile'] = '1';
		unset($query['wa_desktop']);
	} else {
		$query['wa_desktop'] = '1';
		unset($query['wa_mobile']);
	}

	if ($mobile) {
		$aid = (string)($_REQUEST['AUTH_ID'] ?? $_REQUEST['auth'] ?? '');
		if ($aid !== '') {
			$query['wa_aid'] = $aid;
		}
		$domain = (string)($_REQUEST['DOMAIN'] ?? $_REQUEST['domain'] ?? '');
		if ($domain !== '') {
			$query['DOMAIN'] = preg_replace('#^https?://#i', '', $domain);
			$query['DOMAIN'] = preg_replace('/:(443|80)$/', '', $query['DOMAIN']);
		}
	}

	$qs = http_build_query($query);
	if ($mobile) {
		return waCcAppAbsolutizeUrl('/local/custom_chat/mobile.php?' . $qs);
	}
	return rtrim(waCcAppPublicBaseUrl(), '/') . '/?' . $qs;
}

/**
 * @param array<string, scalar> $query
 * @param bool $mobile
 */
function waCcAppRenderShell(array $query, $userId, $note = '', $mobile = false)
{
	$userId = (int)$userId;
	$mobile = (bool)$mobile;
	$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
	$nativeApp = ($ua !== '' && preg_match('/BitrixMobile|BXMobileApp|Bitrix24\.Mobile/i', $ua));

	// Desktop slider iframe — не mobile; native app — всегда mobile
	$secDest = strtolower((string)($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''));
	if ($mobile && !$nativeApp && ($secDest === 'iframe' || !empty($_REQUEST['IFRAME']))) {
		$mobile = false;
	}

	$src = waCcAppBuildChatUrl($query, $userId, $mobile);
	$title = waCcAppTitle();

	if (!headers_sent()) {
		header('Content-Type: text/html; charset=UTF-8');
		header('Cache-Control: no-store');
		header_remove('X-Frame-Options');
		header('Content-Security-Policy: frame-ancestors \'self\' https://crm.artflowers.kz');
	}

	$srcEsc = htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	$titleEsc = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	$srcJs = json_encode($src, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

	// Bitrix24 app WebView: HTTP 302 / meta из iframe → белый экран. Отдаём КЦ напрямую.
	if ($mobile && $nativeApp) {
		$qs = (string)(parse_url($src, PHP_URL_QUERY) ?? '');
		if ($qs !== '') {
			parse_str($qs, $params);
			if (is_array($params)) {
				foreach ($params as $k => $v) {
					if ($v === '' || $v === null) {
						continue;
					}
					$_GET[(string)$k] = (string)$v;
					$_REQUEST[(string)$k] = (string)$v;
				}
			}
		}
		$aid = (string)($_REQUEST['AUTH_ID'] ?? $_REQUEST['auth'] ?? $_REQUEST['wa_aid'] ?? '');
		$GLOBALS['WA_CC_FORCED_USER_ID'] = $userId;
		if ($aid !== '') {
			$GLOBALS['WA_CC_AID'] = $aid;
		}
		if (!empty($query['leadId'])) {
			$GLOBALS['WA_CC_CRM_LEAD_ID'] = (int)$query['leadId'];
		}
		if (!empty($query['dealId'])) {
			$GLOBALS['WA_CC_CRM_DEAL_ID'] = (int)$query['dealId'];
		}
		require $_SERVER['DOCUMENT_ROOT'] . '/local/custom_chat/mobile.php';
		die();
	}

	if ($mobile) {
		// Без зелёного bridge — сразу в КЦ
		if (!headers_sent()) {
			header('Location: ' . $src, true, 302);
		}
		echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta http-equiv="refresh" content="0;url=' . $srcEsc . '">'
			. '<title>' . $titleEsc . '</title>'
			. '<script>try{location.replace(' . $srcJs . ');}catch(e){location.href=' . $srcJs . ';}</script>'
			. '</head><body></body></html>';
		die();
	}

	echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8">'
		. '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
		. '<title>' . $titleEsc . '</title>'
		. '<style>'
		. 'html,body{margin:0;padding:0;height:100%;height:100dvh;background:#f0f2f5;overflow:hidden}'
		. 'iframe{display:block;width:100%;height:100%;height:100dvh;border:0;background:#fff}'
		. '</style></head><body>'
		. '<iframe src="' . $srcEsc . '" title="' . $titleEsc . '" '
		. 'allow="microphone; clipboard-read; clipboard-write"></iframe>'
		. '</body></html>';
	die();
}
