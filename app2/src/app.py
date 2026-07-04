"""
=================================================================
APLIKASI 2 - FLASK WEB APP dengan OIDC Authorization Code Flow
=================================================================
App ini mengimplementasikan OIDC Authorization Code Grant Flow
secara NATIVE di kode Flask, tanpa reverse proxy oauth2-proxy.

ALUR:
  1. Route '/' mengecek session Flask untuk data user.
  2. Jika session kosong, redirect ke Casdoor Authorize Endpoint.
  3. Casdoor redirect balik ke '/callback' dengan parameter 'code'.
  4. Di '/callback', Flask melakukan backchannel POST ke Casdoor
     untuk menukar code → access_token, lalu ambil data userinfo.
  5. Simpan data user di Flask session → redirect ke '/'.
  6. Route '/logout' membersihkan session lokal dan redirect ke
     Casdoor logout endpoint.
=================================================================
"""

import os
import secrets
import requests
from flask import Flask, session, redirect, request, render_template_string
from functools import wraps

# ===================================================================
# KONFIGURASI
# ===================================================================
# Dalam produksi, gunakan environment variable, BUKAN hardcoded!
# ===================================================================
OIDC_CONFIG = {
    # Kredensial aplikasi di Casdoor
    "client_id": os.environ.get("SSO_CLIENT_ID", "app2-client"),
    "client_secret": os.environ.get("SSO_CLIENT_SECRET", "app2-secret-key"),

    # URL INTERNAL Docker untuk backchannel (server-to-server)
    "internal_base": os.environ.get("SSO_INTERNAL_BASE", "http://casdoor:8000"),

    # URL PUBLIC (dari browser) untuk redirect user ke Casdoor login
    "public_base": os.environ.get("SSO_PUBLIC_BASE", "http://localhost:8000"),

    # Redirect URI aplikasi kita — harus terdaftar di Casdoor
    "redirect_uri": os.environ.get("REDIRECT_URI", "http://localhost:3002/callback"),

    # Secret key untuk Flask session signing (jaga kerahasiaan!)
    "flask_secret": os.environ.get("FLASK_SECRET_KEY", "ganti-dengan-random-string-panjang"),
}

# Endpoint OIDC
OIDC_AUTH_ENDPOINT = f"{OIDC_CONFIG['public_base']}/login/oauth/authorize"
OIDC_TOKEN_ENDPOINT = f"{OIDC_CONFIG['internal_base']}/api/login/oauth/access_token"
OIDC_USERINFO_ENDPOINT = f"{OIDC_CONFIG['internal_base']}/api/userinfo"
OIDC_LOGOUT_ENDPOINT = f"{OIDC_CONFIG['public_base']}/logout"

# ===================================================================
# INISIALISASI FLASK APP
# ===================================================================
app = Flask(__name__)
app.secret_key = OIDC_CONFIG["flask_secret"]

# ===================================================================
# HTML TEMPLATE (inline untuk POC sederhana)
# ===================================================================
DASHBOARD_HTML = """
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>App 2 - Flask SSO Dashboard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: linear-gradient(135deg, #0abde3 0%, #48dbfb 100%); min-height: 100vh; display: flex; justify-content: center; align-items: center; }
        .card { background: #fff; border-radius: 16px; padding: 40px; width: 480px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
        h1 { color: #333; font-size: 24px; margin-bottom: 8px; }
        .subtitle { color: #888; font-size: 13px; margin-bottom: 24px; }
        .badge { display: inline-block; background: #0abde3; color: #fff; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; margin-bottom: 20px; }
        .profile { display: flex; align-items: center; gap: 16px; padding: 16px; background: #f0fcff; border-radius: 12px; margin-bottom: 20px; }
        .avatar { width: 56px; height: 56px; border-radius: 50%; background: #0abde3; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 24px; font-weight: bold; }
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
        <h1>App 2 — Flask Python</h1>
        <p class="subtitle">You are logged in via Central SSO (Authorization Code Flow)</p>

        <div class="profile">
            <div class="avatar">{{ user.name[:1] | upper }}</div>
            <div class="profile-info">
                <h2>{{ user.get('name', 'Unknown') }}</h2>
                <p>{{ user.get('email', 'No email') }}</p>
            </div>
        </div>

        <div class="detail-grid">
            <div class="detail-item">
                <label>Username</label>
                <span>{{ user.get('preferred_username', '-') }}</span>
            </div>
            <div class="detail-item">
                <label>Sub (ID)</label>
                <span>{{ user.get('sub', '-') }}</span>
            </div>
            <div class="detail-item">
                <label>Issuer</label>
                <span>{{ user.get('iss', '-') }}</span>
            </div>
            <div class="detail-item">
                <label>Groups</label>
                <span>{{ user.get('groups', []) | join(', ') if user.get('groups') else '-' }}</span>
            </div>
        </div>

        <a href="/logout" class="logout-btn">🚪 Logout (End Session)</a>
    </div>
</body>
</html>
"""


