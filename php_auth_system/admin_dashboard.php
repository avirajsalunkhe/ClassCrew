<?php
// admin_dashboard.php - Central Management Console (FINAL CODE WITH MODAL)
require_once 'db_config.php';
require_once 'vendor/autoload.php';
require_once 'User.class.php';

// Ensure Google classes are available for API calls (required for Google_Client and Google_Service_Drive)
use Google\Client as Google_Client;
use Google\Service\Drive as Google_Service_Drive;

// --- Helper Functions ---

// 2. Byte formatting (MOVED HERE TO FIX FATAL ERROR)
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

// 1. Status determination logic
function get_user_status($last_activity, $last_activity_type) {
    if (!$last_activity) { return ['status' => 'Never Logged In', 'color' => 'gray']; }
    
    // 1. Check for explicit LOGOUT
    if (strtoupper($last_activity_type) === 'LOGOUT') {
        return ['status' => 'Logged Out', 'color' => 'red'];
    }
    
    // 2. Time-based check (for users who closed the tab without logging out)
    $cutoff = time() - (15 * 60); // 15 minutes
    $last_time = strtotime($last_activity);

    if ($last_time > $cutoff) { return ['status' => 'Online', 'color' => 'green']; } 
    else { return ['status' => 'Inactive', 'color' => 'red']; }
}

// 3. Storage Simulation (Used for User Monitoring Table)
function get_simulated_quota($userId) {
    srand($userId); 
    if ($userId % 3 == 0) { $total_gb = 2048; } else { $total_gb = 15; }
    
    $used_gb = rand(1, $total_gb * 0.7); 
    $used_bytes = $used_gb * 1024 * 1024 * 1024;
    $total_bytes = $total_gb * 1024 * 1024 * 1024;
    $percentage = round(($used_gb / $total_gb) * 100, 2);
    
    return ['used' => $used_bytes, 'limit' => $total_bytes, 'percentage' => $percentage];
}

// --- 4. AUTHENTICATION & DATA FETCHING ---
// --- NOTE: Add Theme Configuration setup here ---
$theme = $_COOKIE['theme'] ?? 'day'; // Required for HTML tag

if (!isset($_SESSION['logged_in']) || !isset($_SESSION['user_id'])) { header('Location: index.php?error=access_denied'); exit; }

$user_id = $_SESSION['user_id'];
$user_obj = new User();
$admin_user = $user_obj->getUserById($user_id);

if (empty($admin_user['is_admin'])) { header('Location: profile.php?error=admin_access_denied'); exit; }

$google_client_template = new Google_Client();
$google_client_template->setClientId(GOOGLE_CLIENT_ID);
$google_client_template->setClientSecret(GOOGLE_CLIENT_SECRET);


$admin_quota = $_SESSION['drive_quota'] ?? null; 

if (!empty($admin_user['google_refresh_token'])) {
    $fetched_quota = $user_obj->getRealTimeQuotaForUser($admin_user['google_refresh_token'], $google_client_template);
    
    // Check if fetch was successful (returns an array)
    if (is_array($fetched_quota)) {
        $admin_quota = $fetched_quota; // Update local variable
        $_SESSION['drive_quota'] = $admin_quota; // Update session with fresh, good data
    } else {
        $admin_quota = false;
        unset($_SESSION['drive_quota']); 
    }
}

// Ensure $admin_quota is either the newly fetched array or false/null

// NEW: Fetch Admin's unread message count
$unread_count = 0;
try {
    // Assuming getUnreadMessagesForUser is correctly implemented in User.class.php
    $unread_count = $user_obj->getUnreadMessagesForUser($user_id);
} catch (Exception $e) {
    error_log("Failed to fetch unread message count: " . $e->getMessage());
}

// Fetch all user records
$all_users = []; 
try {
    $all_users = $user_obj->getAllUsersForAdmin(); 
} catch (Exception $e) {
    error_log("Failed to fetch all users: " . $e->getMessage());
}

