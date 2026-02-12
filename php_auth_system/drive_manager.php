<?php
// drive_manager.php - CORRECTED FILE STRUCTURE AND LOGIC FLOW

// --- 1. HELPER FUNCTIONS (Must be defined first) ---
if (!function_exists('formatBytes')) {
    function formatBytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}

// --- CRITICAL FIX: READ THEME COOKIE AND DEFINE $theme VARIABLE ---
$theme = $_COOKIE['theme'] ?? 'day'; 
// ----------------------------------------------------

// --- 2. SYSTEM INITIALIZATION & VARIABLE DECLARATION ---
require_once 'db_config.php';
require_once 'vendor/autoload.php';
require_once 'User.class.php';

// Check if user is logged in (Basic Auth Check)
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['user_id'])) {
    header('Location: index.php?error=not_logged_in');
    exit;
}

$user_id = $_SESSION['user_id'];
$user = new User();
$user_data = $user->getUserWithTokens($user_id); 

// Variables for Drive State
$current_folder_id = $_GET['folderId'] ?? 'root';
$current_folder_name = 'My Drive Root';
$parent_id = null;
$files = []; 
$files_message = null; 
$storage_quota = null; // Initialize storage quota

// --- 3. GOOGLE CLIENT & TOKEN REFRESH LOGIC ---

$client = new Google_Client();
$client->setClientId(GOOGLE_CLIENT_ID);
$client->setClientSecret(GOOGLE_CLIENT_SECRET);

// Load token from session or construct from DB refresh token
$token = $_SESSION['access_token'] ?? null;
if (!$token && !is_array($token) && !empty($user_data['google_refresh_token'])) {
    $token = [
        'access_token' => 'none', 'expires_in' => 0,
        'refresh_token' => $user_data['google_refresh_token'],
        'created' => time() 
    ];
}

// CRITICAL FIX: Ensure $token is a valid array before setting it.
if (empty($token) || !is_array($token)) {
    header('Location: link_google.php?error=Auth_Token_Corrupt');
    exit;
}

$client->setAccessToken($token);

// Check expiration and refresh silently
if ($client->isAccessTokenExpired()) {
    $refreshToken = $token['refresh_token'] ?? $user_data['google_refresh_token'];
    
    if ($refreshToken) {
        $client->fetchAccessTokenWithRefreshToken($refreshToken);
        $_SESSION['access_token'] = $client->getAccessToken(); 
    } else {
        header('Location: link_google.php?error=relink_required');
        exit;
    }
}

// --- 4. DRIVE API EXECUTION ---

$service = new Google_Service_Drive($client);

