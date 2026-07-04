<?php
/**
 * ===================================================================
 * LOGOUT OIDC - JALUR UTAMA (PHP Native)
 * ===================================================================
 * Menghapus session lokal aplikasi, lalu mengalihkan browser langsung 
 * ke endpoint resmi Casdoor untuk menghancurkan Session ID pusat.
 * ===================================================================
 */

require_once __DIR__ . '/config.php';

// Konfigurasi Cookie Session sebelum session dimulai (mencegah mismatch)
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => 'localhost',
    'secure'   => false,
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();

// 1. AMBIL ID_TOKEN TERLEBIH DAHULU (Sebelum session dihancurkan)
// Token ini wajib dikirim ke Casdoor agar tidak terjadi error rute 'undefined'
$idTokenHint = isset($_SESSION['id_token']) ? $_SESSION['id_token'] : '';

// 2. Bersihkan session lokal aplikasi PHP (Port 3001)
$_SESSION = [];
session_destroy();

// 3. Hapus cookie session lokal aplikasi di browser
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}

// 4. Siapkan URL Logout Resmi Casdoor dengan Parameter yang Tepat
$targetAplikasi = "http://localhost:3001/";

$logoutParams = [
    'post_logout_redirect_uri' => $targetAplikasi,
    'redirect_uri'             => $targetAplikasi,
    'returnUrl'                => $targetAplikasi
];

// Jika id_token_hint tersedia, masukkan ke dalam parameter query
if (!empty($idTokenHint)) {
    $logoutParams['id_token_hint'] = $idTokenHint;
}

$casdoorLogoutUrl = OIDC_LOGOUT_ENDPOINT . "?" . http_build_query($logoutParams);

// 5. Alihkan browser secara total ke Casdoor (Port 8000)
// Langkah ini akan menghapus session di Casdoor lalu me-redirect balik ke localhost:3001
header("Location: " . $casdoorLogoutUrl);
exit;