// --- 5. GLOBAL STORAGE AGGREGATION ---
$global_used_storage = 0;
$global_total_storage = 0;

foreach ($all_users as &$user) { 
    $is_linked = !empty($user['google_refresh_token']);
    $user['quota_data'] = false; 

    if ($is_linked) {
        // CRITICAL: Call the newly implemented API fetching logic
        $quota = $user_obj->getRealTimeQuotaForUser($user['google_refresh_token'], $google_client_template);

        if ($quota) {
            $user['quota_data'] = $quota; 
            $global_used_storage += $quota['used'];
            $global_total_storage += $quota['limit'];
        }
    }
}
unset($user); 

$global_percentage = ($global_total_storage > 0) 
    ? round(($global_used_storage / $global_total_storage) * 100, 2) 
    : 0;
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard - DFS Console</title>
    <!-- NOTE: theme_config.php would be included here for the global styles -->
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
            --modal-bg: #fcfcfc;
            --table-header-bg: #b0c4de;
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
            --modal-bg: #2c3e50;
            --table-header-bg: #34495e;
        }

        body { 
            /* iOS-style font stack for a cleaner look */
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol"; 
            padding: 10px; 
            background-color: var(--bg-primary); 
            color: var(--text-color);
            margin: 0;
            line-height: 1.4;
            font-size: 14px;
            transition: background-color 0.3s, color 0.3s;
        }
        .dashboard-container { 
            max-width: 98%; 
            width: 98%; 
            margin: auto; 
            /* Enhanced container color for premium feel */
            background: var(--bg-secondary); 
            border-radius: 10px; /* Slightly more rounded */
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1); 
            padding: 15px 20px; 
            border: 1px solid var(--border-color); 
        }
        
        /* HEADERS & LAYOUT */
        h2 { 
            color: var(--text-color); 
            border-bottom: 1px solid var(--border-color); 
            padding-bottom: 10px; 
            margin-bottom: 15px; 
            font-weight: bold; 
            font-size: 1.6em; 
            text-shadow: 0 1px 1px rgba(255, 255, 255, 0.5); /* Subtle text depth */
        }
        h3 { 
            color: var(--text-color); 
            font-size: 1.2em; 
            margin-top: 15px; 
            padding-bottom: 5px; 
            font-weight: 600;
            text-shadow: 0 1px 1px rgba(255, 255, 255, 0.5); /* Subtle text depth */
        }
        
        /* BUTTONS & ACTIONS */
        .drive-btn { 
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
        /* .drive-btn:hover { background-color: #455070; } */
        
        .header-section { 
            display: flex; 
            justify-content: space-between; 
            align-items: stretch; 
            margin-bottom: 25px; 
            gap: 15px; 
        }
        .admin-card, .storage-card { 
            flex: 1; 
            padding: 15px; 
            border-radius: 8px; /* Slightly softer corners for inner cards */
            border: 1px solid var(--border-color); 
            background: var(--bg-primary); 
            transition: background-color 0.3s;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05); /* Lighter shadow for inner cards */
        }
        .admin-info { display: flex; align-items: center; margin-bottom: 15px; }
        .admin-info img { border: 2px solid var(--link-color); }

        /* USER TABLE STYLING */
        .user-table { width: 100%; border-collapse: collapse; margin-top: 10px; border: 1px solid var(--border-color); }
        .user-table th { background-color: var(--header-bg); color: var(--text-color); font-weight: bold; padding: 8px 12px; border-bottom: 1px solid var(--border-color); border-right: 1px solid var(--border-color); }
        .user-table td { padding: 8px 12px; font-size: 0.85em; border: 1px solid var(--border-color); vertical-align: middle; }
        .user-table tr:nth-child(even) { background-color: var(--bg-secondary); }
        .user-table tr:hover { background-color: var(--active-bg); }

        /* MODAL STYLES (Fixed and centered) */
        .modal-backdrop { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.4); }
        .modal-content { 
            background-color: var(--modal-bg); 
            position: fixed; 
            top: 50%; 
            left: 50%; 
            transform: translate(-50%, -50%); 
            border: 1px solid #99aab5;
            width: 650px; /* FIXED WIDTH */
            height: 450px; /* FIXED HEIGHT */
            border-radius: 5px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.4);
            overflow: hidden; 
        }
        .modal-header { background-color: var(--table-header-bg); color: var(--text-color); padding: 10px 15px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; font-weight: bold; }
        .settings-menu-list { list-style: none; padding: 0; margin: 0; border: 1px solid var(--border-color); border-radius: 3px; }
        .settings-menu-list li { border-top: 1px solid var(--border-color); }
        .settings-menu-list a { display: flex; justify-content: space-between; align-items: center; padding: 8px 10px; text-decoration: none; color: var(--text-color); font-size: 0.9em; }
        .settings-menu-list a:hover { background-color: var(--active-bg); }
        .settings-menu-list .active { background-color: var(--active-bg); font-weight: bold; border-right: 3px solid var(--link-color); }
        
        /* FORM INPUTS */
        #password-form label { display: block; margin-top: 10px; font-size: 0.9em; font-weight: 600; }
        #password-form input { width: 95%; padding: 6px; border: 1px solid var(--border-color); border-radius: 3px; background-color: var(--bg-secondary); color: var(--text-color); }
        #password-form button { margin-top: 15px; }
        
        #message-form textarea { width: 95%; padding: 6px; border: 1px solid var(--border-color); border-radius: 3px; background-color: var(--bg-secondary); color: var(--text-color); }

        /* NEW: Chat Notification Badge Style */
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background-color: #dc3545; /* Red */
            color: white;
            border-radius: 50%;
            padding: 3px 6px;
            font-size: 0.7em;
            font-weight: bold;
            min-width: 15px;
            text-align: center;
            line-height: 1;
        }

        /* NEW: Container for chat button relative positioning */
        .chat-button-container {
            position: relative;
            display: inline-block;
            margin-left: 8px; /* Space between buttons */
        }
        
        /* WHATSAPP-LIKE BUTTON STYLING */
        .whatsapp-btn {
            background-color: #25D366 !important; /* WhatsApp Green */
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background-color 0.2s;
        }
        .whatsapp-btn:hover {
            background-color: #128C7E !important;
        }
        .whatsapp-icon {
            width: 20px;
            height: 20px;
        }
        
        /* NEW APP ICON GRID STYLING */

        .app-grid-container {
            display: flex; 
            justify-content: flex-start; /* Aligns apps to the start of the row */
            align-items: flex-start; /* Keep rows aligned to the top */
            flex-wrap: wrap; 
            gap: 15px 10px; /* Vertical and horizontal gap */
            padding-top: 10px;
        }

        .app-icon-item {
            /* Main container for icon + text */
            display: flex;
            flex-direction: column;
            align-items: center;
            text-decoration: none;
            color: var(--text-color);
            transition: transform 0.1s;
            font-size: 0.85em; /* Smaller text under icon */
            font-weight: 500;
            width: 80px; /* Fixed width for better alignment */
            text-align: center;
            line-height: 1.2;
            text-shadow: 0 0.5px 0.5px rgba(0, 0, 0, 0.2); /* Shadow on text for premium feel */
        }
        .app-icon-item:hover {
            transform: translateY(-2px);
            color: var(--link-color);
        }

        .icon-wrapper {
            /* The square button area */
            position: relative;
            width: 55px;
            height: 55px;
            border-radius: 12px; /* Smooth, modern icon corner */
            display: flex;
            justify-content: center;
            align-items: center;
            /* CRITICAL: Premium shadow for depth, slightly softened from the original */
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2), 
                        inset 0 0 0 1px rgba(255, 255, 255, 0.4); /* Inner border/highlight */ 
            margin-bottom: 5px;
        }

        /* FINAL SETTINGS ICON STYLING (Specific Color Match) */
        .settings-icon-wrapper {
            background-color: #646464; /* Dark Gray match for iOS Settings App Icon */
        }
        
        .app-svg {
            width: 26px;
            height: 26px;
            color: white; /* Icons inside colored wrapper should be white/light */
        }

        /* HEADER LOGOUT BUTTON SPECIFIC STYLING */
        .header-logout-icon {
            width: 32px;
            height: 32px;
            padding: 0;
            border-radius: 50%; /* Make it perfectly round */
            display: inline-flex;
            justify-content: center;
            align-items: center;
            line-height: 1;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            background-color: #dc3545 !important; /* Force red background */
        }
        .logout-svg {
            width: 26px; /* Final size update to maximize button fill */
            height: 26px;
            fill: white;
        }
        .theme-toggle-btn {
    background-color: #ffffffff;
    border: none;
    padding: 1px;
    border-radius: 10px;
    cursor: pointer;
    display: flex;
    justify-content: center;
    align-items: center;
}