# ===================================================================
# DECORATOR: Cek apakah user sudah login
# ===================================================================
def login_required(f):
    """Decorator yang redirect user ke Casdoor jika session kosong."""
    @wraps(f)
    def decorated_function(*args, **kwargs):
        if 'user_data' not in session:
            return redirect_to_casdoor_login()
        return f(*args, **kwargs)
    return decorated_function


def redirect_to_casdoor_login():
    """
    Redirect browser user ke Casdoor Authorize Endpoint.
    Ini adalah LANGKAH 1 dari Authorization Code Flow.
    """
    state = secrets.token_urlsafe(32)
    session['oauth_state'] = state

    params = {
        'response_type': 'code',
        'client_id': OIDC_CONFIG['client_id'],
        'redirect_uri': OIDC_CONFIG['redirect_uri'],
        'scope': 'openid profile email',
        'state': state,
    }

    from urllib.parse import urlencode
    auth_url = f"{OIDC_AUTH_ENDPOINT}?{urlencode(params)}"
    return redirect(auth_url)


# ===================================================================
# ROUTE UTAMA
# ===================================================================
@app.route('/')
@login_required
def index():
    """Halaman dashboard setelah login."""
    user = session.get('user_data', {})
    return render_template_string(DASHBOARD_HTML, user=user)


# ===================================================================
# ROUTE CALLBACK OIDC (LANGKAH 2-4)
# ===================================================================
@app.route('/callback')
def callback():
    """
    ==================================================================
    LANGKAH 2-4: Callback dari Casdoor setelah user login.

    Di sinilah terjadi:
      2. Validasi state (CSRF protection)
      3. Backchannel: Tukar authorization code → access_token
      4. Ambil userinfo dari Casdoor
      5. Simpan di Flask session
    ==================================================================
    """

    # --- LANGKAH 2a: Validasi state (CSRF protection) ---
    state_received = request.args.get('state')
    stored_state = session.pop('oauth_state', None)

    if not state_received or not stored_state or state_received != stored_state:
        return "[SECURITY] State mismatch! Possible CSRF attack.", 400

    # --- LANGKAH 2b: Dapatkan authorization code ---
    code = request.args.get('code')
    if not code:
        return "[ERROR] No authorization code received.", 400

    # ================================================================
    # LANGKAH 3: Backchannel — Tukar Code → Access Token
    # ================================================================
    # Aplikasi (Flask) mengirim POST request langsung ke Casdoor
    # melalui jaringan internal Docker (server-to-server).
    # Credentials (client_id + client_secret) dikirim di body request.
    # ================================================================

    token_payload = {
        'grant_type': 'authorization_code',
        'client_id': OIDC_CONFIG['client_id'],
        'client_secret': OIDC_CONFIG['client_secret'],
        'code': code,
        'redirect_uri': OIDC_CONFIG['redirect_uri'],
    }

    try:
        token_resp = requests.post(
            OIDC_TOKEN_ENDPOINT,
            data=token_payload,
            timeout=10,
            headers={'Content-Type': 'application/x-www-form-urlencoded'}
        )
        token_resp.raise_for_status()
    except requests.exceptions.RequestException as e:
        return f"[ERROR] Token exchange failed: {e}", 500

    token_data = token_resp.json()
    access_token = token_data.get('access_token')
    if not access_token:
        return f"[ERROR] No access_token in response: {token_resp.text}", 500

    # ================================================================
    # LANGKAH 4: Ambil Userinfo dari Endpoint Casdoor
    # ================================================================
    # Dengan access_token yang valid, kita request data profil user.
    # ================================================================

    try:
        userinfo_resp = requests.get(
            OIDC_USERINFO_ENDPOINT,
            headers={'Authorization': f'Bearer {access_token}'},
            timeout=10
        )
        userinfo_resp.raise_for_status()
    except requests.exceptions.RequestException as e:
        return f"[ERROR] Failed to fetch userinfo: {e}", 500

    user_data = userinfo_resp.json()

    # --- LANGKAH 5: Simpan data user di Flask Session ---
    # Session ini bersifat stateful di sisi aplikasi.
    # Setelah ini, route '/' akan mendeteksi bahwa user sudah login.
    session['user_data'] = user_data
    session.permanent = True  # Perpanjang session lifetime

    return redirect('/')


# ===================================================================
# ROUTE LOGOUT
# ===================================================================
@app.route('/logout')
def logout():
    """
    Logout: Hapus session lokal Flask, lalu redirect ke Casdoor
    logout endpoint untuk mengakhiri session SSO global.
    """
    session.clear()

    # Redirect ke Casdoor logout dengan redirect_uri kembali ke sini
    logout_url = f"{OIDC_LOGOUT_ENDPOINT}?redirect_uri=http://localhost:3002/"
    return redirect(logout_url)


# ===================================================================
# ENTRY POINT
# ===================================================================
if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000, debug=False)