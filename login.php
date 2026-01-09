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
session_start();

// Security Headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

// Rate Limiting Configuration
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_TIME', 300); // 5 minutes in seconds
define('ATTEMPT_WINDOW', 900); // 15 minutes window
define('LOGIN_ATTEMPTS_FILE', __DIR__ . '/login_attempts.json');

// Initialize directories
if (!file_exists(__DIR__ . '/data')) {
    mkdir(__DIR__ . '/data', 0755, true);
}

$error = '';
$remainingAttempts = MAX_LOGIN_ATTEMPTS;
$lockoutTime = 0;

/**
 * Get client public IP address using ipify API
 */
function getClientIP() {
    static $cachedIP = null;
    
    // Return cached IP if already fetched
    if ($cachedIP !== null) {
        return $cachedIP;
    }
    
    // Try to get IP from ipify API
    try {
        $context = stream_context_create([
            'http' => [
                'timeout' => 3,
                'ignore_errors' => true
            ]
        ]);
        
        $response = @file_get_contents('https://api.ipify.org?format=json', false, $context);
        
        if ($response !== false) {
            $data = json_decode($response, true);
            if (isset($data['ip']) && filter_var($data['ip'], FILTER_VALIDATE_IP)) {
                $cachedIP = $data['ip'];
                return $cachedIP;
            }
        }
    } catch (Exception $e) {
        // Fallback if API fails
    }
    
    // Fallback to server IP detection
    $ip = $_SERVER['REMOTE_ADDR'];
    
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($forwarded[0]);
    }
    
    $cachedIP = filter_var($ip, FILTER_VALIDATE_IP) ? $ip : $_SERVER['REMOTE_ADDR'];
    return $cachedIP;
}

/**
 * Load login attempts from JSON file
 */
function loadLoginAttempts() {
    if (!file_exists(LOGIN_ATTEMPTS_FILE)) {
        return [];
    }
    
    $data = file_get_contents(LOGIN_ATTEMPTS_FILE);
    $attempts = json_decode($data, true);
    
    return is_array($attempts) ? $attempts : [];
}

/**
 * Save login attempts to JSON file
 */
function saveLoginAttempts($attempts) {
    file_put_contents(LOGIN_ATTEMPTS_FILE, json_encode($attempts, JSON_PRETTY_PRINT));
}

/**
 * Clean old attempts (older than ATTEMPT_WINDOW)
 */
function cleanOldAttempts(&$attempts) {
    $currentTime = time();
    
    foreach ($attempts as $ip => $data) {
        if (isset($data['attempts'])) {
            $data['attempts'] = array_filter($data['attempts'], function($timestamp) use ($currentTime) {
                return ($currentTime - $timestamp) < ATTEMPT_WINDOW;
            });
            
            if (empty($data['attempts']) && (!isset($data['locked_until']) || $data['locked_until'] < $currentTime)) {
                unset($attempts[$ip]);
            } else {
                $attempts[$ip] = $data;
            }
        }
    }
}

/**
 * Check if IP is locked out
 */
function isLockedOut($ip, &$attempts) {
    if (!isset($attempts[$ip])) {
        return false;
    }
    
    $data = $attempts[$ip];
    $currentTime = time();
    
    if (isset($data['locked_until']) && $data['locked_until'] > $currentTime) {
        return [
            'locked' => true,
            'locked_until' => $data['locked_until'],
            'remaining_time' => $data['locked_until'] - $currentTime
        ];
    }
    
    if (isset($data['attempts'])) {
        $recentAttempts = array_filter($data['attempts'], function($timestamp) use ($currentTime) {
            return ($currentTime - $timestamp) < ATTEMPT_WINDOW;
        });
        
        if (count($recentAttempts) >= MAX_LOGIN_ATTEMPTS) {
            $attempts[$ip]['locked_until'] = $currentTime + LOCKOUT_TIME;
            saveLoginAttempts($attempts);
            
            return [
                'locked' => true,
                'locked_until' => $attempts[$ip]['locked_until'],
                'remaining_time' => LOCKOUT_TIME
            ];
        }
    }
    
    return false;
}

/**
 * Record login attempt
 */