/* Modify these values to make icons larger/smaller */
.theme-icon-img {
    width: 60px;   /* Increase to 50px, 60px, etc. */
    height: 60px;  /* Keep same ratio */
    object-fit: contain;
}


/* FORM INPUTS */
/* This block is already in your file, ensure it remains */
#password-form label { display: block; margin-top: 10px; font-size: 0.9em; font-weight: 600; }
#password-form input { width: 95%; padding: 6px; border: 1px solid var(--border-color); border-radius: 3px; background-color: var(--bg-secondary); color: var(--text-color); }
#password-form button { margin-top: 15px; }

/* REFINEMENT: Button Specific Styling for Premium Look */
.settings-action-btn {
    /* Base style copied from original drive-btn */
    padding: 8px 15px; /* Increased padding slightly */
    font-size: 1.0em; /* Slightly larger text */
    background-color: var(--link-color); /* Use the primary link color (Blue/Green) */
    color: white;
    border: none;
    border-radius: 6px; 
    cursor: pointer;
    font-weight: 600;
    transition: all 0.2s;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}

.settings-action-btn:hover {
    background-color: #0056b3; 
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
    transform: translateY(-1px);
}
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="action-header">
            <h2>DFS Admin Monitoring Console</h2>
            <div class="action-links">
                <!-- LOGOUT BUTTON REPLACEMENT: ICON ONLY -->
                <a href="javascript:void(0)" 
                   onclick="resetThemeAndLogout()" 
                   style="background-color: #dc3545;" 
                   class="drive-btn header-logout-icon" 
                   title="Log Out">
                    <!-- This image is scaled to 100% of the 32x32 button size -->
                    <img 
                        src="https://cdn.iconscout.com/icon/premium/png-256-thumb/power-off-icon-svg-download-png-1976481.png?f=webp&w=256"
                        alt="Logout Icon"
                        class="logout-svg"
                        style="width: 100%; height: 100%; object-fit: contain;"
                    />
                </a>
            </div>
        </div>
        
        <div class="header-section">
            <!-- 1. ADMIN PERSONAL CARD -->
            <div class="admin-card">
                <div class="admin-info">
                    <img src="<?php echo htmlspecialchars($admin_user['profile_picture_url'] ?? 'default.png'); ?>" alt="Admin Pic">
                </div>
                    <div>
                        <strong>Admin: 
                            <?php 
                                echo htmlspecialchars($admin_user['first_name'] ? 
                                    $admin_user['first_name'] . ' ' . $admin_user['last_name'] : 
                                    $admin_user['email']); 
                            ?>
                        </strong><br>
                </div>
                
                <?php if ($admin_quota && is_array($admin_quota)): ?>
                    <?php 
                        // CRITICAL FIX: Ensure $admin_quota is treated as an array before accessing keys
                        // FIX: Accessing keys safely and using the calculated percentage
                        if (isset($admin_quota['used'], $admin_quota['limit'], $admin_quota['percentage'])) {
                            $used = $admin_quota['used'];
                            $limit = $admin_quota['limit'];
                            $percentage_admin = $admin_quota['percentage'];
                        } else {
                            // Default values if quota data is invalid/false
                            $used = 0;
                            $limit = 0;
                            $percentage_admin = 0;
                        }
                    ?>
                    <div class="admin-quota">
                        <h4>Admin Drive Quota</h4>
                        <p style="margin-bottom: 5px; font-size: 0.9em;">
                            Used: <?php echo formatBytes($used); ?> / Total: <?php echo formatBytes($limit); ?> 
                            (<?php echo $percentage_admin; ?>%)
                        </p>
                        <div class="progress-mini">
                            <div class="progress-mini-fill" style="width: <?php echo $percentage_admin; ?>%;"></div>
                        </div>
                    </div>
                <?php else: ?>
                     <p style="color: #dc3545; font-size: 0.9em;">(Login via Google to fetch Admin Quota)</p>
                <?php endif; ?>
            </div>

            <!-- 2. AGGREGATED STORAGE NUMBERS (REAL-TIME AGGREGATION) -->
            <div class="storage-card">
                <h3 style="margin-top: 0; border-left: 0; padding-left: 0; color: var(--text-color);">Combined Storage Pool</h3>
                <div class="storage-display">
                    <p style="font-size: 1.8em; font-weight: bold; color: var(--link-color); margin-bottom: 5px;">
                        <?php echo $global_percentage; ?>% Used
                    </p>
                    <p style="font-size: 1.1em; color: var(--text-color); margin: 0;">
                        Occupied: <strong><?php echo formatBytes($global_used_storage); ?></strong>
                    </p>
                    <p style="font-size: 1.1em; color: #7f8c8d; margin: 0;">
                        Total Limit: <strong><?php echo formatBytes($global_total_storage); ?></strong>
                    </p>
                </div>
            </div>

            <!-- 3. BULK ACTIONS -->
            <div class="admin-card" style="flex: 1; padding: 15px;">
                <h3 style="margin-top: 0; color: var(--text-color);">Bulk & Personal Actions</h3>
                
                <div class="app-grid-container">
                    
                    <!-- App Icon 1: Settings / User Tools -->
                    <a href="javascript:void(0)" onclick="openModal('settings-modal')" class="app-icon-item">
                        <div class="icon-wrapper settings-icon-wrapper">
                            
                            <!-- Replaced SVG with Image -->
                            <img 
                                src="https://cdn.iconscout.com/icon/free/png-512/free-apple-settings-icon-svg-download-png-493162.png?f=webp&w=256" 
                                alt="Settings Icon"
                                class="app-svg"
                                style="width: 100%; height: 100%; object-fit: contain;"
                            />
                            
                        </div>
                        <span>Settings</span>
                    </a>

                    
                    <!-- App Icon 2: Chat Console (WhatsApp Style) -->
                    <a href="chat_console.php" class="app-icon-item">
                        <div class="icon-wrapper" style="background-color: #25D366;">
                    
                            <!-- Replaced Chat SVG with Image -->
                            <img 
                                src="https://cdn.iconscout.com/icon/free/png-256/free-whatsapp-icon-svg-download-png-493160.png?f=webp&w=256"
                                alt="Chat Icon"
                                class="app-svg"
                                style="width: 100%; height: 100%; object-fit: contain;"
                            />
                    
                            <?php if ($unread_count > 0): ?>
                                <span class="notification-badge"><?php echo $unread_count; ?></span>
                            <?php endif; ?>
                        </div>
                    
                        <span>Admin Chat</span>
                    </a>


                    <a href="drive_manager.php" class="app-icon-item">
                        <div class="icon-wrapper" style="background-color: #fdfdfdff;">
                    
                            <!-- Replaced Chat SVG with Image -->
                            <img 
                                src="http://cdn.iconscout.com/icon/free/png-512/free-drive-icon-svg-download-png-10918785.png?f=webp&w=256"
                                alt="Drive Icon"
                                class="app-svg"
                                style="width: 105%; height: 105%; object-fit: contain;"
                            />
                    
                        </div>
                    
                        <span>Drive</span>
                    </a>


                    <!-- App Icon 3: Distribute File -->
                    <a href="distribute_file.php" class="app-icon-item">
                        <div class="icon-wrapper" style="background-color: #ffffffff;">
                    
                            <!-- Replaced SVG with Image -->
                            <img 
                                src="https://cdn.iconscout.com/icon/free/png-256/free-airdrop-icon-svg-download-png-10919042.png?f=webp&w=256"
                                alt="Distribute Icon"
                                class="app-svg"
                                style="width: 100%; height: 100%; object-fit: contain;"
                            />
                    
                        </div>
                        <span>Distribute Files</span>
                    </a>

                    <!-- App Icon 3: schedule File -->
                    <a href="schedule_batch.php" class="app-icon-item">
                        <div class="icon-wrapper" style="background-color: rgb(216, 216, 216);">
                    
                            <!-- Replaced SVG with Image -->
                            <img 
                                src="https://i.ibb.co/yc6bJHV3/reminders.png"
                                alt="Schedule Icon"
                                class="app-svg"
                                style="width: 100%; height: 100%; object-fit: contain;"
                            />
                    
                        </div>
                        <span>Schedule Batch</span>
                    </a>

                    <!-- App Icon 3: attendence File -->
                    <a href="attendance.php" class="app-icon-item">
                        <div class="icon-wrapper" style="background-color: rgb(216, 216, 216);">
                    
                            <!-- Replaced SVG with Image -->
                            <img 
                                src="https://i.ibb.co/B5GF05gz/notes.png"
                                alt="Schedule Icon"
                                class="app-svg"
                                style="width: 100%; height: 100%; object-fit: contain;"
                            />
                    
                        </div>
                        <span>Attendance</span>
                    </a>

                    <!-- App Icon 3: Fees Management -->
                    <a href="fees_management.php" class="app-icon-item">
                        <div class="icon-wrapper" style="background-color: rgb(216, 216, 216);">
                    
                            <!-- Replaced SVG with Image -->
                            <img 
                                src="https://i.ibb.co/xKB3HDGW/stocks.png"
                                alt="fees Icon"
                                class="app-svg"
                                style="width: 100%; height: 100%; object-fit: contain;"
                            />
                    
                        </div>
                        <span>Fees Collection</span>
                    </a>
                    <!-- App Icon 3: Notes Management -->
                    <a href="notes_management.php" class="app-icon-item">
                        <div class="icon-wrapper" style="background-color: rgb(216, 216, 216);">
                    
                            <!-- Replaced SVG with Image -->
                            <img 
                                src="https://i.ibb.co/8DcvmFXZ/ibooks.png"
                                alt="Notes Icon"
                                class="app-svg"
                                style="width: 100%; height: 100%; object-fit: contain;"
                            />
                    
                        </div>
                        <span>Notes</span>
                    </a>

                    <!-- App Icon 3: Test Management -->
                    <a href="exam_management.php" class="app-icon-item">
                        <div class="icon-wrapper" style="background-color: rgb(216, 216, 216);">
                    
                            <!-- Replaced SVG with Image -->
                            <img 
                                src="https://i.ibb.co/5WjdYjzW/tv.png"
                                alt="Notes Icon"
                                class="app-svg"
                                style="width: 100%; height: 100%; object-fit: contain;"
                            />
                    
                        </div>
                        <span>Test/Exam Portal</span>
                    </a>
                    <!-- App Icon 3: Post Notice -->
                    <a href="notice_post.php" class="app-icon-item">
                        <div class="icon-wrapper" style="background-color: rgb(223, 128, 44);">
                    
                            <!-- Replaced SVG with Image -->
                            <img 
                                src="https://i.ibb.co/yTCGgXL/alarm-clock.png"
                                alt="fees Icon"
                                class="app-svg"
                                style="width: 100%; height: 100%; object-fit: contain;"
                            />
                    
                        </div>
                        <span>Post Notice</span>
                    </a>
                </div>
            </div>
        </div>
        
        <!-- USER MONITORING TABLE -->
        <h3 style="margin-top: 10px; color: var(--text-color); border-bottom: 1px solid #ccc; padding-bottom: 10px;">User Monitoring (Total Linked: <?php echo count($all_users); ?>)</h3>

        <table class="user-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>User Email</th>
                    <th>Status</th>
                    <th>Last Active</th>
                    <th>Drive Link</th>
                    <th>Storage Used</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($all_users as $user): 
                    $status = get_user_status($user['last_activity'], $user['last_activity_type'] ?? null);
                    $is_linked = !empty($user['google_refresh_token']);
                    $quota_data = $user['quota_data'] ?? get_simulated_quota($user['id']); 
                ?>
                <tr>
                    <td><?php echo $user['id']; ?></td>
                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                    <td>
                        <!-- Status Dot & Text -->
                        <span class="status-dot <?php echo $status['color']; ?>"></span>
                        <span style="font-size: 0.9em;"><?php echo $status['status']; ?></span>
                    </td>
                    <td>
                        <?php echo $user['last_activity'] ? date('Y-m-d H:i:s', strtotime($user['last_activity'])) : 'N/A'; ?>
                    </td>
                    <td>
                        <span class="linked-status <?php echo $is_linked ? 'linked' : 'unlinked'; ?>">
                            <?php echo $is_linked ? 'Linked' : 'Not Linked'; ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($is_linked && $user['quota_data']): ?>
                            <div style="font-size: 0.85em;">
                                <?php echo formatBytes($quota_data['used']); ?> / <?php echo formatBytes($quota_data['limit']); ?>
                            </div>
                            <div class="progress-mini">
                                <div class="progress-mini-fill" style="width: <?php echo $quota_data['percentage']; ?>%;"></div>
                            </div>
                        <?php elseif ($is_linked && !$user['quota_data']): ?>
                            <span style="color: #dc3545; font-size: 0.85em;">Fetch Failed</span>
                        <?php else: ?>
                            <span style="color: #6c757d; font-size: 0.85em;">N/A</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- MODAL WINDOW (Settings) -->
    <div id="settings-modal" class="modal-backdrop" onclick="closeModal(event)">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Admin Preferences (DFS)</h3>
                <span style="cursor: pointer;" onclick="document.getElementById('settings-modal').style.display='none';">&times;</span>
            </div>
            <div style="display: flex; height: 400px;">
                
                <!-- LEFT PANEL: NAVIGATION -->
                <div style="flex-basis: 180px; background-color: var(--header-bg); border-right: 1px solid var(--border-color);">
                    <ul class="settings-menu-list">
                        <!-- 1. Theme Toggle -->
                        <li><a href="javascript:void(0)" onclick="showSettingsTab('theme', this)" id="nav-theme" class="active">☀️ Theme</a></li>
                        <!-- 2. Password Reset -->
                        <li><a href="javascript:void(0)" onclick="showSettingsTab('password', this)" id="nav-password">🔑 Password Reset</a></li>
                        <!-- 4. Logout (MOVED INSIDE MODAL) -->
                        <li><a href="javascript:void(0)" onclick="resetThemeAndLogout()" class="drive-btn" >🚪 Log Out</a></li>
                    </ul>
                </div>
                
                <!-- RIGHT PANEL: CONTENT -->
                <div style="flex-grow: 1; padding: 15px;">
                    
                    <!-- 1. THEME SETTINGS -->
                    <div id="theme-settings" class="settings-tab">
                        <h4>Theme Day/Night</h4>
                        <p></p>
                        <button onclick="toggleTheme()" 
                                class="settings-action-btn theme-toggle-btn">
                        
                            <img 
                                id="theme-icon"
                                src="<?php echo $theme === 'night' 
                                    ? 'https://cdn.iconscout.com/icon/free/png-256/free-cloudy-icon-svg-download-png-156776.png?f=webp&w=256'  // sun
                                    : 'https://cdn.iconscout.com/icon/free/png-256/free-full-icon-svg-download-png-156778.png?f=webp&w=256'; ?>" // moon
                                class="theme-icon-img"
                            >
                        
                        </button>

                    </div>

                    <!-- 2. PASSWORD RESET -->
                    <div id="password-settings" class="settings-tab" style="display: none;">
                        <h4>Change Local Password</h4>
                        <form action="handle_settings.php?action=reset_password" method="POST" id="password-form">
                            <label for="current_password">Current Password:</label>
                            <input type="password" id="current_password" name="current_password" required><br>
                            <label for="new_password_setting">New Password:</label>
                            <input type="password" id="new_password_setting" name="new_password" required><br>
                            <div class="form-actions"><button type="submit" class="settings-action-btn reset">Update Password</button></div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // --- CLIENT-SIDE FUNCTIONS ---

 function toggleTheme() {
    const currentTheme = document.documentElement.getAttribute('data-theme');
    const newTheme = currentTheme === 'night' ? 'day' : 'night';

    document.documentElement.setAttribute('data-theme', newTheme);
    document.cookie = `theme=${newTheme}; max-age=${365 * 24 * 60 * 60}; path=/`;

    const icon = document.getElementById('theme-icon');

    if (newTheme === 'night') {
        icon.src = "https://cdn.iconscout.com/icon/free/png-256/free-cloudy-icon-svg-download-png-156776.png?f=webp&w=256"; // sun
    } else {
        icon.src = "https://cdn.iconscout.com/icon/free/png-256/free-full-icon-svg-download-png-156778.png?f=webp&w=256"; // moon
    }
}


        
        function openModal(initialTabId = 'theme') {
            document.getElementById('settings-modal').style.display = 'block';
            
            // Set the initial tab view upon opening
            const initialElement = document.getElementById(`nav-${initialTabId}`);
            if (initialElement) {
                showSettingsTab(initialTabId, initialElement);
            }
        }

        function closeModal(event) {
            // Close only if the backdrop is clicked
            if (event.target.id === 'settings-modal') {
                document.getElementById('settings-modal').style.display = 'none';
            }
        }
        
        function showSettingsTab(tabId, clickedElement) {
            // Hide all tabs
            document.querySelectorAll('.settings-tab').forEach(tab => tab.style.display = 'none');
            
            // Remove active class from all nav links
            document.querySelectorAll('.settings-menu-list a').forEach(link => link.classList.remove('active'));
            
            // Show selected tab
            document.getElementById(tabId + '-settings').style.display = 'block';
            
            // Set active class on clicked link
            clickedElement.classList.add('active');
        }

        // Initialize the first active tab on load
        document.addEventListener('DOMContentLoaded', () => {
            // Automatically select the theme tab on first load
            const initialElement = document.getElementById('nav-theme');
            if (initialElement) {
                showSettingsTab('theme', initialElement);
            }
        });

        function resetThemeAndLogout() {
    // Set the cookie expiration to a past date to force deletion.
    document.cookie = "theme=day; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
    
    // Then navigate to the logout script
    window.location.href = "logout.php";
}
    </script>
</body>
</html>