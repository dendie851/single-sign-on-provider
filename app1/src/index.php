<?php
/**
 * ===================================================================
 * APLIKASI 1 - HALAMAN UTAMA (PHP Native)
 * ===================================================================
 * Entry point aplikasi. Memeriksa apakah user sudah memiliki session
 * lokal. Jika belum, redirect ke Casdoor (IdP) untuk login.
 * ===================================================================
 */

require_once __DIR__ . '/config.php';
session_start();

// Periksa apakah user sudah login (ada session lokal)
$userData = isset($_SESSION[SESSION_USER_KEY]) ? $_SESSION[SESSION_USER_KEY] : null;

if (!$userData) {
    // ===================================================================
    // LANGKAH 1: User BELUM login → redirect ke Casdoor Authorize Endpoint
    // ===================================================================
    // Parameter OIDC:
    //   - response_type: 'code' menandakan Authorization Code Flow
    //   - client_id:     Identitas aplikasi kita di Casdoor
    //   - redirect_uri:  URL tempat Casdoor mengirimkan 'code' setelah login
    //   - scope:         'openid profile email' meminta token ID + profil
    //   - state:         CSRF token untuk mencegah serangan replay/XSRF
    // ===================================================================
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;

    $params = http_build_query([
        'response_type' => 'code',
        'client_id'     => SSO_CLIENT_ID,
        'redirect_uri'  => REDIRECT_URI,
        'scope'         => 'openid profile email',
        'state'         => $state,
    ]);

    $authUrl = OIDC_AUTH_ENDPOINT . '?' . $params;
    header('Location: ' . $authUrl);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>App 1 - PHP SSO Dashboard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; justify-content: center; align-items: center; }
        .card { background: #fff; border-radius: 16px; padding: 40px; width: 480px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
        h1 { color: #333; font-size: 24px; margin-bottom: 8px; }
        .subtitle { color: #888; font-size: 13px; margin-bottom: 24px; }
        .badge { display: inline-block; background: #667eea; color: #fff; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; margin-bottom: 20px; }
        .profile { display: flex; align-items: center; gap: 16px; padding: 16px; background: #f8f9ff; border-radius: 12px; margin-bottom: 20px; }
        .avatar { width: 56px; height: 56px; border-radius: 50%; background: #667eea; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 24px; font-weight: bold; }
        .profile-info h2 { font-size: 18px; color: #333; }
        .profile-info p { font-size: 13px; color: #888; }
        .detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px; }
        .detail-item { background: #f5f5f5; border-radius: 8px; padding: 12px; }
        .detail-item label { display: block; font-size: 11px; color: #888; text-transform: uppercase; font-weight: 600; margin-bottom: 4px; }
        .detail-item span { font-size: 14px; color: #333; word-break: break-all; }
        .logout-btn { display: block; text-align: center; padding: 12px; background: #ff4757; color: #fff; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 14px; }
        .logout-btn:hover { background: #e8414d; }
    </style>
</head>
<body>
    <div class="card">
        <div class="badge">✅ Authenticated via Casdoor SSO</div>
        <h1>App 1 — PHP Native</h1>
        <p class="subtitle">You are logged in via Central SSO (Authorization Code Flow)</p>

        <div class="profile">
            <div class="avatar"><?php echo strtoupper(substr($userData['name'] ?? 'U', 0, 1)); ?></div>
            <div class="profile-info">
                <h2><?php echo htmlspecialchars($userData['name'] ?? 'Unknown'); ?></h2>
                <p><?php echo htmlspecialchars($userData['email'] ?? 'No email'); ?></p>
            </div>
        </div>

        <div class="detail-grid">
            <div class="detail-item">
                <label>Username</label>
                <span><?php echo htmlspecialchars($userData['preferred_username'] ?? '-'); ?></span>
            </div>
            <div class="detail-item">
                <label>Sub (ID)</label>
                <span><?php echo htmlspecialchars($userData['sub'] ?? '-'); ?></span>
            </div>
            <div class="detail-item">
                <label>Issuer</label>
                <span><?php echo htmlspecialchars($userData['iss'] ?? '-'); ?></span>
            </div>
            <div class="detail-item">
                <label>Groups</label>
                <span><?php echo htmlspecialchars(implode(', ', $userData['groups'] ?? [])) ?: '-'; ?></span>
            </div>
        </div>

        <a href="logout.php" class="logout-btn">🚪 Logout (End Session)</a>
    </div>
</body>
</html>