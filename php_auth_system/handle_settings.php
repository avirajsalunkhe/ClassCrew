<?php
// handle_settings.php - Processes user preference changes (Password Reset, Messaging, etc.)

session_start();
require_once 'db_config.php';
require_once 'User.class.php';

// Check user authentication
if (!isset($_SESSION['logged_in']) || !isset($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php?error=auth_required');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_obj = new User();
$action = $_GET['action'] ?? null;
$redirect_location = 'admin_dashboard.php'; // Default redirect location

// --- Action: Reset Password ---
if ($action === 'reset_password') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';

    // 1. Fetch current user details including hash
    $user_details = $user_obj->getUserById($user_id);
    
    // Safety check for password length
    if (strlen($new_password) < 6) {
        $message = "error=Password_too_short";
        goto redirect;
    }

    if ($user_details && !empty($user_details['password_hash'])) {
        // 2. Verify current password
        if (password_verify($current_password, $user_details['password_hash'])) {
            
            // 3. Update password hash in database
            if ($user_obj->setLocalPassword($user_id, $new_password)) {
                $user_obj->trackActivity($user_id, 'PASSWORD_UPDATE', 'Local password successfully updated.');
                $message = "status=Password_updated_success";
            } else {
                $message = "error=Database_update_failed";
            }
        } else {
            $message = "error=Current_password_incorrect";
        }
    } else {
        // Handle case where user is trying to set password for the first time (e.g., Google login user)
        // Note: This specific flow is typically handled by set_password.php (for first login)
        // but we allow updating here if the current password check passes.
        $message = "error=User_password_not_found";
    }
} 

// --- Action: Message Admin (Assuming Admin contacts are static, or use chat_console) ---
elseif ($action === 'message_admin') {
    $message_content = trim($_POST['message'] ?? '');
    
    if (empty($message_content)) {
        $message = "error=Message_empty";
        goto redirect;
    }
    
    // In a real system, this would queue an email or insert a message into an admin queue.
    // For this demonstration, we'll log it as an activity.
    $user_obj->trackActivity($user_id, 'ADMIN_MESSAGE', 'Admin contact message sent.');
    error_log("ADMIN CONTACT from User ID {$user_id}: {$message_content}");
    
    $message = "status=Message_sent";
}

redirect:
header("Location: $redirect_location?$message");
exit;
?>