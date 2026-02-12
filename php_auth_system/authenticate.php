<?php
// authenticate.php - Handles local email/password login with strict session control
require_once 'db_config.php';
require_once 'User.class.php';

const SESSION_MAX_DURATION = 86400; // 24 hours * 60 minutes * 60 seconds
const ALREADY_LOGGED_IN_ERROR = 'already_logged_in_concurrent';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        header('Location: index.php?error=missing_credentials');
        exit;
    }

    $user_obj = new User();
    // Assuming getUserByEmail now fetches: password_hash, id, current_session_id, and last_activity_timestamp
    $user_data = $user_obj->getUserByEmail($email);

    // 1. Check if user exists and password is correct
    // CRITICAL FIX: Ensure $user_data is checked first. If it is false (user not found), 
    // the condition fails gracefully, preventing PHP from throwing Array to String Conversion later.
    if ($user_data && $user_data['password_hash'] && password_verify($password, $user_data['password_hash'])) {
        
        // --- CONCURRENT SESSION CHECK ---
        $current_time = time();
        
        // NOTE: We rely on the DB having a 'last_activity_timestamp' or fetching the last activity time
        // from the activity_log table for this check. We assume the DB provides a valid timestamp string.
        $last_activity_timestamp = strtotime($user_data['last_activity_timestamp'] ?? '2000-01-01');

        // Check if a session ID is registered AND that session is less than 24 hours old.
        if ($user_data['current_session_id'] && 
            ($current_time - $last_activity_timestamp) < SESSION_MAX_DURATION) 
        {
            // Session is active/unexpired on another device/tab -> REJECT LOGIN
            header('Location: index.php?error=' . ALREADY_LOGGED_IN_ERROR);
            exit;
        }
        
        // --- OVERWRITE/CREATE NEW SESSION (Allowed, as old session is expired or cleared) ---
        $new_session_id = session_id();
        $user_id = $user_data['id'];

        // 1. Update the database to register the NEW active session ID and reset the timestamp.
        $pdo = $user_obj->getPdo();
        $stmt = $pdo->prepare("
            UPDATE users 
            SET current_session_id = ?, last_activity_timestamp = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$new_session_id, $user_id]);

        // 2. Finalize PHP session
        $_SESSION['user_id'] = $user_id;
        $_SESSION['logged_in'] = true;
        
        // 3. Track activity
        $user_obj->trackActivity($user_id, 'LOGIN', 'Successful local login');

        header('Location: profile.php');
        exit;
    }

    // Failure: Invalid credentials or account is Google-only
    header('Location: index.php?error=invalid_credentials');
    exit;

} else {
    header('Location: index.php');
    exit;
}
?>