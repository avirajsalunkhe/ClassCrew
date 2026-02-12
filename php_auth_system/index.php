<?php
// index.php - Main entry point
require_once 'db_config.php';
require_once 'vendor/autoload.php';

// Check if user is already logged in
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header('Location: profile.php');
    exit;
}

// Initialize Google Client for generating the Login URL
$client = new Google_Client();
$client->setClientId(GOOGLE_CLIENT_ID);
$client->setClientSecret(GOOGLE_CLIENT_SECRET);
$client->setRedirectUri(GOOGLE_REDIRECT_URL);

// Request persistent access (offline) and full Drive access
$client->setAccessType('offline'); 
$client->setPrompt('consent');     
$client->addScope(Google_Service_Drive::DRIVE); // Keep existing for Drive Manager
$client->addScope(Google_Service_Drive::DRIVE_APPDATA); // Add for hidden DFS chunks
$client->addScope('email');
$client->addScope('profile');

// Generate the Google Auth URL
$google_login_url = $client->createAuthUrl();

$error = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>DFS Admin Login</title>
    <style>
        /* PHPMA STYLING */
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            display: flex; 
            justify-content: center; 
            align-items: center; 
            min-height: 100vh; 
            background-color: #f0f2f5; /* Light Gray/Blue background */
            margin: 0;
        }
        .container { 
            background: #ffffff; 
            padding: 30px; 
            border-radius: 6px; 
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1); 
            max-width: 380px; 
            width: 100%; 
            border: 1px solid #d3dce0; /* Light blue/gray border */
            text-align: left;
        }
        
        /* HEADER */
        h2 { 
            color: #556080; /* phpMyAdmin Navy/Gray */
            border-bottom: 2px solid #b0c4de; 
            padding-bottom: 10px; 
            margin-bottom: 20px; 
            font-weight: bold; 
            text-align: center;
        }
        h3 {
            color: #34495e;
            font-size: 1.1em;
            margin-top: 0;
            margin-bottom: 15px;
            text-align: center;
            font-weight: 600;
        }
        
        /* FORM ELEMENTS */
        label { 
            display: block; 
            margin-bottom: 5px; 
            font-weight: 600; 
            color: #34495e;
            font-size: 0.95em;
        }
        input[type="email"], input[type="password"] { 
            width: 100%; 
            padding: 10px; 
            border: 1px solid #99aab5; /* Stronger border */
            border-radius: 3px; 
            box-sizing: border-box; 
            margin-bottom: 15px;
            background-color: #fcfcfc;
        }
        
        /* LOCAL LOGIN BUTTON */
        button { 
            width: 100%; 
            padding: 10px; 
            background-color: #556080; /* phpMyAdmin Navy */
            color: white; 
            border: none; 
            border-radius: 3px; 
            cursor: pointer; 
            font-weight: bold;
            transition: background-color 0.2s;
        }
        button:hover { 
            background-color: #455070; 
        }
        
        /* GOOGLE BUTTON */
        .google-btn { 
            display: block; 
            padding: 10px; 
            background-color: #2ecc71; /* Green for Google/OAuth */
            color: white; 
            text-decoration: none; 
            border-radius: 3px; 
            margin-top: 20px; 
            font-weight: bold; 
            text-align: center;
        }
        .google-btn:hover { 
            background-color: #27ae60; 
        }
        
        /* UTILITY & ERROR */
        .hr-separator { margin: 25px 0; border: 0; border-top: 1px solid #e0e0e0; }
        .error { 
            color: #dc3545; 
            text-align: center; 
            margin-bottom: 15px; 
            border: 1px solid #f8d7da; 
            background-color: #f8d7da;
            padding: 10px; 
            border-radius: 4px; 
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>DFS Admin Login</h2>
        <?php if ($error && $error !== 'already_logged_in_concurrent'): ?>
            <p class="error">Error: Could not log in. <?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>

        <!-- Local Access Form -->
        <div id="login-tab">
            <h3 style="margin-top: 0; color: #555;">Server Access</h3>
            <form action="authenticate.php" method="POST">
                <div>
                    <label for="login_email">Username (Email):</label>
                    <input type="email" id="login_email" name="email" required>
                </div>
                <div>
                    <label for="login_password">Password:</label>
                    <input type="password" id="login_password" name="password" required>
                </div>
                <button type="submit" name="action" value="login">Go</button>
            </form>
            <p style="margin-top: 10px; font-size: 0.9em; text-align: center;">
                <a href="reset_password.php" style="color: #007bff; text-decoration: none;">Forgot Password?</a>
            </p>
        </div>

        <hr class="hr-separator">
        
        <h3 style="margin-top: 0; color: #555;">Google OAuth</h3>
        <a href="<?php echo $google_login_url; ?>" class="google-btn">Login with Google Account</a>
    </div>

    <!-- JAVASCRIPT POP-UP FOR CONCURRENT SESSION -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const error = urlParams.get('error');

            if (error === 'already_logged_in_concurrent') {
                // Clear the error parameter from the URL bar (optional but cleaner)
                history.replaceState(null, null, window.location.pathname);
                
                // NOTE: Using a custom modal is best practice, but sticking to alert() for simplicity
                alert("🚨 Login Rejected: Your account is currently logged in and active in another browser or device (or the session has not yet expired). Please log out there first.");
            }
        });
    </script>
</body>
</html>