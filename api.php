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
 * Stalker Portal API Wrapper
 * Handles all communication with Stalker Portal servers
 */

require_once __DIR__ . '/config.php';

class StalkerAPI {
    private string $portal_url;
    private string $mac;
    private array $device_info;
    private ?string $token = null;
    private string $session_file;
    private string $model = 'MAG250';
    
    /**
     * Constructor with optional custom device credentials
     * @param string $portal_url Portal base URL
     * @param string $mac MAC address
     * @param array $custom_device Optional: ['sn_cut', 'device_id', 'device_id2', 'signature', 'model']
     */
    public function __construct(string $portal_url, string $mac, array $custom_device = []) {
        $this->portal_url = sanitize_portal_url($portal_url);
        $this->mac = strtoupper(trim($mac));
        
        // Generate default device info from MAC
        $this->device_info = generate_device_info($this->mac);
        
        // Override with custom device credentials if provided
        if (!empty($custom_device['sn_cut'])) {
            $this->device_info['sn_cut'] = $custom_device['sn_cut'];
        }
        if (!empty($custom_device['device_id'])) {
            $this->device_info['device_id'] = $custom_device['device_id'];
        }
        if (!empty($custom_device['device_id2'])) {
            $this->device_info['device_id2'] = $custom_device['device_id2'];
        } else {
            $this->device_info['device_id2'] = $this->device_info['device_id'];
        }
        if (!empty($custom_device['signature'])) {
            $this->device_info['signature'] = $custom_device['signature'];
        }
        if (!empty($custom_device['model'])) {
            $this->model = $custom_device['model'];
        }
        
        $this->session_file = SESSIONS_DIR . '/' . md5($this->portal_url . $this->mac) . '.json';
        
        // Try to load existing session
        $this->loadSession();
    }
    
    /**
     * Get the server load.php URL
     */
    private function getServerUrl(): string {
        if (strpos($this->portal_url, '/stalker_portal') !== false) {
            return $this->portal_url . '/server/load.php';
        }
        return $this->portal_url . '/stalker_portal/server/load.php';
    }
    
    /**
     * Get portal base URL for referer
     */
    private function getPortalBase(): string {
        if (strpos($this->portal_url, '/stalker_portal') !== false) {
            return $this->portal_url . '/c/';
        }
        return $this->portal_url . '/stalker_portal/c/';
    }
    
    /**
     * Load session from file
     */
    private function loadSession(): void {
        if (file_exists($this->session_file)) {
            $data = json_decode(file_get_contents($this->session_file), true);
            if ($data && isset($data['token'])) {
                // Check if session is still valid (less than 1 hour old)
                if (isset($data['timestamp']) && (time() - $data['timestamp']) < 3600) {
                    $this->token = $data['token'];
                }
            }
        }
    }
    
    /**
     * Save session to file
     */
    private function saveSession(): void {
        $data = [
            'token' => $this->token,
            'timestamp' => time(),
            'portal_url' => $this->portal_url,
            'mac' => $this->mac
        ];
        file_put_contents($this->session_file, json_encode($data, JSON_PRETTY_PRINT));
    }
    
    /**
     * Build request headers
     */
    private function buildHeaders(): array {
        $headers = [
            'User-Agent: Mozilla/5.0 (QtEmbedded; U; Linux; C) AppleWebKit/533.3 (KHTML, like Gecko) MAG200 stbapp ver: 2 rev: 250 Safari/533.3',
            'X-User-Agent: Model: MAG250; Link: WiFi',
            'Accept: */*',
            'Accept-Encoding: gzip, deflate',
            'Connection: Keep-Alive',
            'Cookie: mac=' . urlencode($this->mac) . '; stb_lang=en; timezone=GMT',
            'Referer: ' . $this->getPortalBase(),
        ];
        
        if ($this->token) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        
        return $headers;
    }
    
    /**
     * Perform handshake to get token
     */
    public function handshake(): array {
        $url = $this->getServerUrl() . '?type=stb&action=handshake&prehash=' . urlencode($this->mac) . '&token=&JsHttpRequest=1-xml';
        
        $result = curl_request($url, $this->buildHeaders(), HANDSHAKE_TIMEOUT);
        
        if (!$result['success']) {
            return ['success' => false, 'error' => 'Connection failed: ' . $result['error']];
        }
        
        $data = json_decode($result['response'], true);
        
        if (isset($data['js']['token'])) {
            $this->token = $data['js']['token'];
            $this->saveSession();
            return ['success' => true, 'token' => $this->token];
        }
        
        if (isset($data['token'])) {
            $this->token = $data['token'];
            $this->saveSession();
            return ['success' => true, 'token' => $this->token];
        }
        
        return ['success' => false, 'error' => 'No token in response', 'raw' => $result['response']];
    }
    
