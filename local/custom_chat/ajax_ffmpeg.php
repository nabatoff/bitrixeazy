<?php
/**
 * Прокси ffmpeg wasm/js. Отдельный файл — не парсим index.php.
 */
$waFfmpegAssets = [
	'worker' => [
		'url' => 'https://cdn.jsdelivr.net/npm/@ffmpeg/ffmpeg@0.12.10/dist/umd/814.ffmpeg.js',
		'type' => 'text/javascript; charset=utf-8',
	],
	'core-js' => [
		'url' => 'https://cdn.jsdelivr.net/npm/@ffmpeg/core@0.12.10/dist/umd/ffmpeg-core.js',
		'type' => 'text/javascript; charset=utf-8',
	],
	'core-wasm' => [
		'url' => 'https://cdn.jsdelivr.net/npm/@ffmpeg/core@0.12.10/dist/umd/ffmpeg-core.wasm',
		'type' => 'application/wasm',
	],
];
$key = preg_replace('/[^a-z0-9_-]/', '', (string)($_GET['wa_ffmpeg'] ?? $_GET['asset'] ?? ''));
if (!isset($waFfmpegAssets[$key])) {
	http_response_code(404);
	header('Content-Type: text/plain; charset=utf-8');
	echo 'Not found';
	exit;
}
$asset = $waFfmpegAssets[$key];
$ctx = stream_context_create([
	'http' => ['timeout' => 120, 'follow_location' => 1],
	'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
]);
$data = @file_get_contents($asset['url'], false, $ctx);
if ($data === false) {
	http_response_code(502);
	header('Content-Type: text/plain; charset=utf-8');
	echo 'Upstream fetch failed';
	exit;
}
header('Content-Type: ' . $asset['type']);
header('Cache-Control: public, max-age=604800');
header('Access-Control-Allow-Origin: *');
echo $data;
exit;
