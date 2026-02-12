<?php
// set_password.php - Processes the new password submission
require_once 'db_config.php';
require_once 'User.class.php';

if (!isset($_SESSION['logged_in']) || !isset($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$new_password = $_POST['new_password'] ?? '';
$user = new User();

if (strlen($new_password) < 6) {
    header('Location: profile.php?error=Password_too_short');
    exit;
}

try {
    if ($user->setLocalPassword($user_id, $new_password)) {
        $user->trackActivity($user_id, 'PASSWORD_SET', 'Local password set after Google login.');
        header('Location: profile.php?status=Password_set_success');
        exit;
    } else {
        header('Location: profile.php?error=Password_set_failed');
        exit;
    }
} catch (Exception $e) {
    header('Location: profile.php?error=DB_Error');
    exit;
}
?>