<?php
// reset_password.php - Handles password request, token validation, and password update
require_once 'db_config.php';
require_once 'User.class.php';

$user_obj = new User();
$message = '';
$show_reset_form = false;
$token_email = '';
$pdo = $user_obj->getPdo(); // Assumes getPdo() returns the PDO connection instance

// --- Helper function for email (REPLACE THIS WITH PHPMailer or actual setup) ---
function send_reset_email($recipient, $link) {
    // In a real application, you would use PHPMailer or a transactional email service.
    // For now, we simulate success and log the link for testing.
    error_log("Password Reset Link sent to $recipient: $link");
    return true; 
}
// -----------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ===================================================================
    // PHASE 1: User submits EMAIL to request the link
    // ===================================================================
    if (isset($_POST['email']) && !isset($_POST['new_password'])) {
        $email = trim($_POST['email']);
        $user_data = $user_obj->getUserByEmail($email);
        
        if ($user_data) {
            // 1. Generate a secure, unique token
            $token = bin2hex(random_bytes(32));
            $expiry = date("Y-m-d H:i:s", time() + 3600); // Token valid for 1 hour

            // 2. Store the token in the database (password_resets table)
            $stmt = $pdo->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
            $stmt->execute([$email, $token, $expiry]);
            
            // 3. Construct the direct link (ensure /php_auth_system/ path is correct)
            $reset_link = 'http://' . $_SERVER['HTTP_HOST'] . '/php_auth_system/reset_password.php?token=' . $token;

            // 4. Send the email (simulated)
            if (send_reset_email($email, $reset_link)) {
                $message = "A password reset link has been sent to your email. Check your inbox (or server logs).";
            } else {
                $message = "Failed to send email. Please contact support.";
            }

            // Track activity
            $user_obj->trackActivity($user_data['id'], 'RESET_REQUEST', 'Password reset requested via email');

        } else {
            // Prevent enumeration: send generic message
            $message = "If the email address exists in our system, a password reset link has been sent.";
        }
    } 

    // ===================================================================
    // PHASE 3: User submits NEW PASSWORD with a valid token
    // ===================================================================
    elseif (isset($_POST['token'], $_POST['new_password'])) {
        $token = $_POST['token'];
        $new_password = $_POST['new_password'];

        // Re-validate token to prevent race conditions
        $stmt = $pdo->prepare("SELECT email FROM password_resets WHERE token = ? AND expires_at > NOW()");
        $stmt->execute([$token]);
        $reset_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($reset_data) {
            $email = $reset_data['email'];
            $user_data = $user_obj->getUserByEmail($email);
            
            // 1. Hash the new password
            $new_hash = password_hash($new_password, PASSWORD_DEFAULT);

            // 2. Update user password in the users table
            $update_stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
            $update_stmt->execute([$new_hash, $email]);
            
            // 3. Delete the used token to prevent replay attacks
            $delete_stmt = $pdo->prepare("DELETE FROM password_resets WHERE email = ?");
            $delete_stmt->execute([$email]);
            
            $message = "✅ Success! Your password has been updated. You can now log in.";
            
            // Track activity
            $user_obj->trackActivity($user_data['id'], 'PASSWORD_RESET', 'Password successfully changed using token');

        } else {
            $message = "❌ Error: Invalid or expired reset token. Please request a new link.";
        }
    }

} 
// ===================================================================
// PHASE 2: User accesses the page via a direct link (GET request with token)
// ===================================================================
elseif (isset($_GET['token'])) {
    $token = $_GET['token'];

    // 1. Check existence and expiry of the token
    $stmt = $pdo->prepare("SELECT email FROM password_resets WHERE token = ? AND expires_at > NOW()");
    $stmt->execute([$token]);
    $reset_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($reset_data) {
        $show_reset_form = true;
        $token_email = $reset_data['email'];
    } else {
        $message = "Invalid or expired password reset link. Please request a new one.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reset Password</title>
    <style>
        /* Minimal styles for context, use your index.php styles */
        body { font-family: Arial, sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; background-color: #f4f4f4; }
        .container { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0, 0, 0, 0.1); max-width: 400px; width: 90%; }
        h2 { text-align: center; color: #333; }
        form div { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type="email"], input[type="password"] { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        button { width: 100%; padding: 10px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background-color: #0056b3; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Password Reset</h2>
        
        <?php if ($message): ?>
            <p style="color: <?php echo strpos($message, 'Success') !== false ? 'green' : 'red'; ?>; text-align: center;"><?php echo htmlspecialchars($message); ?></p>
            <?php if (strpos($message, 'Success') !== false): ?>
                 <p style="text-align: center;"><a href="index.php">Go to Login</a></p>
            <?php endif; ?>
        <?php endif; ?>

        <?php 
        // 1. Show the email request form if no token is present and no message has been generated
        if (!$show_reset_form && empty($message)): 
        ?>
            <p>Enter your email to receive a password reset link.</p>
            <form action="reset_password.php" method="POST">
                <div>
                    <label for="email">Email:</label>
                    <input type="email" id="email" name="email" required>
                </div>
                <button type="submit">Send Reset Link</button>
            </form>
        
        <?php 
        // 2. Show the new password form if a valid token was processed (Phase 2 success)
        elseif ($show_reset_form): 
        ?>
            <h3>Set New Password for <?php echo htmlspecialchars($token_email); ?></h3>
            <form action="reset_password.php" method="POST">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($_GET['token']); ?>">
                <div>
                    <label for="new_password">New Password:</label>
                    <input type="password" id="new_password" name="new_password" required>
                </div>
                <button type="submit">Update Password</button>
            </form>
        <?php endif; ?>
        
        <?php if (!$show_reset_form): ?>
            <p style="text-align: center; margin-top: 10px;"><a href="index.php">Back to Login</a></p>
        <?php endif; ?>
    </div>
</body>
</html>