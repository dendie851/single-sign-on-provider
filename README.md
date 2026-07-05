# Modern Single Sign-On (SSO) with Legacy LDAP and Casdoor

**Stop Forcing Your Users to Log In 10 Times! How We Saved Our Team from Password Fatigue using Casdoor and Legacy LDAP.**

Imagine this: your company is growing fast. You launch an internal HR app, then a wiki, a project management tool, and a couple of custom-built internal portals. Sounds great, right? 

But behind the scenes, a disaster is brewing. Your users are frustrated because they have to remember 10 different passwords. They have to register on every single app. Even worse, your IT administrators are losing their minds manually adding, deleting, and updating user accounts across multiple database tables. User profiles are duplicated everywhere, and stale, inactive accounts stay open forever because someone forgot to clean them up.

Fortunately, there was a hidden treasure. The organization already had a legacy **OpenLDAP** server. This server was the gold standard—it contained the most complete, accurate, and secure record of every single employee in the organization. 

But how do you connect modern applications (like a PHP site and a Python Flask app) to a legacy LDAP server without writing messy LDAP queries inside every single codebase? 

This is the story of how we unified our entire organization's identity system. By deploying **Casdoor** as a modern Identity Provider (IdP) and linking it directly to our legacy **LDAP server**, we built a seamless **Single Sign-On (SSO) and Single Sign-Out** system. No more duplicated users, no more password fatigue, and 100% security.

Here is exactly how we did it.

