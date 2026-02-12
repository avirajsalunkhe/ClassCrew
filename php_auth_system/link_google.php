<?php
// link_google.php - Page to initiate linking Google Drive access to a local account
require_once 'db_config.php';
require_once 'vendor/autoload.php';
require_once 'User.class.php';

// Check if user is logged in locally
if (!isset($_SESSION['logged_in']) || !isset($_SESSION['user_id'])) {
    header('Location: index.php?error=relogin_required');
    exit;
}

$user = new User();
$user_data = $user->getUserWithTokens($_SESSION['user_id']); // Assumes this fetches google_refresh_token

// Check if account is already linked (token exists in DB)
if (!empty($user_data['google_refresh_token'])) {
    header('Location: profile.php?status=Already_Linked');
    exit;
}

// 1. Initialize Google Client for LINKING
$client = new Google_Client();
$client->setClientId(GOOGLE_CLIENT_ID);
$client->setClientSecret(GOOGLE_CLIENT_SECRET);

// NOTE: Use a unique redirect URI for linking to keep flows separate
// This URI MUST be registered in your Google Cloud Console!
$link_redirect_uri = 'http://' . $_SERVER['HTTP_HOST'] . '/php_auth_system/link_google_callback.php';
$client->setRedirectUri($link_redirect_uri);

// Crucial: Request offline access and force consent to get the persistent Refresh Token
$client->setAccessType('offline'); 
$client->setPrompt('consent');     
// Requesting the full Drive scope
$client->addScope(Google_Service_Drive::DRIVE); // Keep existing for Drive Manager
$client->addScope(Google_Service_Drive::DRIVE_APPDATA); // Add for hidden DFS chunks
$client->addScope('email');

$google_link_url = $client->createAuthUrl();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Link Google Drive</title>
    <style>
        body { font-family: Arial, sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; background-color: #f4f4f4; }
        .container { background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 0 15px rgba(0, 0, 0, 0.1); max-width: 500px; width: 90%; text-align: center; }
        h2 { color: #333; border-bottom: 2px solid #ccc; padding-bottom: 10px; margin-bottom: 20px;}
        p { color: #555; }
        .google-btn { 
            display: inline-block; 
            text-align: center; 
            padding: 12px 20px; 
            background-color: #4285F4; /* Google Blue */
            color: white; 
            text-decoration: none; 
            border-radius: 4px; 
            margin-top: 20px;
            font-size: 1.1em;
            font-weight: bold;
            transition: background-color 0.3s;
        }
        .google-btn:hover {
            background-color: #357ae8;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>🔗 Link Your Google Drive</h2>
        <p>Your local account (<strong><?php echo htmlspecialchars($user_data['email']); ?></strong>) needs permission to access Drive features.</p>
        <p>Click the button below to authorize **Matoshree** to access your Drive files. This only needs to be done once.</p>
        
        <a href="<?php echo $google_link_url; ?>" class="google-btn">
            Connect & Grant Drive Access
        </a>

        <p style="margin-top: 30px;"><a href="profile.php">Continue to Profile (Without Drive Access)</a></p>
    </div>
</body>
</html>