    /**
     * Re-handshake using existing token (keeps session alive)
     * This is called automatically to refresh token before expiry
     */
    public function reHandshake(): array {
        if (!$this->token) {
            return $this->handshake();
        }
        
        $url = $this->getServerUrl() . '?type=stb&action=handshake&prehash=' . urlencode($this->mac) 
             . '&token=' . urlencode($this->token) . '&JsHttpRequest=1-xml';
        
        $result = curl_request($url, $this->buildHeaders(), HANDSHAKE_TIMEOUT);
        
        if (!$result['success']) {
            // If re-handshake fails, try fresh handshake
            return $this->handshake();
        }
        
        $data = json_decode($result['response'], true);
        
        if (isset($data['js']['token'])) {
            $this->token = $data['js']['token'];
            $this->saveSession();
            return ['success' => true, 'token' => $this->token, 'refreshed' => true];
        }
        
        // Fallback to fresh handshake
        return $this->handshake();
    }
    
    /**
     * Ensure we have a valid token (auto-refresh if near expiry)
     * Token is refreshed if older than 5 minutes for aggressive refresh
     * IMPORTANT: Calls get_profile after handshake to fully initialize session
     */
    public function ensureToken(): bool {
        // Check if we have a token and its age
        if ($this->token) {
            // Load session to check timestamp
            if (file_exists($this->session_file)) {
                $data = json_decode(file_get_contents($this->session_file), true);
                $age = time() - ($data['timestamp'] ?? 0);
                
                // If token is older than 5 minutes (300 seconds), refresh it proactively
                if ($age > 300) {
                    $result = $this->reHandshake();
                    if ($result['success']) {
                        // Call get_profile to fully initialize session
                        $this->getProfile();
                    }
                    return $result['success'];
                }
            }
            return true;
        }
        
        // No token - do fresh handshake + profile
        $result = $this->handshake();
        if ($result['success']) {
            // IMPORTANT: get_profile must be called after handshake
            // Many portals require this before createLink will work
            $this->getProfile();
        }
        return $result['success'];
    }
    
    /**
     * Force refresh token (useful for long streams)
     */
    public function forceTokenRefresh(): array {
        return $this->reHandshake();
    }
    
    /**
     * Get user profile
     */
    public function getProfile(): array {
        if (!$this->ensureToken()) {
            return ['success' => false, 'error' => 'Failed to get token'];
        }
        
        $ts = time();
        $metrics = json_encode([
            'mac' => $this->device_info['mac'],
            'sn' => $this->device_info['sn'],
            'model' => 'MAG250',
            'type' => 'STB',
            'random' => bin2hex(random_bytes(8))
        ]);
        
        $url = $this->getServerUrl() . '?' . http_build_query([
            'type' => 'stb',
            'action' => 'get_profile',
            'hd' => '1',
            'sn' => $this->device_info['sn_cut'],
            'stb_type' => 'MAG250',
            'device_id' => $this->device_info['device_id'],
            'device_id2' => $this->device_info['device_id'],
            'signature' => $this->device_info['signature'],
            'timestamp' => $ts,
            'metrics' => $metrics,
            'JsHttpRequest' => '1-xml'
        ]);
        
        $result = curl_request($url, $this->buildHeaders(), REQUEST_TIMEOUT);
        
        if (!$result['success']) {
            return ['success' => false, 'error' => 'Request failed'];
        }
        
        $data = json_decode($result['response'], true);
        $js = $data['js'] ?? $data;
        
        return [
            'success' => true,
            'data' => [
                'id' => $js['id'] ?? null,
                'name' => $js['name'] ?? $js['fname'] ?? 'Unknown',
                'login' => $js['login'] ?? null,
                'expiry' => $js['expire_billing_date'] ?? $js['phone'] ?? $js['expires'] ?? 'Unknown',
                'tariff' => $js['tariff_plan'] ?? $js['tariff_plan_name'] ?? null,
                'status' => $js['status'] ?? 1,
                'parent_password' => $js['parent_password'] ?? null
            ]
        ];
    }
    
    /**
     * Get genres/categories
     */
    public function getGenres(): array {
        if (!$this->ensureToken()) {
            return [];
        }
        
        $endpoints = [
            'type=itv&action=get_genres&JsHttpRequest=1-xml',
            'type=itv&action=get_all_genres&JsHttpRequest=1-xml',
        ];
        
        foreach ($endpoints as $endpoint) {
            $url = $this->getServerUrl() . '?' . $endpoint;
            $result = curl_request($url, $this->buildHeaders(), REQUEST_TIMEOUT);
            
            if ($result['success']) {
                $data = json_decode($result['response'], true);
                $list = $data['js']['data'] ?? $data['js'] ?? $data['data'] ?? [];
                
                if (is_array($list) && !empty($list)) {
                    $genres = [];
                    foreach ($list as $item) {
                        if (is_array($item)) {
                            $id = $item['id'] ?? $item['genre_id'] ?? $item['tv_genre_id'] ?? null;
                            $name = $item['title'] ?? $item['name'] ?? $item['genre_name'] ?? '';
                            if ($id !== null) {
                                $genres[(string)$id] = $name;
                            }
                        }
                    }
                    if (!empty($genres)) {
                        return $genres;
                    }
                }
            }
        }
        
        return [];
    }
    