## Table of Contents
- [Modern Single Sign-On (SSO) with Legacy LDAP and Casdoor](#modern-single-sign-on-sso-with-legacy-ldap-and-casdoor)
  - [Table of Contents](#table-of-contents)
  - [1. System Architecture](#1-system-architecture)
    - [Architectural Components](#architectural-components)
    - [Authentication Steps (1-6) Breakdown](#authentication-steps-1-6-breakdown)
  - [2. Integration Flow](#2-integration-flow)
    - [Key Flows](#key-flows)
  - [3. Step-by-Step Implementation Evidence](#3-step-by-step-implementation-evidence)
    - [Step 1: Docker Deployment](#step-1-docker-deployment)
    - [Step 2: Legacy LDAP Server Configuration](#step-2-legacy-ldap-server-configuration)
    - [Step 3: Setting Up Casdoor and Integrating LDAP](#step-3-setting-up-casdoor-and-integrating-ldap)
    - [Step 4: Registering Applications in Casdoor](#step-4-registering-applications-in-casdoor)
    - [Step 5: Testing Single Sign-On \& Session Verification (App 1 - PHP)](#step-5-testing-single-sign-on--session-verification-app-1---php)
    - [Step 6: Testing Single Sign-On (App 2 - Python Flask)](#step-6-testing-single-sign-on-app-2---python-flask)

---

## 1. System Architecture

To solve this identity nightmare, we designed a centralized authentication architecture. Rather than allowing our PHP application (App 1) and Python Flask application (App 2) to connect directly to the LDAP server or manage their own users, we inserted **Casdoor** in the middle as the Identity Provider (IdP).

Here is the high-level architecture diagram of our system:

![System Architecture](design/arisitekur.png)

### Architectural Components
1. **Legacy LDAP Server (OpenLDAP)**: The single source of truth (database) for all employee credentials and organization groups.
2. **Casdoor (Identity Provider / IdP)**: A modern, web-portal-based authentication engine. It communicates with LDAP to authenticate users and sync directories, then acts as an OAuth 2.0 / OIDC provider for applications.
3. **Application 1 (PHP App)** & **Application 2 (Flask App)**: Client applications that offload authentication to Casdoor via standard OpenID Connect (OIDC) protocols.

### Authentication Steps (1-6) Breakdown
The unified login process consists of six main steps, which coordinate user-agent requests and backchannel server calls:

* **Step 1: Auth Check & Redirection**
  When an unauthenticated user attempts to visit the application, the local session check fails. The application immediately redirects the user's browser to the Casdoor OIDC Authorize endpoint:
  ```
  http://casdoorserver/login/oauth/authorize?client_id=<CLIENT_ID>&redirect_uri=<REDIRECT_CALLBACK>&response_type=code&scope=read
  ```
  This request sends the application's unique `client_id` and registered redirect URI.

* **Step 2: Credential Verification via LDAP**
  Casdoor intercepts the authorization request, maps it to the corresponding application profile, and renders the unified `Form Login Apps`. When the user enters their username and password, Casdoor queries the legacy LDAP server in real-time to verify the credentials directly (`Verifikasi user & password direct to ldap`).

* **Step 3: Authorization Code Callback (Login Success)**
  Upon successful credential verification, Casdoor creates a short-lived authorization code. It then triggers a browser redirect back to the client application's callback URL (`Callback Auth`), indicating a successful login state.

* **Step 4: Token Exchange (Backchannel Request)**
  The application's backend server receives the authorization code via the callback URL. Without exposing credentials to the client browser, the backend server initiates a server-to-server POST request to Casdoor's token endpoint:
  ```
  http://casdoorserver/api/login/oauth/access_token
  ```
  This backchannel query trades the authorization code for a secure JSON Web Token (JWT) containing access permissions.

* **Step 5: Fetching User Profile Claims**
  Using the newly acquired JWT access token, the application's backend calls Casdoor's OIDC profile endpoint:
  ```
  http://casdoorserver/api/userinfo
  ```
  Casdoor responds with the user's mapped details (such as username, email, department, and role) derived directly from the LDAP directory attributes.

* **Step 6: Show App & Establish Session**
  Once user details are received, the application registers a local session (e.g., native PHP `$_SESSION` or Flask `session`) and renders the fully authenticated application layout (`Show App`) to the client. The system also supports Single Sign-Out by redirecting the user to:
  ```
  http://casdoorserver/login/oauth/logout
  ```
  which clears the SSO session cookie globally, securing all integrated client applications at once.

---

## 2. Integration Flow

The integration between Casdoor and the legacy LDAP system follows a structured setup and runtime synchronization flow.

Here is the flow diagram illustrating how integration and login authentication are configured and executed:

![Integration Flow](design/flow-setting-integration-on-casedor.png)

### Key Flows
- **Configuration & Sync Flow**: The Administrator links Casdoor to the LDAP host. Casdoor runs an automatic synchronization job to import users and map LDAP Organization Units (OUs) to Casdoor organizations.
- **Login Flow (OIDC Authorization Code Grant)**:
  1. A user attempts to visit App 1 or App 2.
  2. The application detects no active session and redirects the user to Casdoor.
  3. The user inputs credentials on the Casdoor login page.
  4. Casdoor verifies these credentials against the legacy LDAP server in real-time.
  5. Upon success, Casdoor redirects the user back to the application with an authorization code.
  6. The application exchanges this code for a JWT token via a backchannel call, reads user profile claims, and logs the user in.
- **Single Sign-Out Flow**: When a user logs out of one app, they are redirected to Casdoor's logout endpoint, which invalidates the SSO session across all connected applications.

---

## 3. Step-by-Step Implementation Evidence

We successfully built, deployed, and tested this system. Below is the complete step-by-step evidence of our setup.

### Step 1: Docker Deployment
We containerized all services using Docker Compose to ensure a reproducible environment containing MySQL, Casdoor, OpenLDAP, phpLDAPadmin, PHP App 1, and Python App 2.

![Docker Deployment](ss/1-step-1-docker-deployment.png)
*Figure 1: Deploying all services via docker-compose up, spin up the entire infrastructure.*

---

### Step 2: Legacy LDAP Server Configuration
First, we verified our legacy LDAP server state and structured the organizational units (OUs), groups, and user accounts using phpLDAPadmin.

![LDAP GUI Login](ss/2-step-2-ldap-gui-login.png)
*Figure 2: Logging into phpLDAPadmin to manage the legacy LDAP server.*

![LDAP Organisation Unit](ss/3-step-2-ldap-oragnisation-unit.png)
*Figure 3: Inspecting the Base DN (`dc=democorp,dc=local`) and organizational units inside the directory.*

![LDAP UT User](ss/4-step-2-ldap-ut-user.png)
*Figure 4: Creating a test user organizational unit (OU) named `people` for user storage.*

![LDAP UT List 1](ss/5-step-2-ldap-ut-list.png)
*Figure 5: Listing active LDAP entries and organizational structures.*

![LDAP UT List 2](ss/5-step-2-ldap-ut-list-2.png)
*Figure 6: Verifying child entries under the default organizational tree.*

![LDAP Group Create](ss/6-step-2-ldap-group-create.png)
*Figure 7: Creating LDAP groups (e.g., `posixGroup`) to group users for role management.*

![LDAP Group List 1](ss/7-step-2-ldap-group-list.png)
*Figure 8: Reviewing created groups under the directory tree.*

![LDAP Group List 2](ss/8-step-2-ldap-group-list-2.png)
*Figure 9: Confirming group memberships and attributes.*

![LDAP Create User](ss/9-step-2-ldap-ut-list-create-user.png)
*Figure 10: Adding a new user account with attributes like `uid`, `cn`, `sn`, and passwords into the LDAP tree.*

![LDAP User Verification](ss/10-step-2-ldap-ut-list-user.png)
*Figure 11: Confirming the newly created user is active and readable in the database.*

![LDAP Best Practice Structure](ss/11-step-2-ldap-best-practice-structtur.png)
*Figure 12: Final view of the well-structured LDAP directory schema following system best practices.*

---

### Step 3: Setting Up Casdoor and Integrating LDAP
Next, we configured Casdoor to connect to our legacy LDAP server as a primary provider, syncing user accounts automatically.

![Casdoor Login](ss/12-step-3-casdoor-login.png)
*Figure 13: Accessing the Casdoor administrator login page.*

![Casdoor Dashboard](ss/13-step-3-casdoor-dashboard.png)
*Figure 14: Navigating the Casdoor main dashboard interface.*

![Add LDAP Integration 1](ss/14-step-3-casdoor-add-organitaion-integration-to-ldap.png)
*Figure 15: Configuring a new LDAP provider in Casdoor with Host, Port, BaseDN, Admin credentials, and attribute mappings.*

![Add LDAP Integration 2](ss/15-step-3-casdoor-add-organitaion-integration-to-ldap-2.png)
*Figure 16: Adjusting specific synchronization parameters for the LDAP provider.*

![Add LDAP Integration 3](ss/16-step-3-casdoor-add-organitaion-integration-to-ldap-3.png)
*Figure 17: Finalizing provider connection configurations.*

![LDAP Sync Settings](ss/17-step-3-casdoor-add-organitaion-integration-to-syncronitation.png)
*Figure 18: Launching the synchronization process in Casdoor to import all LDAP users.*

![LDAP Sync Success](ss/18-step-3-casdoor-add-organitaion-integration-to-syncronitation-success-sycront.png)
*Figure 19: Verification screen showing that LDAP users were successfully synchronized into the Casdoor organization database.*

![User Grouping 1](ss/19-step-3-casdoor-add-organitaion-integration-to-pengelompokan-user-berdasarkan-organisation.png)
*Figure 20: Assigning user objects to their respective departments/organizations within Casdoor.*

![User Grouping 2](ss/20-step-3-casdoor-add-organitaion-integration-to-pengelompokan-user-berdasarkan-organisation-2.png)
*Figure 21: Finalizing and verifying mapped organization-level user groupings.*

---

### Step 4: Registering Applications in Casdoor
We registered our two internal applications (App 1 and App 2) as OAuth/OIDC clients in Casdoor.

![Casdoor App Integration List](ss/21-step-4-casdoor-integration-application.png)
*Figure 22: Accessing the application configuration screen inside Casdoor.*

![Add App 1 Config](ss/22-step-4-casdoor-integration-application-add-application-ke-1.png)
*Figure 23: Configuring App 1 client credentials, homepage URLs, and redirect callback URLs.*

![Add App 2 Config](ss/22-step-4-casdoor-integration-application-add-application-ke-2.png)
*Figure 24: Configuring App 2 details, including client secrets and endpoint mappings.*

![List Applications](ss/23-step-4-casdoor-integration-application-list-application.png)
*Figure 25: Viewing the registered application list containing both App 1 and App 2.*

![Set Token Expiration](ss/24-step-4-casdoor-integration-application-set-token-expired.png)
*Figure 26: Adjusting Token Expiration settings for JWT Access Tokens and Refresh Tokens.*

![URL Login Redirect Configuration](ss/25-step-4-casdoor-integration-application-url-login-redirect-ke-casedoor.png)
*Figure 27: Determining the correct OIDC authorization URL to initiate the SSO login redirect flow.*

---

### Step 5: Testing Single Sign-On & Session Verification (App 1 - PHP)
We tested the end-to-end authentication flow on App 1 (PHP) to verify that login redirect, LDAP authentication, token exchange, and local sessions worked seamlessly.

![App 1 Login Redirection](ss/26-step-5-casdoor-app1-test-login.png)
*Figure 28: Accessing App 1, which immediately redirects the user to the unified Casdoor portal.*

![App 1 Login Success](ss/27-step-5-casdoor-app1-test-login-berhasil.png)
*Figure 29: Logging in with LDAP credentials, redirected back to App 1 showing dashboard access.*

![SSO Client ID Mapping](ss/28-step-5-casdoor-app1-mapping-sso-client-id.png)
*Figure 30: Verifying how client requests are mapped to Casdoor OAuth client ID.*

![Token and Profile Verification](ss/29-step-5-casdoor-app1-check-verifikasi-login-casdoor-berhasil-get-token-jwt-get-info-user.png)
*Figure 31: Verifying that App 1 successfully retrieved the JWT token and user details from the OIDC userinfo endpoint.*

![Verify User Session](ss/30-step-5-casdoor-app1-check-session-user-login.png)
*Figure 32: Confirming local PHP session variables are set and matched with the SSO user.*

![Verify Access Token](ss/31-step-5-casdoor-app1-check-token-user-login.png)
*Figure 33: Inspecting raw JWT token values retrieved from the authorization callback.*

![JWT Details Analysis](ss/32-step-5-casdoor-app1-check-token-user-login-detail.png)
*Figure 34: Decrypting/reading the JWT token payload (showing sub, org, and email fields).*

---

### Step 6: Testing Single Sign-On (App 2 - Python Flask)
Finally, we opened App 2 in the same browser session to ensure the user was logged in automatically without needing to re-type their credentials (SSO).

![App 2 Test Login](ss/33-step-6-casdoor-app2-test-login.png)
*Figure 35: Accessing App 2. It detects the active Casdoor session and completes login seamlessly.*

![App 2 Check Session](ss/34-step-6-casdoor-app2-check-session.png)
*Figure 36: Verifying Flask session variables containing user info mapped from the LDAP sync.*

![App 2 Check Token](ss/34-step-6-casdoor-app2-check-token.png)
*Figure 37: Verifying OIDC claims and tokens generated specifically for App 2's client configuration.*
