<?php
/*
 * ██╗░░░░░░█████╗░███████╗██╗░░░██╗██╗░░░██╗██╗░░██╗██████╗░
 * ██║░░░░░██╔══██╗╚════██║╚██╗░██╔╝╚██╗░██╔╝╚██╗██╔╝██╔══██╗
 * ██║░░░░░███████║░░███╔═╝░╚████╔╝░░╚████╔╝░░╚███╔╝░██║░░██║
 * ██║░░░░░██╔══██║██╔══╝░░░░╚██╔╝░░░░╚██╔╝░░░██╔██╗░██║░░██║
 * ███████╗██║░░██║███████╗░░░██║░░░░░░██║░░░██╔╝╚██╗██████╔╝
 * ╚══════╝╚═╝░░╚═╝╚══════╝░░░╚═╝░░░░░░╚═╝░░░╚═╝░░╚═╝╚═════╝░
 * 
 * Developed By : LazyyXD
 * License      : Not For Sale / Personal Use Only
 */
/**
 * Stream Link Resolver & HLS Proxy
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api.php';

error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: *');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$portal_id = $_GET['id'] ?? '';
$channel_index = $_GET['ch'] ?? null;
$manifest_url = $_GET['m'] ?? null;
$debug = isset($_GET['debug']);

$portals = load_portals();

if (!isset($portals[$portal_id])) {
    http_response_code(404);
    die('Portal not found');
}

$portal = $portals[$portal_id];
$cache_file = PLAYLISTS_DIR . '/' . $portal_id . '_channels.json';
$session_file = SESSIONS_DIR . '/' . md5($portal['url'] . $portal['mac']) . '.json';

function loadChannelCache($cache_file) {
    if (!file_exists($cache_file)) return null;
    $data = json_decode(file_get_contents($cache_file), true);
    return $data['channels'] ?? null;
}

function proxyManifest($url, $portal_id, $base_url) {
    $headers = [
        'User-Agent: Mozilla/5.0 (QtEmbedded; U; Linux; C) AppleWebKit/533.3 (KHTML, like Gecko) MAG200 stbapp ver: 2 rev: 250 Safari/533.3',
        'Accept: */*',
        'Connection: Keep-Alive'
    ];

    $result = curl_request($url, $headers, 15);
    if (!$result['success']) return ['success' => false, 'error' => $result['error']];

    $content = $result['response'];
    $parsed = parse_url($url);
    $origin = $parsed['scheme'] . '://' . $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
    $basePath = isset($parsed['path']) ? dirname($parsed['path']) : '';

    $lines = explode("\n", $content);
    $output = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) { $output[] = ''; continue; }

        if (strpos($line, '#') === 0) {
            if (preg_match('/URI="([^"]+)"/', $line, $matches)) {
                $uri = $matches[1];
                $fullUrl = resolveUrl($uri, $origin, $basePath);
                $proxyUrl = $base_url . '/getlink.php?id=' . urlencode($portal_id) . '&m=' . urlencode(base64_encode($fullUrl));
                $line = str_replace('URI="' . $uri . '"', 'URI="' . $proxyUrl . '"', $line);
            }
            $output[] = $line;
            continue;
        }

        $fullUrl = resolveUrl($line, $origin, $basePath);
        if (preg_match('/\.m3u8(\?|$)/i', $line)) {
            $output[] = $base_url . '/getlink.php?id=' . urlencode($portal_id) . '&m=' . urlencode(base64_encode($fullUrl));
        } else {
            $output[] = $fullUrl;
        }
    }

    return ['success' => true, 'content' => implode("\n", $output)];
}

function resolveUrl($url, $origin, $basePath) {
    if (preg_match('#^https?://#i', $url)) return $url;
    if (strpos($url, '//') === 0) return 'http:' . $url;
    if (strpos($url, '/') === 0) return $origin . $url;
    return $origin . rtrim($basePath, '/') . '/' . $url;
}

$custom_device = [
    'sn_cut' => $portal['sn_cut'] ?? '',
    'device_id' => $portal['device_id'] ?? '',
    'device_id2' => $portal['device_id2'] ?? '',
    'signature' => $portal['signature'] ?? '',
    'model' => $portal['model'] ?? 'MAG250'
];