try {
    // 4a. Fetch Storage Quota (Must run first for indicator)
    $about = $service->about->get(['fields' => 'storageQuota']);
    $storage_quota = $about->getStorageQuota();
    
    // Save Quota to Session for profile.php
    $_SESSION['drive_quota'] = [
        'usage' => (int)$storage_quota->getUsage(),
        'limit' => (int)$storage_quota->getLimit() 
    ];


    // 4b. Fetch current folder details for breadcrumb/navigation
    if ($current_folder_id !== 'root') {
        $folder_data = $service->files->get($current_folder_id, ['fields' => 'name, parents']);
        $current_folder_name = $folder_data->getName();
        $parents = $folder_data->getParents();
        $parent_id = $parents[0] ?? 'root';
    }

    // 4c. List files in the current folder
    $optParams = [
        'pageSize' => 50,
        'q' => "'{$current_folder_id}' in parents and trashed = false",
        'fields' => 'nextPageToken, files(id, name, mimeType, size, modifiedTime, webViewLink, parents)'
    ];
    $results = $service->files->listFiles($optParams);
    $files = $results->getFiles();

    if (count($files) == 0) {
        $files_message = "This directory is currently empty.";
    }

} catch (Exception $e) {
    $files_message = "API Error: Could not load Drive contents. " . $e->getMessage();
    error_log("Drive Manager API Failure: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <title>My Google Drive Manager</title>
    <!-- CSS variable definitions (Theme configuration is assumed to be included or defined) -->
    <style>
        /* THEME BASE */
        :root {
            --bg-primary: #f8f8f8;
            --bg-secondary: #ffffff;
            --text-color: #34495e;
            --border-color: #d3dce0;
            --header-bg: #e3e9ed;
            --link-color: #007bff;
            --active-bg: #e5f1f8;
        }

        /* NIGHT MODE OVERRIDE */
        html[data-theme='night'] {
            --bg-primary: #2c3e50;
            --bg-secondary: #34495e;
            --text-color: #ecf0f1;
            --border-color: #556080;
            --header-bg: #455070;
            --link-color: #2ecc71;
            --active-bg: #405169;
        }

        /* BASE STYLES & LAYOUT */
        body { 
            font-family: 'Consolas', monospace, 'Segoe UI', Tahoma, sans-serif; 
            padding: 10px; 
            background-color: var(--bg-primary); 
            color: var(--text-color);
            margin: 0;
            line-height: 1.4;
            font-size: 14px;
            transition: background-color 0.3s, color 0.3s;
        }
        .container {
            /* FIX: Use 98% width to utilize full screen space */
            max-width: 98%; 
            width: 98%; 
            margin: 0 auto;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color); 
            padding: 20px;
            border-radius: 4px;
        }
        
        /* HEADERS */
        h1 { 
            color: var(--text-color); 
            border-bottom: 1px solid var(--border-color); 
            padding-bottom: 10px; 
            margin-bottom: 15px; 
            font-weight: bold; 
            font-size: 1.6em; 
        }
        h3 { 
            color: var(--text-color); 
            font-size: 1.2em; 
            margin-top: 15px; 
            padding-bottom: 5px; 
            font-weight: 600;
        }
        
        /* HEADER & NAVIGATION */
        .header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 15px; 
        }
        .drive-btn { 
            background-color: #556080; 
            color: white; 
            padding: 6px 12px; 
            border-radius: 3px; 
            font-weight: 600; 
            display: inline-block; 
            transition: background-color 0.2s; 
            border: 1px solid #455070;
            text-decoration: none;
            font-size: 0.9em;
        }
        .drive-btn:hover { background-color: #455070; }
        
        /* QUOTA INDICATOR */
        .quota-indicator {
            background: var(--header-bg); 
            border: 1px solid var(--border-color);
            padding: 10px 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .quota-indicator h4 {
            color: var(--text-color);
            margin: 0 0 5px 0;
            font-weight: bold;
            font-size: 1em;
        }
        .progress-mini { height: 6px; background-color: var(--border-color); border-radius: 3px; width: 100%; margin-top: 5px; }
        .progress-mini-fill { height: 100%; background-color: var(--link-color); border-radius: 3px; }
        
        /* UPLOAD CARD */
        .upload-card {
            background: var(--bg-secondary); 
            padding: 15px; 
            border: 1px solid var(--border-color);
            border-radius: 4px; 
            margin-bottom: 20px;
        }
        .upload-form {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .upload-card input[type="file"] {
            padding: 5px;
            flex-grow: 1;
            border: 1px solid var(--border-color); 
            border-radius: 3px;
            background-color: var(--bg-secondary);
            color: var(--text-color);
        }
        .upload-btn {
            background-color: #2ecc71; 
            padding: 6px 15px;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-weight: bold;
            font-size: 0.9em;
        }

        /* NAVIGATION BAR */
        .path-bar {
            background-color: var(--header-bg);
            padding: 8px 15px;
            border: 1px solid var(--border-color);
            border-radius: 3px;
            margin-bottom: 10px;
            font-size: 0.9em;
            display: flex; 
            justify-content: space-between;
            align-items: center;
        }
        .path-link {
            color: var(--link-color);
            text-decoration: none;
            font-weight: 600;
        }
        .path-link:hover { text-decoration: underline; }

        /* FILE LIST TABLE (phpMyAdmin style) */
        .file-table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-top: 10px; 
            border: 1px solid var(--border-color);
        }
        .file-table th { 
            background-color: var(--header-bg); 
            color: var(--text-color); 
            font-weight: bold; 
            text-transform: uppercase; 
            font-size: 0.85em;
            padding: 8px 12px;
            border-bottom: 1px solid var(--border-color);
            border-right: 1px solid var(--border-color);
        }
        .file-table td { 
            padding: 6px 12px; 
            font-size: 0.8em;
            border: 1px solid var(--border-color); /* Use theme border color */
            white-space: nowrap; 
            color: var(--text-color);
        }
        .file-table tr:nth-child(even) { background-color: var(--bg-secondary); }
        .file-table tr:hover { background-color: var(--active-bg); }

        /* ACTION LINKS & CONTAINER FIX */
        .action-container {
            display: flex;
            gap: 5px; 
            flex-wrap: nowrap; 
            align-items: center;
        }
        .action-btn { 
            padding: 4px 8px; 
            font-size: 0.75em; 
            font-weight: 600; 
            display: inline-block; 
            border-radius: 3px;
            text-decoration: none;
            border: 1px solid transparent; 
        }
        .download-btn { background-color: #27ae60; color: white; }
        .delete-btn { background-color: #dc3545; color: white; }
        .view-link { 
            color: var(--text-color); 
            background-color: var(--header-bg);
            border: 1px solid var(--border-color); 
        }
        .delete-form { display: inline-block; margin: 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>My Google Drive Manager</h1>
            <div>
                <a href="profile.php" class="drive-btn">Back to Profile</a> 
                <a href="javascript:void(0)" onclick="resetThemeAndLogout()" class="drive-btn" style="background-color: #dc3545;">Logout</a>
            </div>
        </div>

        <!-- Drive Quota Indicator -->
        <?php if ($storage_quota): ?>
            <?php
                $used = $storage_quota->getUsage();
                $limit = $storage_quota->getLimit();
                $percentage = ($limit > 0) ? round(($used / $limit) * 100, 2) : 0;
            ?>
            <div class="quota-indicator">
                <h4>📊 Drive Storage Usage</h4>
                <p style="margin-top: 0; margin-bottom: 5px; font-size: 0.9em;">
                    Used: <?php echo formatBytes($used); ?> / Total: <?php echo formatBytes($limit); ?> 
                    (<?php echo $percentage; ?>%)
                </p>
                <div class="progress-mini">
                    <div class="progress-mini-fill" style="width: <?php echo $percentage; ?>%;"></div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Upload Form -->
        <div class="upload-card">
            <h3>⬆️ Upload File</h3>
            <form action="drive_actions.php" method="POST" enctype="multipart/form-data" class="upload-form">
                <input type="file" name="uploadFile" required>
                <button type="submit" name="action" value="upload" class="upload-btn">Upload to Drive</button>
            </form>
        </div>

        <!-- Navigation Path -->
        <div class="path-bar">
            <div>
                Current Directory: 
                <a href="drive_manager.php" class="path-link">My Drive Root</a> 
                <?php if ($current_folder_id !== 'root'): ?>
                    / <strong style="color: var(--text-color);"><?php echo htmlspecialchars($current_folder_name); ?></strong>
                <?php endif; ?>
            </div>
            <?php if ($parent_id && $current_folder_id !== 'root'): ?>
                <span style="margin-left: 15px;">
                    <a href="drive_manager.php?folderId=<?php echo htmlspecialchars($parent_id); ?>" class="drive-btn" style="background-color: var(--link-color); padding: 3px 6px;">
                        ⬆️ Up One Level
                    </a>
                </span>
            <?php endif; ?>
        </div>


        <?php if (!empty($files)): ?>
            <table class="file-table">
                <thead>
                    <tr>
                        <th style="width: 40%;">Name</th>
                        <th>Type</th>
                        <th>Size</th>
                        <th>Last Modified</th>
                        <th>View</th>
                        <th style="width: 20%;">Actions</th> 
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($files as $file): ?>
                        <tr>
                            <td>
                                <?php if ($file->getMimeType() === 'application/vnd.google-apps.folder'): ?>
                                    <a href="drive_manager.php?folderId=<?php echo htmlspecialchars($file->getId()); ?>" style="font-weight: bold; color: var(--link-color);">
                                        📁 <?php echo htmlspecialchars($file->getName()); ?>
                                    </a>
                                <?php else: ?>
                                    📄 <?php echo htmlspecialchars($file->getName()); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($file->getMimeType()); ?></td>
                            <td>
                                <?php 
                                    echo ($file->getMimeType() !== 'application/vnd.google-apps.folder' && $file->getSize() !== null) 
                                        ? formatBytes($file->getSize())
                                        : '-'; 
                                ?>
                            </td>
                            <td><?php echo date('Y-m-d H:i:s', strtotime($file->getModifiedTime())); ?></td>
                            
                            <td>
                                <?php if ($file->getWebViewLink()): ?>
                                    <a href="<?php echo htmlspecialchars($file->getWebViewLink()); ?>" 
                                       target="_blank" 
                                       class="action-btn view-link">
                                        View
                                    </a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($file->getMimeType() !== 'application/vnd.google-apps.folder'): ?>
                                    <div class="action-container">
                                        <a href="drive_actions.php?action=download&fileId=<?php echo htmlspecialchars($file->getId()); ?>" 
                                           class="action-btn download-btn">
                                            Download
                                        </a>
                                        <form action="drive_actions.php" method="POST" class="delete-form">
                                            <input type="hidden" name="fileId" value="<?php echo htmlspecialchars($file->getId()); ?>">
                                            <button type="submit" 
                                                    name="action" 
                                                    value="delete" 
                                                    class="action-btn delete-btn" 
                                                    onclick="return confirm('Permanently delete this file?')">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="padding: 15px; background: var(--bg-secondary); border-radius: 8px; border: 1px solid var(--border-color);">
                <?php echo $files_message ?? "No files found in the current directory."; ?>
            </p>
        <?php endif; ?>
    </div>
    <script>
        function resetThemeAndLogout() {
            // Set the cookie expiration to a past date to force deletion (resets theme to default 'day')
            document.cookie = "theme=day; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
            
            // Then navigate to the logout script
            window.location.href = "logout.php";
        }
    </script>
</body>
</html>