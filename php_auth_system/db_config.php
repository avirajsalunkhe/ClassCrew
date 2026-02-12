<?php
// Database Credentials (Adjust these for your setup)
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', ''); 
define('DB_NAME', 'login_system_db');

// Google OAuth Credentials
// Get these from Google API Console -> Credentials -> OAuth 2.0 Client IDs
define('GOOGLE_CLIENT_ID', '759349791864-ftu9te4521fjgdru603s2vgmp63ri0ps.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', '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');

// Must match the Authorized redirect URI in Google Console
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$domain = $_SERVER['HTTP_HOST'];

$path = '/php_auth_system/google_callback.php';
define('GOOGLE_REDIRECT_URL', $protocol . '://' . $domain . $path);
// Start the session at the beginning of nearly every script
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
?>