function recordLoginAttempt($ip, &$attempts) {
    if (!isset($attempts[$ip])) {
        $attempts[$ip] = [
            'attempts' => [],
            'first_attempt' => time(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'last_attempt_time' => date('Y-m-d H:i:s')
        ];
    }
    
    $attempts[$ip]['attempts'][] = time();
    $attempts[$ip]['last_attempt_time'] = date('Y-m-d H:i:s');
    $attempts[$ip]['total_attempts'] = count($attempts[$ip]['attempts']);
    
    saveLoginAttempts($attempts);
}

/**
 * Clear login attempts on successful login
 */
function clearLoginAttempts($ip, &$attempts) {
    if (isset($attempts[$ip])) {
        $attempts[$ip]['attempts'] = [];
        $attempts[$ip]['locked_until'] = 0;
        $attempts[$ip]['last_success'] = date('Y-m-d H:i:s');
        saveLoginAttempts($attempts);
    }
}

/**
 * Get remaining attempts for IP
 */
function getRemainingAttempts($ip, $attempts) {
    if (!isset($attempts[$ip]['attempts'])) {
        return MAX_LOGIN_ATTEMPTS;
    }
    
    $currentTime = time();
    $recentAttempts = array_filter($attempts[$ip]['attempts'], function($timestamp) use ($currentTime) {
        return ($currentTime - $timestamp) < ATTEMPT_WINDOW;
    });
    
    return max(0, MAX_LOGIN_ATTEMPTS - count($recentAttempts));
}

// Handle Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Redirect if already logged in
if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

// Load and clean attempts
$loginAttempts = loadLoginAttempts();
cleanOldAttempts($loginAttempts);

// Get client IP
$clientIP = getClientIP();

// Check if locked out
$lockoutStatus = isLockedOut($clientIP, $loginAttempts);
if ($lockoutStatus) {
    $lockoutTime = $lockoutStatus['remaining_time'];
    $minutes = ceil($lockoutTime / 60);
    $error = "Too many failed login attempts. Please try again in {$minutes} minute(s).";
    http_response_code(429);
}

// Get remaining attempts
$remainingAttempts = getRemainingAttempts($clientIP, $loginAttempts);

// Handle Login POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$lockoutStatus) {
    // CSRF Protection
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || 
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'Invalid security token. Please refresh and try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        // Input validation
        if (empty($username) || empty($password)) {
            $error = 'Username and password are required';
            recordLoginAttempt($clientIP, $loginAttempts);
        } else {
            // Rate limiting check before authentication
            $lockoutStatus = isLockedOut($clientIP, $loginAttempts);
            if ($lockoutStatus) {
                $lockoutTime = $lockoutStatus['remaining_time'];
                $minutes = ceil($lockoutTime / 60);
                $error = "Too many failed login attempts. Locked for {$minutes} minute(s).";
                http_response_code(429);
            } else {
                // Authenticate user
                if ($username === ADMIN_USERNAME && password_verify($password, ADMIN_PASSWORD_HASH)) {
                    // Successful login
                    session_regenerate_id(true);
                    
                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['admin_username'] = $username;
                    $_SESSION['admin_last_activity'] = time();
                    $_SESSION['admin_ip'] = $clientIP;
                    $_SESSION['admin_user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
                    
                    // Clear login attempts
                    clearLoginAttempts($clientIP, $loginAttempts);
                    
                    // Log successful login
                    error_log("Successful login: {$username} from {$clientIP}");
                    
                    header('Location: index.php');
                    exit;
                } else {
                    // Failed login
                    recordLoginAttempt($clientIP, $loginAttempts);
                    $remainingAttempts = getRemainingAttempts($clientIP, $loginAttempts);
                    
                    // Log failed attempt
                    error_log("Failed login attempt: {$username} from {$clientIP}");
                    
                    if ($remainingAttempts > 0) {
                        $error = "Invalid username or password. {$remainingAttempts} attempt(s) remaining.";
                    } else {
                        $error = "Too many failed attempts. Your account is locked for 5 minutes.";
                        http_response_code(429);
                    }
                    
                    // Progressive delay
                    $attemptCount = MAX_LOGIN_ATTEMPTS - $remainingAttempts;
                    usleep($attemptCount * 500000);
                }
            }
        }
    }
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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
    <title>Login - Stalker Portal Manager</title>
    <link rel="icon" type="image/png" href="<?= LOGO_URL ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-gradient: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            --bg-primary: #ffffff;
            --bg-secondary: #f8fafc;
            --glass-bg: rgba(255, 255, 255, 0.95);
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #64748b;
            --text-light: #94a3b8;
            --border: #e2e8f0;
            --accent: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
            --accent-solid: #3b82f6;
            --accent-light: #dbeafe;
            --accent-bg: #eff6ff;
            --danger: #ef4444;
            --danger-light: #fecaca;
            --danger-bg: #fee2e2;
            --warning: #f59e0b;
            --warning-light: #fde68a;
            --warning-bg: #fef3c7;
            --shadow-md: 0 4px 6px -1px rgba(15, 23, 42, 0.1), 0 2px 4px -2px rgba(15, 23, 42, 0.08);
            --shadow-lg: 0 10px 15px -3px rgba(15, 23, 42, 0.1), 0 4px 6px -4px rgba(15, 23, 42, 0.08);
            --shadow-xl: 0 20px 25px -5px rgba(15, 23, 42, 0.12), 0 8px 10px -6px rgba(15, 23, 42, 0.1);
            --shadow-2xl: 0 25px 50px -12px rgba(15, 23, 42, 0.25);
            --radius-lg: 16px;
            --radius-xl: 20px;
            --radius-2xl: 24px;
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
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
            -webkit-font-smoothing: antialiased;
            padding: 20px;
        }
        
        body::before {
            content: "";
            position: absolute;
            inset: 0;
            z-index: 0;
            background-image: radial-gradient(circle 500px at 50% 300px, rgba(16,185,129,0.35), transparent);
            pointer-events: none;
        }
        
        .wrapper {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 440px;
        }
        
        .card {
            background: var(--glass-bg);
            backdrop-filter: blur(30px) saturate(180%);
            -webkit-backdrop-filter: blur(30px) saturate(180%);
            border: 2px solid var(--border);
            border-radius: var(--radius-2xl);
            padding: 40px 32px;
            box-shadow: var(--shadow-2xl);
            opacity: 0;
            transform: translateY(30px) scale(0.95);
            animation: cardEnter 0.6s cubic-bezier(0.22, 0.61, 0.36, 1) 0.2s forwards;
            position: relative;
            overflow: hidden;
        }
        
        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--accent);
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .card:hover::before {
            opacity: 1;
        }
        
        @keyframes cardEnter {
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        
        .logo-box {
            width: 80px;
            height: 80px;
            background: var(--accent-bg);
            border-radius: var(--radius-xl);
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid var(--border);
            box-shadow: var(--shadow-lg);
            margin: 0 auto 24px;
            overflow: hidden;
            position: relative;
        }
        
        .logo-box img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            position: relative;
            z-index: 1;
        }
        
        .logo-box i {
            font-size: 36px;
            background: var(--accent);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            position: relative;
            z-index: 1;
        }
        
        .title {
            font-size: 28px;
            font-weight: 900;
            text-align: center;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 8px;
            letter-spacing: -0.03em;
        }
        
        .subtitle {
            font-size: 15px;
            color: var(--text-secondary);
            text-align: center;
            margin-bottom: 32px;
            font-weight: 500;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 10px;
            font-weight: 700;
        }
        
        .input-wrapper {
            position: relative;
        }
        
        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 16px;
            pointer-events: none;
            transition: color 0.2s;
        }
        
        .toggle-password {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 16px;
            cursor: pointer;
            transition: color 0.2s;
            padding: 8px;
            z-index: 10;
        }
        
        .toggle-password:hover {
            color: var(--accent-solid);
        }
        
        .form-group input {
            width: 100%;
            padding: 16px 48px;
            background: var(--bg-secondary);
            border: 2px solid var(--border);
            border-radius: var(--radius-lg);
            color: var(--text-primary);
            font-size: 15px;
            font-weight: 500;
            outline: none;
            transition: all 0.2s;
            font-family: inherit;
        }
        
        .form-group input:focus {
            border-color: var(--accent-solid);
            box-shadow: 0 0 0 4px var(--accent-light);
            background: var(--bg-primary);
        }
        
        .form-group input:focus ~ .input-icon {
            color: var(--accent-solid);
        }
        
        .form-group input::placeholder {
            color: var(--text-light);
            font-weight: 400;
        }
        
        .btn-login {
            width: 100%;
            padding: 16px;
            min-height: 56px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            color: #fff;
            font-size: 16px;
            font-weight: 800;
            border: none;
            border-radius: var(--radius-lg);
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 8px;
            box-shadow: var(--shadow-xl);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            position: relative;
            overflow: hidden;
        }
        
        .btn-login::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, #2563eb, #7c3aed);
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .btn-login:hover:not(:disabled)::before {
            opacity: 1;
        }
        
        .btn-login:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: var(--shadow-2xl);
        }
        
        .btn-login:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            background: var(--text-muted);
        }
        
        .btn-login span, .btn-login i {
            position: relative;
            z-index: 1;
        }
        
        .error {
            background: var(--danger-bg);
            color: #991b1b;
            padding: 14px 18px;
            border-radius: var(--radius-lg);
            font-size: 14px;
            font-weight: 600;
            text-align: center;
            margin-bottom: 24px;
            border: 1px solid var(--danger-light);
            display: flex;
            align-items: center;
            gap: 10px;
            animation: shake 0.5s ease;
            box-shadow: var(--shadow-md);
        }
        
        .error i {
            color: var(--danger);
            font-size: 16px;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
        }
        
        .warning {
            background: var(--warning-bg);
            color: #92400e;
            padding: 12px 16px;
            border-radius: var(--radius-lg);
            font-size: 13px;
            font-weight: 600;
            text-align: center;
            margin-bottom: 20px;
            border: 1px solid var(--warning-light);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .warning i {
            color: var(--warning);
        }
        
        .footer {
            text-align: center;
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-weight: 600;
        }
        
        .footer i {
            background: var(--accent);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-size: 14px;
        }
        
        #spinner { display: none; }
        .loading #btnText { display: none; }
        .loading #spinner { display: inline-block; }
        .loading #btnIcon { display: none; }
        
        @media (max-width: 480px) {
            body { padding: 16px; }
            .card { padding: 32px 24px; }
            .title { font-size: 24px; }
            .logo-box { width: 70px; height: 70px; }
        }
        
        @media (min-width: 640px) {
            .card:hover {
                transform: translateY(-4px);
                box-shadow: var(--shadow-2xl);
            }
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="card">
            <div class="logo-box">
                <img src="<?= LOGO_URL ?>" alt="Logo" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                <i class="fas fa-satellite-dish" style="display:none;"></i>
            </div>
            
            <div class="title">Stalker Portal</div>
            <div class="subtitle">Secure Manager Login</div>
            
            <?php if ($error): ?>
                <div class="error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>
            
            <?php if (!$lockoutStatus && $remainingAttempts < MAX_LOGIN_ATTEMPTS && $remainingAttempts > 0): ?>
                <div class="warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span><?= $remainingAttempts ?> attempt(s) remaining before lockout</span>
                </div>
            <?php endif; ?>
            
            <form method="POST" id="loginForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                
                <div class="form-group">
                    <label for="username">Username</label>
                    <div class="input-wrapper">
                        <input 
                            type="text" 
                            name="username" 
                            id="username" 
                            placeholder="Enter your username" 
                            required 
                            autofocus
                            autocomplete="username"
                            <?= $lockoutStatus ? 'disabled' : '' ?>
                        >
                        <i class="fas fa-user input-icon"></i>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-wrapper">
                        <input 
                            type="password" 
                            name="password" 
                            id="password" 
                            placeholder="Enter your password" 
                            required
                            autocomplete="current-password"
                            <?= $lockoutStatus ? 'disabled' : '' ?>
                        >
                        <i class="fas fa-lock input-icon"></i>
                        <i class="fas fa-eye toggle-password" id="togglePassword" title="Show password"></i>
                    </div>
                </div>
                
                <button type="submit" class="btn-login" id="loginBtn" <?= $lockoutStatus ? 'disabled' : '' ?>>
                    <span id="btnText"><?= $lockoutStatus ? 'Locked' : 'Sign In' ?></span>
                    <i class="fas fa-spinner fa-spin" id="spinner"></i>
                    <i class="fas fa-arrow-right" id="btnIcon" style="<?= $lockoutStatus ? 'display:none;' : '' ?>"></i>
                    <?php if ($lockoutStatus): ?>
                        <i class="fas fa-lock"></i>
                    <?php endif; ?>
                </button>
            </form>
            
            <div class="footer">
                <i class="fas fa-shield-halved"></i>
                <span>Secure Admin Access</span>
            </div>
        </div>
    </div>
    
    <script>
        // Password visibility toggle
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        
        if (togglePassword && passwordInput) {
            togglePassword.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                
                this.classList.toggle('fa-eye');
                this.classList.toggle('fa-eye-slash');
                
                this.setAttribute('title', type === 'password' ? 'Show password' : 'Hide password');
            });
        }
        
        // Form submission
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('loginBtn');
            const btnIcon = document.getElementById('btnIcon');
            
            if (!btn.disabled) {
                btn.classList.add('loading');
                btn.disabled = true;
                if (btnIcon) btnIcon.style.display = 'none';
            }
        });
        
        // Auto-unlock countdown
        <?php if ($lockoutStatus): ?>
        let remainingSeconds = <?= $lockoutTime ?>;
        const btnText = document.getElementById('btnText');
        
        function updateCountdown() {
            if (remainingSeconds > 0) {
                const minutes = Math.floor(remainingSeconds / 60);
                const seconds = remainingSeconds % 60;
                btnText.textContent = `Locked (${minutes}:${seconds.toString().padStart(2, '0')})`;
                remainingSeconds--;
                setTimeout(updateCountdown, 1000);
            } else {
                window.location.reload();
            }
        }
        
        updateCountdown();
        <?php endif; ?>
        
        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>
</html>