    /**
     * Get all channels
     */
    public function getChannels(): array {
        if (!$this->ensureToken()) {
            return ['success' => false, 'error' => 'Failed to get token', 'channels' => []];
        }
        
        $url = $this->getServerUrl() . '?type=itv&action=get_all_channels&JsHttpRequest=1-xml';
        $result = curl_request($url, $this->buildHeaders(), 30);
        
        if (!$result['success']) {
            return ['success' => false, 'error' => 'Request failed', 'channels' => []];
        }
        
        $data = json_decode($result['response'], true);
        
        // Extract channels from various response formats
        $channels = [];
        if (isset($data['js']['data'])) {
            $channels = $data['js']['data'];
        } elseif (isset($data['data'])) {
            $channels = $data['data'];
        } elseif (is_array($data['js'] ?? null)) {
            $channels = array_values(array_filter($data['js'], 'is_array'));
        }
        
        // Get genres for mapping
        $genres = $this->getGenres();
        
        // Enrich channels with category names
        $enriched = [];
        foreach ($channels as $ch) {
            if (!is_array($ch)) continue;
            
            $genre_id = $ch['tv_genre_id'] ?? $ch['genre_id'] ?? $ch['category_id'] ?? null;
            $category = $genres[(string)$genre_id] ?? $ch['category'] ?? $ch['genres_str'] ?? 'Unknown';
            
            $enriched[] = [
                'id' => $ch['id'] ?? null,
                'name' => $ch['name'] ?? $ch['title'] ?? 'Unknown',
                'cmd' => $ch['cmd'] ?? '',
                'logo' => LOGO_URL,
                'category' => $category,
                'number' => $ch['number'] ?? 0
            ];
        }
        
        return [
            'success' => true,
            'total' => count($enriched),
            'channels' => $enriched
        ];
    }
    
    /**
     * Create stream link for a channel
     */
    public function createLink(string $cmd): array {
        if (!$this->ensureToken()) {
            return ['success' => false, 'error' => 'Failed to get token'];
        }
        
        // Check if cmd already contains a direct URL
        if (preg_match('#(https?://[^\s"\']+)#i', $cmd, $m) && stripos($cmd, 'ffrt') !== 0) {
            return ['success' => true, 'url' => trim($m[1]), 'direct' => true];
        }
        
        // Handle ffmpeg prefix
        if (stripos($cmd, 'ffmpeg ') === 0) {
            $stripped = substr($cmd, 7);
            if (preg_match('#(https?://[^\s"\']+)#i', $stripped, $m)) {
                return ['success' => true, 'url' => trim($m[1]), 'direct' => true];
            }
        }
        
        // Call portal API
        $url = $this->getServerUrl() . '?' . http_build_query([
            'type' => 'itv',
            'action' => 'create_link',
            'cmd' => $cmd,
            'JsHttpRequest' => '1-xml'
        ]);
        
        $result = curl_request($url, $this->buildHeaders(), REQUEST_TIMEOUT);
        
        if (!$result['success']) {
            return ['success' => false, 'error' => 'Request failed'];
        }
        
        $data = json_decode($result['response'], true);
        $js = $data['js'] ?? $data;
        
        // Extract URL from response
        $stream_url = null;
        if (isset($js['cmd'])) {
            $cmd_result = $js['cmd'];
            if (stripos($cmd_result, 'ffmpeg ') === 0) {
                $cmd_result = substr($cmd_result, 7);
            }
            if (preg_match('#(https?://[^\s"\']+)#i', $cmd_result, $m)) {
                $stream_url = trim($m[1]);
            } else {
                $stream_url = $cmd_result;
            }
        } elseif (isset($js['url'])) {
            $stream_url = $js['url'];
        }
        
        if ($stream_url) {
            return ['success' => true, 'url' => $stream_url, 'direct' => false];
        }
        
        return ['success' => false, 'error' => 'No URL in response', 'raw' => $result['response']];
    }
    
    /**
     * Get current token
     */
    public function getToken(): ?string {
        return $this->token;
    }
    
    /**
     * Test portal connection
     */
    public function testConnection(): array {
        $handshake = $this->handshake();
        if (!$handshake['success']) {
            return ['success' => false, 'error' => $handshake['error'] ?? 'Handshake failed'];
        }
        
        $profile = $this->getProfile();
        if (!$profile['success']) {
            return ['success' => false, 'error' => 'Profile fetch failed'];
        }
        
        return [
            'success' => true,
            'message' => 'Connection successful',
            'profile' => $profile['data']
        ];
    }
}