// ============================================
// Route: Proxy sub-manifest (?m=base64url)
// ============================================
if ($manifest_url !== null) {
    $url = base64_decode($manifest_url);
    if (!$url || !preg_match('#^https?://#i', $url)) {
        http_response_code(400);
        die('Invalid manifest URL');
    }

    // Background token refresh - keep token alive during playback
    if (file_exists($session_file)) {
        $session_data = json_decode(file_get_contents($session_file), true);
        $token_age = time() - ($session_data['timestamp'] ?? 0);

        // Refresh token if older than 4 minutes (240 seconds)
        if ($token_age > 240) {
            $api = new StalkerAPI($portal['url'], $portal['mac'], $custom_device);
            $hs = $api->handshake();
            if ($hs['success']) {
                $api->getProfile();
            }
        }
    }

    $result = proxyManifest($url, $portal_id, BASE_URL);
    if (!$result['success']) {
        http_response_code(502);
        die('Failed to proxy manifest: ' . ($result['error'] ?? 'Unknown'));
    }

    header('Content-Type: application/vnd.apple.mpegurl');
    header('Cache-Control: no-cache');
    echo $result['content'];
    exit;
}

// ============================================
// Route: Resolve channel (?ch=index)
// ============================================
if ($channel_index === null) {
    http_response_code(400);
    die('Missing channel index');
}

$channels = loadChannelCache($cache_file);

if (!$channels) {
    http_response_code(500);
    die('No channel cache. Please sync the portal first from the dashboard.');
}

$channel_index = (int)$channel_index;

if (!isset($channels[$channel_index])) {
    http_response_code(404);
    die('Channel not found');
}

$channel = $channels[$channel_index];
$cmd = $channel['cmd'] ?? '';

if (empty($cmd)) {
    http_response_code(400);
    die('No stream command');
}

// Create API instance
$api = new StalkerAPI($portal['url'], $portal['mac'], $custom_device);

// FORCE FRESH TOKEN for channel switching
$hs = $api->handshake();
if (!$hs['success']) {
    http_response_code(500);
    die('Handshake failed: ' . ($hs['error'] ?? 'Unknown'));
}

// Always get profile after handshake to initialize session
$api->getProfile();

// Debug mode output
if ($debug) {
    header('Content-Type: text/plain');
    echo "Portal: " . $portal['url'] . "\n";
    echo "MAC: " . $portal['mac'] . "\n";
    echo "Channel: " . ($channel['name'] ?? 'Unknown') . "\n";
    echo "CMD: " . $cmd . "\n";
    echo "Token: " . ($api->getToken() ? 'Yes' : 'No') . "\n";
    echo "\n";
}

// Resolve stream URL
$link = $api->createLink($cmd);

// If failed, try with fresh token + profile
if (!$link['success'] || empty($link['url'])) {
    $hs = $api->handshake();

    if ($hs['success']) {
        $api->getProfile();
    }

    $link = $api->createLink($cmd);

    if ($debug) {
        echo "Retry Result:\n";
        print_r($link);
        exit;
    }

    if (!$link['success'] || empty($link['url'])) {
        http_response_code(500);
        die('Failed to resolve stream URL');
    }
}

$stream_url = trim($link['url']);
if (stripos($stream_url, 'ffmpeg ') === 0) {
    $stream_url = trim(substr($stream_url, 7));
}

if ($debug) {
    echo "Final stream URL: " . $stream_url . "\n";
    exit;
}

// Proxy HLS manifests
if (preg_match('/\.m3u8(\?|$)/i', $stream_url)) {
    $result = proxyManifest($stream_url, $portal_id, BASE_URL);

    if (!$result['success']) {
        header('Location: ' . $stream_url, true, 302);
        exit;
    }

    header('Content-Type: application/vnd.apple.mpegurl');
    header('Cache-Control: no-cache');
    echo $result['content'];
    exit;
}

header('Location: ' . $stream_url, true, 302);
exit;