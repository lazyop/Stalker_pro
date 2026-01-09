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
 * M3U Playlist Generator - With Channel Caching
 * Caches channel data to avoid hitting portal API on every request
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api.php';

// Strict User Agent Protection
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
if (stripos($ua, 'TiviMate') === false && stripos($ua, 'OTT Navigator') === false) {
    header("Location: https://i.ibb.co/DgkrxYhj/nigga.png");
    exit;
}

$portal_id = $_GET['id'] ?? '';
$force_refresh = isset($_GET['refresh']);
$portals = load_portals();

if (!isset($portals[$portal_id])) {
    http_response_code(404);
    header('Content-Type: audio/x-mpegurl');
    die("#EXTM3U\n#EXTINF:-1,Portal Not Found\nhttp://error.invalid/not-found");
}

$portal = $portals[$portal_id];

// Channel cache file (valid for 30 minutes)
$cache_file = PLAYLISTS_DIR . '/' . $portal_id . '_channels.json';
$cache_max_age = 1800; // 30 minutes

/**
 * Load cached channels if valid
 */
function loadChannelCache($cache_file, $max_age) {
    if (!file_exists($cache_file)) {
        return null;
    }
    
    $age = time() - filemtime($cache_file);
    if ($age > $max_age) {
        return null; // Cache expired
    }
    
    $data = json_decode(file_get_contents($cache_file), true);
    return $data['channels'] ?? null;
}

/**
 * Save channels to cache
 */
function saveChannelCache($cache_file, $channels) {
    $data = [
        'cached_at' => date('Y-m-d H:i:s'),
        'timestamp' => time(),
        'count' => count($channels),
        'channels' => $channels
    ];
    file_put_contents($cache_file, json_encode($data, JSON_PRETTY_PRINT));
}

// Try to load from cache first (unless force refresh)
$channels = null;
$from_cache = false;

if (!$force_refresh) {
    $channels = loadChannelCache($cache_file, $cache_max_age);
    if ($channels) {
        $from_cache = true;
    }
}

// If no cache, fetch from portal
if (!$channels) {
    // Build custom device params
    $custom_device = [
        'sn_cut' => $portal['sn_cut'] ?? '',
        'device_id' => $portal['device_id'] ?? '',
        'device_id2' => $portal['device_id2'] ?? '',
        'signature' => $portal['signature'] ?? '',
        'model' => $portal['model'] ?? 'MAG250'
    ];
    
    $api = new StalkerAPI($portal['url'], $portal['mac'], $custom_device);
    
    // Force a fresh handshake for reliability
    $handshake = $api->handshake();
    if (!$handshake['success']) {
        http_response_code(500);
        header('Content-Type: audio/x-mpegurl');
        die("#EXTM3U\n#EXTINF:-1,Handshake failed - " . htmlspecialchars($handshake['error'] ?? 'Unknown') . "\nhttp://error.invalid/handshake-failed");
    }
    
    // CRITICAL: Always get profile after handshake to fully initialize the session
    $api->getProfile();
    
    $result = $api->getChannels();
    
    if (!$result['success'] || empty($result['channels'])) {
        http_response_code(500);
        header('Content-Type: audio/x-mpegurl');
        die("#EXTM3U\n#EXTINF:-1,Failed to load channels - " . htmlspecialchars($result['error'] ?? 'Unknown') . "\nhttp://error.invalid/failed");
    }
    
    $channels = $result['channels'];
    
    // Save to cache
    saveChannelCache($cache_file, $channels);
}

// Build M3U content
$m3u = "#EXTM3U\n";
$m3u .= "#\n";
$m3u .= "# Developed BY:\n";
$m3u .= "# ██╗░░░░░░█████╗░███████╗██╗░░░██╗██╗░░░██╗██╗░░██╗██████╗░\n";
$m3u .= "# ██║░░░░░██╔══██╗╚════██║╚██╗░██╔╝╚██╗░██╔╝╚██╗██╔╝██╔══██╗\n";
$m3u .= "# ██║░░░░░███████║░░███╔═╝░╚████╔╝░░╚████╔╝░░╚███╔╝░██║░░██║\n";
$m3u .= "# ██║░░░░░██╔══██║██╔══╝░░░░╚██╔╝░░░░╚██╔╝░░░██╔██╗░██║░░██║\n";
$m3u .= "# ███████╗██║░░██║███████╗░░░██║░░░░░░██║░░░██╔╝╚██╗██████╔╝\n";
$m3u .= "# ╚══════╝╚═╝░░╚═╝╚══════╝░░░╚═╝░░░░░░╚═╝░░░╚═╝░░╚═╝╚═════╝░\n";
$m3u .= "#\n";
$m3u .= "#PLAYLIST:" . htmlspecialchars($portal['name']) . "\n";
$m3u .= "#GENERATED:" . date('Y-m-d H:i:s') . "\n";
$m3u .= "#CHANNELS:" . count($channels) . "\n";
$m3u .= "#CACHED:" . ($from_cache ? 'yes' : 'no') . "\n\n";

foreach ($channels as $index => $ch) {
    $name = str_replace(["\r", "\n"], ' ', trim($ch['name']));
    $logo = $ch['logo'] ?? '';
    $group = str_replace('"', "'", $ch['category'] ?? 'Unknown');
    $cmd = $ch['cmd'] ?? '';
    
    if (empty($cmd)) continue;
    
    $m3u .= '#EXTINF:-1';
    $m3u .= ' tvg-id="' . ($ch['id'] ?? $index) . '"';
    if (!empty($logo)) {
        $m3u .= ' tvg-logo="' . htmlspecialchars($logo) . '"';
    }
    $m3u .= ' group-title="' . htmlspecialchars($group) . '"';
    $m3u .= ',' . htmlspecialchars($name) . "\n";
    $m3u .= BASE_URL . '/getlink.php?id=' . urlencode($portal_id) . '&ch=' . $index . "\n";
}

header('Content-Type: audio/x-mpegurl; charset=utf-8');
header('Content-Disposition: inline; filename="' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $portal['name']) . '.m3u"');
header('Cache-Control: max-age=300'); // Browser can cache for 5 min
header('Access-Control-Allow-Origin: *');
header('X-Generated-At: ' . date('c'));
header('X-Channel-Count: ' . count($channels));
header('X-From-Cache: ' . ($from_cache ? 'yes' : 'no'));

echo $m3u;
