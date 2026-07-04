<?php
/**
 * ===================================================================
 * CALLBACK OIDC - APLIKASI 1 (PHP Native)
 * ===================================================================
 * File ini adalah Redirect URI yang dipanggil Casdoor setelah user
 * berhasil login. Di sinilah terjadi "jabat tangan belakang"
 * (backchannel communication) untuk menukar authorization code
 * dengan access token, lalu mengambil data profil user.
 * ===================================================================
 *
 * ALUR LENGKAP AUTHORIZATION CODE GRANT FLOW:
 *   1. User membuka http://localhost:3001/ → index.php
 *   2. index.php mendeteksi session kosong → redirect ke Casdoor login
 *   3. User login di Casdoor → Casdoor redirect balik ke sini (callback.php)
 *      dengan parameter "?code=...&state=..."
 *   4. Kode di BAWAH ini menukar 'code' → 'access_token' via backchannel
 *   5. Setelah dapat token, ambil data user dari /api/userinfo
 *   6. Simpan data user di PHP Session → redirect ke index.php
 * ===================================================================
 */

// -------------------------------------------------------------------
// LANGKAH 1: Validasi State (CSRF Protection) & Parameter Code
// -------------------------------------------------------------------
session_start();

// Membandingkan 'state' dari URL dengan yang disimpan di session
// untuk mencegah serangan CSRF / replay attack.
if (!isset($_GET['state']) || !isset($_SESSION['oauth_state']) || $_GET['state'] !== $_SESSION['oauth_state']) {
    die("[SECURITY] State mismatch! Possible CSRF attack. Please login again.");
}
unset($_SESSION['oauth_state']); // State hanya valid sekali pakai

// Validasi apakah authorization code dikirim oleh Casdoor
if (!isset($_GET['code'])) {
    die("[ERROR] No authorization code received from Casdoor.");
}

$code = $_GET['code'];
require_once __DIR__ . '/config.php';

// ===================================================================
// LANGKAH 2: Backchannel — Tukar Authorization Code → Access Token & ID Token
// ===================================================================
// Aplikasi mengirim POST request ke token endpoint Casdoor.
// Karena ini backchannel (server-to-server), URL yang digunakan adalah
// URL INTERNAL Docker (http://casdoor:8000), BUKAN localhost.
// ===================================================================

$tokenParams = [
    'grant_type'    => 'authorization_code',
    'client_id'     => SSO_CLIENT_ID,
    'client_secret' => SSO_CLIENT_SECRET,
    'code'          => $code,
    'redirect_uri'  => REDIRECT_URI,
];

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => OIDC_TOKEN_ENDPOINT,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($tokenParams),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
]);
$tokenResponse = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if (curl_errno($ch)) {
    die("[ERROR] Backchannel cURL error: " . curl_error($ch));
}
curl_close($ch);

if ($httpCode !== 200) {
    die("[ERROR] Token exchange failed (HTTP $httpCode). Response: " . htmlspecialchars($tokenResponse));
}

$tokenData = json_decode($tokenResponse, true);
if (!isset($tokenData['access_token'])) {
    die("[ERROR] No access_token in response. Body: " . htmlspecialchars($tokenResponse));
}

$accessToken = $tokenData['access_token'];

// 🛠️ PERBAIKAN: Tangkap ID Token yang asli dari Casdoor untuk modal logout aman
if (isset($tokenData['id_token']) && !empty($tokenData['id_token'])) {
    $_SESSION['id_token'] = $tokenData['id_token'];
} else {
    // Fallback jika lingkup OIDC scope belum mengeluarkan id_token terpisah
    $_SESSION['id_token'] = $tokenData['access_token'];
}

// ===================================================================
// LANGKAH 3: Ambil Data Profil User dari /api/userinfo
// ===================================================================
// Dengan access_token yang valid, kita panggil endpoint userinfo
// Casdoor untuk mendapatkan klaim-klaim OIDC tentang user
// (name, email, preferred_username, groups, dll.)
// ===================================================================

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => OIDC_USERINFO_ENDPOINT,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
]);
$userInfoRaw = curl_exec($ch);
$httpCodeUser = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCodeUser !== 200 || !$userInfoRaw) {
    die("[ERROR] Failed to fetch userinfo (HTTP $httpCodeUser).");
}

$userData = json_decode($userInfoRaw, true);
if (!$userData) {
    die("[ERROR] Invalid userinfo JSON.");
}

// ===================================================================
// LANGKAH 4: Simpan Data User di PHP Session Lokal & Redirect
// ===================================================================
// Setelah data profil berhasil didapatkan, simpan di session lokal.
// Session inilah yang digunakan index.php untuk menentukan apakah
// user sudah login atau belum.
// ===================================================================

$_SESSION[SESSION_USER_KEY] = $userData;

// Mengalihkan user kembali ke halaman utama (index.php) setelah login sukses
header('Location: index.php');
exit;