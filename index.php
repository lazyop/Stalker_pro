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
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api.php';
// Security Headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

session_start();
require_login();

// Session Timeout (30 minutes)
$timeout_duration = 1800;
if (isset($_SESSION['admin_last_activity']) && (time() - $_SESSION['admin_last_activity']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header("Location: login.php?msg=" . urlencode("Session expired due to inactivity."));
    exit;
}
$_SESSION['admin_last_activity'] = time();

// Generate CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$portals = load_portals();
$message = $_GET['msg'] ?? '';
$error = $_GET['err'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Check
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die('Invalid Security Token');
    }

    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $mac = strtoupper(trim($_POST['mac'] ?? ''));
        $sn_cut = trim($_POST['sn_cut'] ?? '');
        $device_id = trim($_POST['device_id'] ?? '');
        $device_id2 = trim($_POST['device_id2'] ?? '');
        $signature = trim($_POST['signature'] ?? '');
        $model = $_POST['model'] ?? 'MAG250';
        
        if (!empty($name) && !empty($url) && !empty($mac)) {
            $portal_id = generate_portal_id();
            $portals[$portal_id] = [
                'id' => $portal_id, 'name' => $name,
                'url' => sanitize_portal_url($url), 'mac' => $mac,
                'sn_cut' => $sn_cut, 'device_id' => $device_id,
                'device_id2' => $device_id2, 'signature' => $signature,
                'model' => $model, 'created_at' => date('Y-m-d H:i:s'),
                'status' => 'pending', 'channels_count' => 0
            ];
            save_portals($portals);
            header('Location: index.php?msg=Portal+added+successfully');
            exit;
        }
    }

    if ($action === 'update') {
        $id = $_POST['portal_id'] ?? '';
        if (isset($portals[$id])) {
            $name = trim($_POST['name'] ?? '');
            $url = trim($_POST['url'] ?? '');
            $mac = strtoupper(trim($_POST['mac'] ?? ''));
            
            if (!empty($name) && !empty($url) && !empty($mac)) {
                $portals[$id]['name'] = $name;
                $portals[$id]['url'] = sanitize_portal_url($url);
                $portals[$id]['mac'] = $mac;
                $portals[$id]['sn_cut'] = trim($_POST['sn_cut'] ?? '');
                $portals[$id]['device_id'] = trim($_POST['device_id'] ?? '');
                $portals[$id]['device_id2'] = trim($_POST['device_id2'] ?? '');
                $portals[$id]['signature'] = trim($_POST['signature'] ?? '');
                $portals[$id]['model'] = $_POST['model'] ?? 'MAG250';
                
                // Trigger Sync
                $custom_device = [
                    'sn_cut' => $portals[$id]['sn_cut'],
                    'device_id' => $portals[$id]['device_id'],
                    'device_id2' => $portals[$id]['device_id2'],
                    'signature' => $portals[$id]['signature'],
                    'model' => $portals[$id]['model']
                ];
                
                $api = new StalkerAPI($portals[$id]['url'], $portals[$id]['mac'], $custom_device);
                $test = $api->testConnection();
                
                if ($test['success']) {
                    $ch = $api->getChannels();
                    $portals[$id]['status'] = 'active';
                    $portals[$id]['last_sync'] = date('Y-m-d H:i:s');
                    $portals[$id]['channels_count'] = $ch['total'] ?? 0;
                    $portals[$id]['profile'] = $test['profile'] ?? null;
                    $portals[$id]['error'] = null;
                    
                    if (!empty($ch['channels'])) {
                        $cache_file = PLAYLISTS_DIR . '/' . $id . '_channels.json';
                        file_put_contents($cache_file, json_encode([
                            'cached_at' => date('Y-m-d H:i:s'), 'timestamp' => time(),
                            'count' => count($ch['channels']), 'channels' => $ch['channels']
                        ], JSON_PRETTY_PRINT));
                    }
                    save_portals($portals);
                    header('Location: index.php?msg=Portal+updated+and+synced');
                } else {
                    $portals[$id]['status'] = 'error';
                    $portals[$id]['error'] = $test['error'] ?? 'Sync failed';
                    save_portals($portals);
                    header('Location: index.php?err=' . urlencode('Updated but sync failed: ' . ($test['error'] ?? 'Unknown')));
                }
                exit;
            }
        }
    }
    
    if ($action === 'rotate_id' && isset($_POST['portal_id'], $portals[$_POST['portal_id']])) {
        $old_id = $_POST['portal_id'];
        $p = $portals[$old_id];
        
        $new_id = generate_portal_id();
        $p['id'] = $new_id;
        
        // Rename cache file to match new ID (preserve data)
        $old_cache = PLAYLISTS_DIR . '/' . $old_id . '_channels.json';
        $new_cache = PLAYLISTS_DIR . '/' . $new_id . '_channels.json';
        if (file_exists($old_cache)) {
            rename($old_cache, $new_cache);
        }
        
        // Update portals array keys
        unset($portals[$old_id]);
        $portals[$new_id] = $p;
        
        save_portals($portals);
        header('Location: index.php?msg=Portal+ID+Rotated');
        exit;
    }
    
    if ($action === 'delete' && isset($_POST['portal_id'], $portals[$_POST['portal_id']])) {
        $pid = $_POST['portal_id'];
        @unlink(PLAYLISTS_DIR . '/' . $pid . '_channels.json');
        unset($portals[$pid]);
        save_portals($portals);
        header('Location: index.php?msg=Portal+deleted');
        exit;
    }
    
    if ($action === 'sync' && isset($_POST['portal_id'], $portals[$_POST['portal_id']])) {
        $p = $portals[$_POST['portal_id']];
        $pid = $_POST['portal_id'];
        
        $custom_device = [
            'sn_cut' => $p['sn_cut'] ?? '', 'device_id' => $p['device_id'] ?? '',
            'device_id2' => $p['device_id2'] ?? '', 'signature' => $p['signature'] ?? '',
            'model' => $p['model'] ?? 'MAG250'
        ];
        
        $api = new StalkerAPI($p['url'], $p['mac'], $custom_device);
        $test = $api->testConnection();
        if ($test['success']) {
            $ch = $api->getChannels();
            $portals[$pid]['status'] = 'active';
            $portals[$pid]['last_sync'] = date('Y-m-d H:i:s');
            $portals[$pid]['channels_count'] = $ch['total'] ?? 0;
            $portals[$pid]['profile'] = $test['profile'] ?? null;
            save_portals($portals);
            
            if (!empty($ch['channels'])) {
                $cache_file = PLAYLISTS_DIR . '/' . $pid . '_channels.json';
                file_put_contents($cache_file, json_encode([
                    'cached_at' => date('Y-m-d H:i:s'), 'timestamp' => time(),
                    'count' => count($ch['channels']), 'channels' => $ch['channels']
                ], JSON_PRETTY_PRINT));
            }
            header('Location: index.php?msg=Synced+' . ($ch['total'] ?? 0) . '+channels');
        } else {
            $portals[$pid]['status'] = 'error';
            $portals[$pid]['error'] = $test['error'] ?? 'Unknown';
            save_portals($portals);
            header('Location: index.php?err=' . urlencode($test['error'] ?? 'Sync failed'));
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#ffffff">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="light-content">
    <title>Dashboard - Stalker Portal Manager</title>
    <link rel="icon" type="image/png" href="<?= LOGO_URL ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            /* Premium Light Glass Theme relative to Dark Background */
            --bg-gradient: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); /* Fallback */
            
            /* Glass Variables matching Login.php */
            --bg-primary: rgba(255, 255, 255, 0.9);
            --bg-secondary: rgba(248, 250, 252, 0.8);
            --bg-tertiary: rgba(241, 245, 249, 0.8);
            --bg-hover: rgba(226, 232, 240, 0.9);
            
            /* Glass Effect */
            --glass-bg: rgba(255, 255, 255, 0.85);
            --glass-border: rgba(255, 255, 255, 0.5);
            --glass-blur: blur(20px);
            
            /* Text Colors */
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #64748b;
            --text-light: #94a3b8;
            
            /* Border */
            --border: rgba(226, 232, 240, 0.6);
            --border-medium: rgba(203, 213, 225, 0.6);
            --border-focus: #94a3b8;
            
            /* Brand */
            --accent: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
            --accent-solid: #3b82f6;
            --accent-hover: #2563eb;
            --accent-light: #dbeafe;
            --accent-bg: rgba(239, 246, 255, 0.8);
            
            /* Status Colors */
            --success: #10b981;
            --success-light: #d1fae5;
            --success-bg: rgba(236, 253, 245, 0.8);
            
            --warning: #f59e0b;
            --warning-light: #fde68a;
            --warning-bg: rgba(254, 243, 199, 0.8);
            
            --danger: #ef4444;
            --danger-light: #fecaca;
            --danger-bg: rgba(254, 226, 226, 0.8);
            
            --info: #06b6d4;
            --info-light: #cffafe;
            
            /* Shadows */
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px -1px rgba(0, 0, 0, 0.06);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.04);
            --shadow-2xl: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            --shadow-glow: 0 0 20px rgba(59, 130, 246, 0.15);
            
            /* Radius */
            --radius-sm: 8px;
            --radius: 12px;
            --radius-lg: 16px;
            --radius-xl: 20px;
            --radius-2xl: 24px;
            
            /* Spacing */
            --spacing-mobile: 16px;
            --spacing-desktop: 24px;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }
        
        body {
            min-height: 100dvh;
            background-color: #020617;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            color: var(--text-primary);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            overflow-x: hidden;
            position: relative;
        }

        body::before {
            content: "";
            position: absolute;
            inset: 0;
            z-index: 0;
            background-image: radial-gradient(circle 800px at 50% 300px, rgba(16,185,129,0.25), transparent);
            pointer-events: none;
            position: fixed;
        }
        
        /* Glass Navbar */
        .navbar {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: var(--glass-bg);
            backdrop-filter: var(--glass-blur);
            -webkit-backdrop-filter: var(--glass-blur);
            border-bottom: 1px solid var(--border);
            padding: 12px var(--spacing-mobile);
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: var(--shadow-sm);
        }
        
        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .navbar-brand img {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            box-shadow: var(--shadow);
        }
        
        .navbar-brand h1 {
            font-size: 16px;
            font-weight: 800;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -0.03em;
        }
        
        .navbar-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .navbar a {
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            padding: 8px 14px;
            border-radius: var(--radius-sm);
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .navbar a:hover, .navbar a:active {
            color: var(--text-primary);
            background: var(--bg-tertiary);
        }
        
        /* Mobile-Optimized Container */
        .container {
            max-width: 100%;
            margin: 0 auto;
            padding: var(--spacing-mobile);
            padding-bottom: 80px;
        }
        
        /* Stats Overview - Mobile First */
        .stats-overview {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: var(--bg-primary);
            backdrop-filter: var(--glass-blur);
            -webkit-backdrop-filter: var(--glass-blur);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 16px;
            box-shadow: var(--shadow);
            transition: all 0.3s;
        }
        
        .stat-card:active {
            transform: scale(0.98);
        }
        
        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 10px;
            font-size: 18px;
        }
        
        .stat-icon.primary {
            background: var(--accent-bg);
            color: var(--accent-solid);
        }
        
        .stat-icon.success {
            background: var(--success-bg);
            color: var(--success);
        }
        
        .stat-icon.warning {
            background: var(--warning-bg);
            color: var(--warning);
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 800;
            color: var(--text-primary);
            letter-spacing: -0.03em;
            line-height: 1;
        }
        
        .stat-label {
            font-size: 11px;
            color: var(--text-muted);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 4px;
        }
        
        /* Alerts - Mobile Optimized */
        .alert {
            padding: 14px 16px;
            border-radius: var(--radius-lg);
            margin-bottom: 16px;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideDown 0.3s ease;
            box-shadow: var(--shadow-md);
        }
        
        @keyframes slideDown {
            from { 
                opacity: 0; 
                transform: translateY(-20px);
            }
            to { 
                opacity: 1; 
                transform: none;
            }
        }
        
        .alert-success {
            background: var(--success-bg);
            border: 1px solid var(--success-light);
            color: #065f46;
        }
        
        .alert-success i { color: var(--success); }
        
        .alert-error {
            background: var(--danger-bg);
            border: 1px solid var(--danger-light);
            color: #991b1b;
        }
        
        .alert-error i { color: var(--danger); }
        
        /* Premium Glass Cards */
        .card {
            background: var(--bg-primary);
            backdrop-filter: var(--glass-blur);
            -webkit-backdrop-filter: var(--glass-blur);
            border: 1px solid var(--border);
            border-radius: var(--radius-xl);
            margin-bottom: 20px;
            overflow: hidden;
            box-shadow: var(--shadow-md);
            transition: all 0.3s;
        }
        
        .card-header {
            padding: 16px;
            border-bottom: 1px solid var(--border);
            background: var(--bg-secondary);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .card-title {
            font-size: 15px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-primary);
            letter-spacing: -0.02em;
        }
        
        .card-title i {
            background: var(--accent);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 18px;
        }
        
        .card-body { 
            padding: 16px;
        }
        
        /* Forms - Touch Optimized */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 14px;
        }
        
        .form-group { 
            display: flex; 
            flex-direction: column; 
            gap: 8px;
        }
        
        .form-label {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-secondary);
            letter-spacing: -0.01em;
        }
        
        .form-input, .form-select {
            width: 100%;
            padding: 14px 16px;
            background: var(--bg-secondary);
            border: 2px solid var(--border);
            border-radius: var(--radius-lg);
            color: var(--text-primary);
            font-size: 15px;
            font-weight: 500;
            transition: all 0.2s;
            font-family: inherit;
            -webkit-appearance: none;
            appearance: none;
        }
        
        .form-input:focus, .form-select:focus {
            outline: none;
            border-color: var(--accent-solid);
            box-shadow: 0 0 0 4px var(--accent-light);
            background: var(--bg-primary);
        }
        
        .form-input::placeholder { 
            color: var(--text-light);
            font-weight: 400;
        }
        
        /* Touch-Optimized Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 14px 20px;
            min-height: 48px;
            font-size: 14px;
            font-weight: 700;
            border: none;
            border-radius: var(--radius-lg);
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            white-space: nowrap;
            letter-spacing: -0.01em;
            position: relative;
            overflow: hidden;
        }
        
        .btn::before {
            content: '';
            position: absolute;
            inset: 0;
            background: rgba(255, 255, 255, 0.2);
            opacity: 0;
            transition: opacity 0.2s;
        }
        
        .btn:active::before {
            opacity: 1;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            color: white;
            box-shadow: var(--shadow-lg);
        }
        
        .btn-primary:active {
            transform: scale(0.98);
            box-shadow: var(--shadow-md);
        }
        
        .btn-secondary {
            background: var(--bg-tertiary);
            color: var(--text-primary);
            border: 2px solid var(--border);
        }
        
        .btn-secondary:active {
            background: var(--bg-hover);
        }
        
        .btn-danger {
            background: var(--danger-bg);
            color: var(--danger);
            border: 2px solid var(--danger-light);
        }
        
        .btn-danger:active {
            background: var(--danger);
            color: white;
        }
        
        .btn-sm { 
            padding: 10px 16px; 
            font-size: 13px;
            min-height: 40px;
        }
        
        .btn-icon {
            padding: 10px;
            min-width: 40px;
            min-height: 40px;
        }
        
        /* Portal Grid - Mobile First */
        .portal-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
        }
        
        /* Premium Glass Portal Card */
        .portal-card {
            background: var(--bg-primary);
            backdrop-filter: var(--glass-blur);
            -webkit-backdrop-filter: var(--glass-blur);
            border: 2px solid var(--border);
            border-radius: var(--radius-xl);
            padding: 18px;
            box-shadow: var(--shadow-lg);
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }
        
        .portal-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #3b82f6, #8b5cf6);
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .portal-card:active {
            transform: translateY(-2px);
            box-shadow: var(--shadow-2xl);
            border-color: var(--accent-solid);
        }
        
        .portal-card:active::before {
            opacity: 1;
        }
        
        .portal-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 14px;
            gap: 10px;
        }
        
        .portal-name {
            font-size: 16px;
            font-weight: 800;
            color: var(--text-primary);
            letter-spacing: -0.02em;
            line-height: 1.3;
        }
        
        .badge {
            padding: 6px 12px;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            border-radius: 8px;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }
        
        .badge-active { 
            background: var(--success-bg); 
            color: var(--success);
            border: 1px solid var(--success-light);
        }
        
        .badge-pending { 
            background: var(--warning-bg); 
            color: #92400e;
            border: 1px solid var(--warning-light);
        }
        
        .badge-error { 
            background: var(--danger-bg); 
            color: var(--danger);
            border: 1px solid var(--danger-light);
        }
        
        .portal-info {
            font-size: 12px;
            color: var(--text-secondary);
            margin-bottom: 14px;
        }
        
        .portal-info p {
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
        }
        
        .portal-info i {
            width: 16px;
            font-size: 11px;
            color: var(--text-muted);
        }
        
        .portal-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 14px;
            padding: 14px;
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
        }
        
        .stat { 
            text-align: center;
        }
        
        .stat-value {
            font-size: 26px;
            font-weight: 900;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -0.03em;
            line-height: 1;
        }
        
        .stat-label {
            font-size: 10px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 700;
            margin-top: 6px;
        }
        
        .portal-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
            margin-bottom: 14px;
        }
        
        .portal-actions .btn {
            width: 100%;
        }
        
        /* URL Box - Touch Optimized */
        .url-box {
            display: flex;
            align-items: center;
            gap: 10px;
            background: var(--bg-secondary);
            border: 2px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 12px;
        }
        
        .url-box code {
            flex: 1;
            font-size: 11px;
            color: var(--accent-solid);
            word-break: break-all;
            font-family: 'SF Mono', 'Monaco', 'Consolas', monospace;
            font-weight: 600;
            line-height: 1.4;
        }
        
        .copy-btn {
            flex-shrink: 0;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            color: white;
            border: none;
            padding: 10px 16px;
            border-radius: var(--radius);
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
            box-shadow: var(--shadow-md);
            min-height: 40px;
        }
        
        .copy-btn:active { 
            transform: scale(0.95);
            box-shadow: var(--shadow);
        }
        
        .copy-btn.copied { 
            background: var(--success);
        }
        
        /* Token Info - Compact Mobile View */
        .token-info {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 12px;
            margin-bottom: 14px;
            font-size: 11px;
        }
        
        .token-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 0;
        }
        
        .token-row:not(:last-child) {
            border-bottom: 1px solid var(--border);
            padding-bottom: 8px;
            margin-bottom: 8px;
        }
        
        .token-label {
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 600;
        }
        
        .token-label i { 
            width: 14px; 
            font-size: 10px;
            color: var(--text-muted);
        }
        
        .token-value {
            color: var(--text-primary);
            font-family: 'SF Mono', 'Monaco', 'Consolas', monospace;
            font-size: 11px;
            font-weight: 700;
        }
        
        .token-preview {
            color: var(--text-muted);
            font-size: 10px;
        }
        
        .stat-value.expired, .token-value.expired { 
            background: var(--danger);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .stat-value.warning, .token-value.warning { 
            background: var(--warning);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: var(--text-muted);
        }
        
        .empty-state i {
            font-size: 56px;
            color: var(--border-medium);
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .empty-state h3 {
            font-size: 18px;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 8px;
            letter-spacing: -0.02em;
        }
        
        .empty-state p {
            font-size: 14px;
            color: var(--text-secondary);
            font-weight: 500;
        }
        
        /* Advanced Toggle */
        .advanced-toggle {
            font-size: 13px;
            color: var(--accent-solid);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
            font-weight: 700;
            padding: 8px 14px;
            border-radius: var(--radius);
            background: var(--accent-bg);
        }
        
        .advanced-toggle:active { 
            background: var(--accent-light);
            transform: scale(0.98);
        }
        
        .advanced-fields {
            display: none;
            grid-column: 1 / -1;
            padding-top: 16px;
            border-top: 2px solid var(--border);
            margin-top: 14px;
        }
        
        .advanced-fields.show {
            display: grid;
            grid-template-columns: 1fr;
            gap: 14px;
        }
        
        /* Toast - Mobile Bottom */
        .toast {
            position: fixed;
            bottom: 80px;
            left: 50%;
            transform: translateX(-50%) translateY(100px);
            background: var(--success);
            color: white;
            padding: 14px 24px;
            border-radius: var(--radius-xl);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            font-weight: 700;
            opacity: 0;
            transition: all 0.3s cubic-bezier(0.22, 0.61, 0.36, 1);
            z-index: 2000;
            box-shadow: var(--shadow-2xl);
            max-width: calc(100% - 32px);
        }
        
        .toast.show {
            transform: translateX(-50%) translateY(0);
            opacity: 1;
        }
        
        /* Utilities */
        .text-muted { color: var(--text-muted); }
        .text-small { font-size: 12px; }
        
        /* Scrollbar - Desktop */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: var(--bg-tertiary);
        }
        
        ::-webkit-scrollbar-thumb {
            background: var(--border-medium);
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: var(--text-muted);
        }
        
        /* ===== TABLET BREAKPOINT (640px+) ===== */
        @media (min-width: 640px) {
            .navbar {
                padding: 14px var(--spacing-desktop);
            }
            
            .navbar-brand h1 {
                font-size: 18px;
            }
            
            .container {
                padding: var(--spacing-desktop);
            }
            
            .stats-overview {
                grid-template-columns: repeat(4, 1fr);
                gap: 16px;
                margin-bottom: 24px;
            }
            
            .form-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .advanced-fields.show {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .portal-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 20px;
            }
            
            .portal-actions {
                grid-template-columns: repeat(4, 1fr);
            }
        }
        
        /* ===== DESKTOP BREAKPOINT (1024px+) ===== */
        @media (min-width: 1024px) {
            .container {
                max-width: 1280px;
                padding: 32px;
            }
            
            .navbar {
                padding: 16px 32px;
            }
            
            .navbar-brand img {
                width: 36px;
                height: 36px;
            }
            
            .navbar-brand h1 {
                font-size: 19px;
            }
            
            .card:hover {
                box-shadow: var(--shadow-xl);
            }
            
            .form-grid {
                grid-template-columns: repeat(4, 1fr);
            }
            
            .advanced-fields.show {
                grid-template-columns: repeat(3, 1fr);
            }
            
            .portal-grid {
                grid-template-columns: repeat(3, 1fr);
            }
            
            .portal-card:hover {
                transform: translateY(-4px);
                box-shadow: var(--shadow-2xl);
                border-color: var(--accent-solid);
            }
            
            .portal-card:hover::before {
                opacity: 1;
            }
            
            .btn:hover {
                transform: translateY(-2px);
            }
            
            .btn-primary:hover {
                box-shadow: var(--shadow-xl);
            }
            
            .btn:active {
                transform: scale(0.98);
            }
            
            .toast {
                bottom: 32px;
                max-width: 500px;
            }
        }
        
        /* ===== LARGE DESKTOP (1280px+) ===== */
        @media (min-width: 1280px) {
            .container {
                max-width: 1400px;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="navbar-brand">
            <img src="<?= LOGO_URL ?>" alt="Logo" onerror="this.style.display='none'">
            <h1>Stalker Portal Manager</h1>
        </div>
        <div class="navbar-actions">
            <a href="login.php?logout=1">
                <i class="fas fa-sign-out-alt"></i> <span class="desktop-only">Logout</span>
            </a>
        </div>
    </nav>
    
    <div class="container">
        <!-- Stats Overview -->
        <div class="stats-overview">
            <div class="stat-card">
                <div class="stat-icon primary">
                    <i class="fas fa-satellite-dish"></i>
                </div>
                <div class="stat-value"><?= count($portals) ?></div>
                <div class="stat-label">Total Portals</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon success">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value"><?= count(array_filter($portals, fn($p) => $p['status'] === 'active')) ?></div>
                <div class="stat-label">Active</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon primary">
                    <i class="fas fa-tv"></i>
                </div>
                <div class="stat-value"><?= array_sum(array_column($portals, 'channels_count')) ?></div>
                <div class="stat-label">Channels</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon warning">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stat-value"><?= count(array_filter($portals, fn($p) => $p['status'] === 'error')) ?></div>
                <div class="stat-label">Errors</div>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?= htmlspecialchars(urldecode($message)) ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?= htmlspecialchars(urldecode($error)) ?>
            </div>
        <?php endif; ?>
        
        <!-- Add Portal -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">
                    <i class="fas fa-plus-circle"></i> Add New Portal
                </span>
                <span class="advanced-toggle" onclick="toggleAdvanced()">
                    <i class="fas fa-cog"></i> <span id="advToggleText">Advanced</span>
                </span>
            </div>
            <div class="card-body">
                <form method="POST" class="form-grid">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="action" value="add">
                    
                    <div class="form-group">
                        <label class="form-label">Portal Name *</label>
                        <input type="text" name="name" class="form-input" placeholder="My IPTV Portal" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Portal URL *</label>
                        <input type="url" name="url" class="form-input" placeholder="http://example.com/stalker_portal" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">MAC Address *</label>
                        <input type="text" name="mac" class="form-input" placeholder="00:1A:79:XX:XX:XX" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary" style="width:100%; min-height:52px;">
                            <i class="fas fa-plus"></i> Add Portal
                        </button>
                    </div>
                    
                    <!-- Advanced Fields -->
                    <div class="advanced-fields" id="advancedFields">
                        <div class="form-group">
                            <label class="form-label">SN Cut <span class="text-muted">(optional)</span></label>
                            <input type="text" name="sn_cut" class="form-input" placeholder="Auto-generated">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Device ID 1 <span class="text-muted">(optional)</span></label>
                            <input type="text" name="device_id" class="form-input" placeholder="Auto-generated">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Device ID 2 <span class="text-muted">(optional)</span></label>
                            <input type="text" name="device_id2" class="form-input" placeholder="Same as ID 1">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Signature <span class="text-muted">(optional)</span></label>
                            <input type="text" name="signature" class="form-input" placeholder="Auto-generated">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Device Model</label>
                            <select name="model" class="form-select">
                                <option value="MAG250">MAG250</option>
                                <option value="MAG254">MAG254</option>
                                <option value="MAG256">MAG256</option>
                                <option value="MAG322">MAG322</option>
                                <option value="MAG324">MAG324</option>
                                <option value="MAG420">MAG420</option>
                            </select>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Portals List -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">
                    <i class="fas fa-satellite-dish"></i> My Portals
                    <span class="text-muted text-small">(<?= count($portals) ?>)</span>
                </span>
            </div>
            <div class="card-body">
                <?php if (empty($portals)): ?>
                    <div class="empty-state">
                        <i class="fas fa-satellite-dish"></i>
                        <h3>No portals yet</h3>
                        <p>Add your first Stalker Portal above to get started</p>
                    </div>
                <?php else: ?>
                    <div class="portal-grid">
                        <?php foreach ($portals as $id => $p): ?>
                            <div class="portal-card">
                                <div class="portal-header">
                                    <span class="portal-name"><?= htmlspecialchars($p['name']) ?></span>
                                    <span class="badge badge-<?= $p['status'] ?>"><?= $p['status'] ?></span>
                                </div>
                                
                                <div class="portal-info">
                                    <p><i class="fas fa-network-wired"></i> <?= htmlspecialchars($p['mac']) ?></p>
                                    <p><i class="fas fa-tv"></i> <?= htmlspecialchars($p['model'] ?? 'MAG250') ?></p>
                                    <?php if (!empty($p['profile']['login'])): ?>
                                        <p><i class="fas fa-user"></i> <?= htmlspecialchars($p['profile']['login']) ?></p>
                                    <?php endif; ?>
                                </div>
                                
                                <?php
                                $session_file = SESSIONS_DIR . '/' . md5($p['url'] . $p['mac']) . '.json';
                                $token_info = null;
                                if (file_exists($session_file)) {
                                    $token_info = json_decode(file_get_contents($session_file), true);
                                }
                                
                                $expiry_days = null;
                                $expiry_class = '';
                                if (!empty($p['profile']['expiry'])) {
                                    $expiry_time = strtotime($p['profile']['expiry']);
                                    if ($expiry_time) {
                                        $expiry_days = floor(($expiry_time - time()) / 86400);
                                        if ($expiry_days < 0) {
                                            $expiry_class = 'expired';
                                        } elseif ($expiry_days <= 7) {
                                            $expiry_class = 'warning';
                                        }
                                    }
                                }
                                ?>
                                
                                <div class="portal-stats">
                                    <div class="stat">
                                        <div class="stat-value"><?= $p['channels_count'] ?? 0 ?></div>
                                        <div class="stat-label">Channels</div>
                                    </div>
                                    <?php if ($expiry_days !== null): ?>
                                    <div class="stat">
                                        <div class="stat-value <?= $expiry_class ?>"><?= $expiry_days < 0 ? '0' : $expiry_days ?></div>
                                        <div class="stat-label"><?= $expiry_days < 0 ? 'Expired' : 'Days Left' ?></div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="token-info">
                                    <?php if (!empty($p['profile']['expiry'])): ?>
                                    <div class="token-row">
                                        <span class="token-label"><i class="fas fa-calendar-alt"></i> Expiry</span>
                                        <span class="token-value <?= $expiry_class ?>"><?= htmlspecialchars($p['profile']['expiry']) ?></span>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($token_info): ?>
                                    <div class="token-row">
                                        <span class="token-label"><i class="fas fa-clock"></i> Token</span>
                                        <span class="token-value"><?= date('H:i:s', $token_info['timestamp'] ?? 0) ?></span>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($p['last_sync'])): ?>
                                    <div class="token-row">
                                        <span class="token-label"><i class="fas fa-sync"></i> Last Sync</span>
                                        <span class="token-value"><?= date('M d, H:i', strtotime($p['last_sync'])) ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="portal-actions">
                                    <form method="POST" style="display:contents">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="action" value="sync">
                                        <input type="hidden" name="portal_id" value="<?= $id ?>">
                                        <button type="button" class="btn btn-secondary btn-sm" onclick="confirmAction(this, 'Sync Portal', 'This will refresh the channel list from the server. It may take a few seconds.', 'Sync Now', 'primary')">
                                            <i class="fas fa-sync-alt"></i> Sync
                                        </button>
                                    </form>

                                    <button type="button" class="btn btn-secondary btn-sm" onclick='openEditModal(<?= htmlspecialchars(json_encode($p), ENT_QUOTES, "UTF-8") ?>)'>
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    
                                    <form method="POST" style="display:contents">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="action" value="rotate_id">
                                        <input type="hidden" name="portal_id" value="<?= $id ?>">
                                        <button type="button" class="btn btn-primary btn-sm" onclick="confirmAction(this, 'Generate New ID?', 'This will revoke the old M3U link immediately. You will need to update your players with the new URL.', 'Generate', 'warning')">
                                            <i class="fas fa-random"></i> New ID
                                        </button>
                                    </form>
                                    
                                    <form method="POST" style="display:contents">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="portal_id" value="<?= $id ?>">
                                        <button type="button" class="btn btn-danger btn-sm" onclick="confirmAction(this, 'Delete Portal?', 'Are you sure you want to delete this portal? This action cannot be undone.', 'Delete', 'danger')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                                
                                <?php if ($p['status'] === 'active'): ?>
                                    <div class="url-box">
                                        <code id="url-<?= $id ?>"><?= BASE_URL ?>/playlist.php?id=<?= $id ?></code>
                                        <button class="copy-btn" id="copy-btn-<?= $id ?>" onclick="copyUrl('<?= $id ?>')">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div id="toast" class="toast">
        <i class="fas fa-check-circle"></i>
        <span>URL copied to clipboard!</span>
    </div>
    
    <!-- Confirmation Modal -->
    <div id="confirmModal" class="modal-overlay">
        <div class="modal">
            <div class="modal-icon warning">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h3 class="modal-title" id="modalTitle">Confirm Action</h3>
            <p class="modal-text" id="modalText">Are you sure you want to proceed?</p>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="closeModal()">
                    Cancel
                </button>
                <button class="btn btn-primary" id="confirmBtn">
                    Confirm
                </button>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal-overlay">
        <div class="modal" style="max-width: 600px; text-align: left;">
            <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 class="modal-title" style="margin:0;">Edit Portal</h3>
                <button type="button" onclick="closeEditModal()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:18px;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form method="POST" class="form-grid" style="grid-template-columns: 1fr;">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="portal_id" id="edit_portal_id">
                
                <div class="form-group">
                    <label class="form-label">Portal Name</label>
                    <input type="text" name="name" id="edit_name" class="form-input" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Portal URL</label>
                    <input type="url" name="url" id="edit_url" class="form-input" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">MAC Address</label>
                    <input type="text" name="mac" id="edit_mac" class="form-input" required>
                </div>
                
                <div style="padding:10px 0; border-top:1px solid var(--border); margin-top:10px;">
                    <span class="advanced-toggle" onclick="document.getElementById('editAdvanced').classList.toggle('show');" style="width:fit-content;">
                        <i class="fas fa-cog"></i> Advanced Settings
                    </span>
                </div>
                
                <div class="advanced-fields" id="editAdvanced">
                    <div class="form-group">
                        <label class="form-label">Device Model</label>
                        <select name="model" id="edit_model" class="form-select">
                            <option value="MAG250">MAG250</option>
                            <option value="MAG254">MAG254</option>
                            <option value="MAG256">MAG256</option>
                            <option value="MAG322">MAG322</option>
                            <option value="MAG324">MAG324</option>
                            <option value="MAG420">MAG420</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">SN Cut</label>
                        <input type="text" name="sn_cut" id="edit_sn_cut" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Device ID</label>
                        <input type="text" name="device_id" id="edit_device_id" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Device ID 2</label>
                        <input type="text" name="device_id2" id="edit_device_id2" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Signature</label>
                        <input type="text" name="signature" id="edit_signature" class="form-input">
                    </div>
                </div>
                
                <div class="modal-actions" style="grid-template-columns: 1fr 1fr; margin-top:20px;">
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save & Sync</button>
                </div>
            </form>
        </div>
    </div>

    <style>
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
            padding: 20px;
        }

        .modal-overlay.show {
            opacity: 1;
            pointer-events: auto;
        }

        .modal {
            background: var(--bg-primary);
            border: 1px solid var(--border);
            border-radius: var(--radius-xl);
            padding: 32px;
            width: 100%;
            max-width: 400px;
            text-align: center;
            transform: scale(0.95) translateY(10px);
            transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: var(--shadow-2xl);
        }

        .modal-overlay.show .modal {
            transform: scale(1) translateY(0);
        }

        .modal-icon {
            width: 64px;
            height: 64px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin: 0 auto 20px;
        }

        .modal-icon.warning {
            background: var(--warning-bg);
            color: var(--warning);
        }

        .modal-title {
            font-size: 20px;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 10px;
        }

        .modal-text {
            font-size: 15px;
            color: var(--text-secondary);
            margin-bottom: 24px;
            line-height: 1.5;
        }

        .modal-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
    </style>

    <script>
        let currentForm = null;

        function confirmAction(btn, title, text, confirmText = 'Confirm', variant = 'primary') {
            const modal = document.getElementById('confirmModal');
            const modalTitle = document.getElementById('modalTitle');
            const modalText = document.getElementById('modalText');
            const confirmBtn = document.getElementById('confirmBtn');
            const icon = modal.querySelector('.modal-icon');
            
            // Set content
            modalTitle.textContent = title;
            modalText.textContent = text;
            confirmBtn.textContent = confirmText;
            
            // Set variant styles
            confirmBtn.className = `btn btn-${variant}`;
            icon.className = `modal-icon ${variant === 'danger' ? 'danger' : 'warning'}`;
            icon.innerHTML = `<i class="fas fa-${variant === 'danger' ? 'trash' : 'exclamation-triangle'}"></i>`;
            
            // Store form
            currentForm = btn.closest('form');
            
            // Show modal
            modal.classList.add('show');
            
            // Handle confirm
            confirmBtn.onclick = function() {
                if (currentForm) currentForm.submit();
                closeModal();
            };
        }

        function closeModal() {
            document.getElementById('confirmModal').classList.remove('show');
            currentForm = null;
        }

        function openEditModal(data) {
            document.getElementById('edit_portal_id').value = data.id;
            document.getElementById('edit_name').value = data.name;
            document.getElementById('edit_url').value = data.url;
            document.getElementById('edit_mac').value = data.mac;
            document.getElementById('edit_model').value = data.model || 'MAG250';
            document.getElementById('edit_sn_cut').value = data.sn_cut || '';
            document.getElementById('edit_device_id').value = data.device_id || '';
            document.getElementById('edit_device_id2').value = data.device_id2 || '';
            document.getElementById('edit_signature').value = data.signature || '';
            
            document.getElementById('editModal').classList.add('show');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('show');
        }

        // Close on outside click
        document.getElementById('confirmModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) closeEditModal();
        });

        function toggleAdvanced() {
            const el = document.getElementById('advancedFields');
            const text = document.getElementById('advToggleText');
            if (el.classList.contains('show')) {
                el.classList.remove('show');
                text.textContent = 'Advanced';
            } else {
                el.classList.add('show');
                text.textContent = 'Hide';
            }
        }
        
        function copyUrl(id) {
            const url = document.getElementById('url-' + id).textContent;
            const btn = document.getElementById('copy-btn-' + id);
            
            navigator.clipboard.writeText(url).then(() => {
                btn.innerHTML = '<i class="fas fa-check"></i>';
                btn.classList.add('copied');
                
                const toast = document.getElementById('toast');
                toast.classList.add('show');
                
                setTimeout(() => {
                    btn.innerHTML = '<i class="fas fa-copy"></i>';
                    btn.classList.remove('copied');
                    toast.classList.remove('show');
                }, 2000);
            }).catch(() => {
                const textarea = document.createElement('textarea');
                textarea.value = url;
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
                
                btn.innerHTML = '<i class="fas fa-check"></i>';
                btn.classList.add('copied');
                
                const toast = document.getElementById('toast');
                toast.classList.add('show');
                
                setTimeout(() => {
                    btn.innerHTML = '<i class="fas fa-copy"></i>';
                    btn.classList.remove('copied');
                    toast.classList.remove('show');
                }, 2000);
            });
        }
        
        // Prevent double-tap zoom on iOS
        let lastTouchEnd = 0;
        document.addEventListener('touchend', function (event) {
            const now = (new Date()).getTime();
            if (now - lastTouchEnd <= 300) {
                event.preventDefault();
            }
            lastTouchEnd = now;
        }, false);
        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
    </script>
</body>
</html>
