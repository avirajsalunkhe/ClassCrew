<?php
// link_google_callback.php - Stores tokens in DB
require_once 'db_config.php';
require_once 'vendor/autoload.php';
require_once 'User.class.php';

// Check if user is locally logged in
if (!isset($_SESSION['logged_in']) || !isset($_SESSION['user_id'])) {
    header('Location: index.php?error=session_expired');
    exit;
}

$link_redirect_uri = 'http://' . $_SERVER['HTTP_HOST'] . '/php_auth_system/link_google_callback.php';
$user_id = $_SESSION['user_id'];
$user = new User();

if (isset($_GET['code'])) {
    try {
        $client = new Google_Client();
        $client->setClientId(GOOGLE_CLIENT_ID);
        $client->setClientSecret(GOOGLE_CLIENT_SECRET);
        $client->setRedirectUri($link_redirect_uri);
        
        // 1. Exchange the code for the token array
        $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
        
        // 2. Get the linked Google account info
        $client->setAccessToken($token);
        $oauth = new Google_Service_Oauth2($client);
        $userInfo = $oauth->userinfo->get();
        
        // 3. Store the refresh token in the database
        $refreshToken = $token['refresh_token'] ?? null;
        
        if ($refreshToken) {
            $user->linkGoogleAccount($user_id, $refreshToken, $userInfo->email);
            $user->trackActivity($user_id, 'GOOGLE_LINK', 'Google Drive account linked successfully.');
            
            // Set the new Access Token in the session for immediate use
            $_SESSION['access_token'] = $token; 
            
            header('Location: profile.php?status=Google_Linked');
            exit;
        } else {
            // This happens if access_type=offline or prompt=consent was missed.
            header('Location: link_google.php?error=No_Refresh_Token');
            exit;
        }

    } catch (Exception $e) {
        error_log("Google Linking Error: " . $e->getMessage());
        header('Location: link_google.php?error=API_Error');
        exit;
    }
} else {
    header('Location: link_google.php');
    exit;
}
?>