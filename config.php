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
 * Stalker Portal Manager - Configuration
 */

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Timezone
date_default_timezone_set('Asia/Kolkata');

// Session settings
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);

// ==========================================
//           USER CONFIGURATION
// ==========================================

// 1. Base URL Configuration
//    - Automatically detects your server's IP/Domain
//    - Works for Localhost, KSWEB, XAMPP, and Live Hosting
//    - You can manually override this if needed
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
// Remove filename from current script path to get directory
$path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
define('BASE_URL', $protocol . $host . $path);
define('SITE_NAME', 'Stalker Portal Manager');

// 2. Admin Credentials
//    - Change these immediately after installation!
//    - Username: The username you will use to login
//    - Password Hash: Use password_hash('YourPassword', PASSWORD_DEFAULT) to generate
//    - Default Password: 'Gahu@420'
define('ADMIN_USERNAME', 'LazyyXD');
define('ADMIN_PASSWORD_HASH', password_hash('Pass@LazyyXD', PASSWORD_DEFAULT));

// 3. Branding Configuration
//    - URL to your logo image (PNG/JPG)
//    - Recommended size: 200x200px or similar square aspect ratio
define('LOGO_URL', 'https://i.ibb.co/gZyGHzSS/logo.png');

// ==========================================
//       END OF USER CONFIGURATION
// ==========================================

// Data storage directory
define('DATA_DIR', __DIR__ . '/data');
define('PORTALS_FILE', DATA_DIR . '/portals.json');
define('SESSIONS_DIR', DATA_DIR . '/sessions');
define('PLAYLISTS_DIR', DATA_DIR . '/playlists');

// Create directories if not exist
if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);
if (!is_dir(SESSIONS_DIR)) mkdir(SESSIONS_DIR, 0755, true);
if (!is_dir(PLAYLISTS_DIR)) mkdir(PLAYLISTS_DIR, 0755, true);

// Protect data directory
if (!file_exists(DATA_DIR . '/.htaccess')) {
    file_put_contents(DATA_DIR . '/.htaccess', "deny from all\n");
}

// Request timeout settings
define('REQUEST_TIMEOUT', 15);
define('HANDSHAKE_TIMEOUT', 10);

/**
 * Load portals from JSON file
 */
function load_portals(): array {
    if (!file_exists(PORTALS_FILE)) {
        return [];
    }
    $content = file_get_contents(PORTALS_FILE);
    return json_decode($content, true) ?: [];
}

/**
 * Save portals to JSON file
 */
function save_portals(array $portals): bool {
    $json = json_encode($portals, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return file_put_contents(PORTALS_FILE, $json) !== false;
}

/**
 * Generate unique portal ID
 */
function generate_portal_id(): string {
    return bin2hex(random_bytes(8));
}

/**
 * Sanitize portal URL
 */
function sanitize_portal_url(string $url): string {
    $url = trim($url);
    $url = rtrim($url, '/');
    
    // Remove trailing paths like /c/ or /c
    $url = preg_replace('#/c/?$#', '', $url);
    $url = preg_replace('#/stalker_portal/?$#', '', $url);
    
    return $url;
}

/**
 * Generate device info from MAC address
 */
function generate_device_info(string $mac): array {
    $mac = strtoupper(trim($mac));
    $sn = strtoupper(md5($mac));
    $sn_cut = substr($sn, 0, 13);
    $device_id = strtoupper(hash('sha256', $mac));
    $signature = strtoupper(hash('sha256', $sn_cut . $mac));
    
    return [
        'mac' => $mac,
        'sn' => $sn,
        'sn_cut' => $sn_cut,
        'device_id' => $device_id,
        'signature' => $signature
    ];
}

/**
 * Build headers for Stalker Portal requests
 */
function build_headers(string $portal_url, string $mac, string $token = ''): array {
    $headers = [
        'User-Agent: Mozilla/5.0 (QtEmbedded; U; Linux; C) AppleWebKit/533.3 (KHTML, like Gecko) MAG200 stbapp ver: 2 rev: 250 Safari/533.3',
        'X-User-Agent: Model: MAG250; Link: WiFi',
        'Accept: */*',
        'Accept-Encoding: gzip, deflate',
        'Connection: Keep-Alive',
        'Cookie: mac=' . urlencode($mac) . '; stb_lang=en; timezone=GMT',
    ];
    
    if (!empty($token)) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    
    // Add referer based on portal URL
    $referer = $portal_url;
    if (strpos($portal_url, '/stalker_portal') !== false) {
        $referer = $portal_url . '/c/';
    } elseif (strpos($portal_url, '/c') === false) {
        $referer = $portal_url . '/c/';
    }
    $headers[] = 'Referer: ' . $referer;
    
    return $headers;
}

/**
 * Make cURL request
 */
function curl_request(string $url, array $headers = [], int $timeout = 15): array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');
    
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    
    return [
        'success' => empty($error) && $info['http_code'] >= 200 && $info['http_code'] < 400,
        'response' => $response,
        'error' => $error,
        'http_code' => $info['http_code'],
        'info' => $info
    ];
}

/**
 * JSON response helper
 */
function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Check if user is logged in
 */
function is_logged_in(): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

/**
 * Require login
 */
function require_login(): void {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}
