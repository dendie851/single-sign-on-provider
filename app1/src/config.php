<?php
/**
 * ===================================================================
 * KONFIGURASI OIDC - APLIKASI 1 (PHP Native)
 * ===================================================================
 * File ini berisi kredensial & endpoint OIDC.
 * Dalam produksi, simpan nilai sensitif di environment variable,
 * bukan hardcoded seperti di bawah ini (POC purpose only).
 * ===================================================================
 */

// --- Konfigurasi SSO Casdoor (OIDC Provider) ---
define('SSO_CLIENT_ID',     '4e3d300005c2a2e0e3e6');
define('SSO_CLIENT_SECRET', '6baf7dc0c109125b231b8c5f52ebfb3922b911dc');

// URL internal Docker untuk backchannel communication (token exchange & userinfo)
define('SSO_INTERNAL_BASE', 'http://casdoor:8000');

// URL publik (browser) untuk redirect ke halaman login Casdoor
define('SSO_PUBLIC_BASE',   'http://localhost:8000');

// Redirect URI tempat Casdoor mengirimkan authorization code setelah login
define('REDIRECT_URI',      'http://localhost:3001/callback.php');

// Nama session untuk menyimpan data user yang sudah login
define('SESSION_USER_KEY',  'sso_user_data');

// ===================================================================
// ENDPOINT OIDC - Casdoor menyediakan semuanya via OpenID Discovery
// ===================================================================
define('OIDC_AUTH_ENDPOINT',      SSO_PUBLIC_BASE . '/login/oauth/authorize');
define('OIDC_TOKEN_ENDPOINT',     SSO_INTERNAL_BASE . '/api/login/oauth/access_token');
define('OIDC_USERINFO_ENDPOINT',  SSO_INTERNAL_BASE . '/api/userinfo');
define('OIDC_LOGOUT_ENDPOINT',    SSO_PUBLIC_BASE . '/login/oauth/logout');

