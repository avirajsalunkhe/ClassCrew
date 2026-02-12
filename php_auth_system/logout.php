<?php
// logout.php - Finalized Logic for Concurrent Session Control
require_once 'db_config.php';
require_once 'User.class.php';

if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $user = new User();
    
    // --- 1. CRITICAL: Clear session state AND update last activity time ---
    try {
        $pdo = $user->getPdo();
        
        // Use a single statement to clear the session ID and update the timestamp
        // This explicitly marks the time the user logged out, resetting the 24-hour clock.
        $stmt = $pdo->prepare("
            UPDATE users 
            SET current_session_id = NULL, last_activity_timestamp = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$user_id]);
    } catch (Exception $e) {
        // Log error but do not halt logout process
        error_log("Failed to clear current_session_id: " . $e->getMessage());
    }

    // --- 2. Track logout activity ---
    // Ensure the user still exists in the database before tracking
    if ($user->getUserById($user_id)) { 
        $user->trackActivity($user_id, 'LOGOUT', 'User initiated logout');
    }
}

// --- 3. Destroy PHP Session ---
// These must be the last actions before the redirect
session_unset();
session_destroy();

header('Location: index.php');
exit;
?>