<?php
// google_callback.php - Handles Google OAuth response with session control

require_once 'db_config.php';
require_once 'vendor/autoload.php';
require_once 'User.class.php';

const SESSION_MAX_DURATION = 86400; // 24 hours in seconds
const ALREADY_LOGGED_IN_ERROR = 'already_logged_in_concurrent';

$client = new Google_Client();
$client->setClientId(GOOGLE_CLIENT_ID);
$client->setClientSecret(GOOGLE_CLIENT_SECRET);
$client->setRedirectUri(GOOGLE_REDIRECT_URL);

// NOTE: Requesting offline access and consent is crucial for persistent Refresh Tokens
$client->setAccessType('offline'); 
$client->setPrompt('consent');     
$client->addScope(Google_Service_Drive::DRIVE); // Assuming full Drive scope is required
$client->addScope('email');
$client->addScope('profile'); 

if (isset($_GET['code'])) {
    try {
        $user = new User();
        
        // 1. Exchange authorization code for token
        $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
        $client->setAccessToken($token);

        $refreshToken = $token['refresh_token'] ?? null;
        
        // 2. Get user info
        $oauth = new Google_Service_Oauth2($client);
        $userInfo = $oauth->userinfo->get();
        
        $user_data = [
            'id' => $userInfo->id,
            'email' => $userInfo->email,
            'given_name' => $userInfo->givenName,
            'family_name' => $userInfo->familyName,
            'picture' => $userInfo->picture
        ];

        // 3. Database operation: Upsert user and get existing session data
        // Assumes upsertGoogleUser handles fetching existing session data for the user_id
        $user_id = $user->upsertGoogleUser($user_data, $refreshToken); 
        $user_data_session_check = $user->getUserById($user_id); // Re-fetch all fields for accurate check
        
        // --- 4. CONCURRENT SESSION CHECK ---
        $current_time = time();
        $last_activity_timestamp = strtotime($user_data_session_check['last_activity_timestamp'] ?? '2000-01-01');

        if ($user_data_session_check['current_session_id'] && 
            ($current_time - $last_activity_timestamp) < SESSION_MAX_DURATION) 
        {
            // Session is active/unexpired -> REJECT LOGIN
            header('Location: index.php?error=' . ALREADY_LOGGED_IN_ERROR);
            exit;
        }

        // --- 5. FINALIZE LOGIN AND UPDATE DB STATE ---
        $new_session_id = session_id();

        // Update database with NEW session ID and timestamp (allowed)
        $pdo = $user->getPdo();
        $stmt = $pdo->prepare("UPDATE users SET current_session_id = ?, last_activity_timestamp = NOW() WHERE id = ?");
        $stmt->execute([$new_session_id, $user_id]);
        
        // Finalize PHP session
        $_SESSION['user_id'] = $user_id;
        $_SESSION['logged_in'] = true;
        $_SESSION['access_token'] = $token; 

        // Fetch and set Drive Quota (for profile.php display)
        $user->fetchAndSetDriveQuota($client);

        // Track activity
        $user->trackActivity($user_id, 'LOGIN', 'Successful login via Google');

        header('Location: profile.php'); 
        exit;

    } catch (Exception $e) {
        error_log("Google OAuth Error: " . $e->getMessage());
        header('Location: index.php?error=oauth_failed');
        exit;
    }
} else {
    // If user manually navigates here without a 'code'
    header('Location: index.php');
    exit;
}
?>