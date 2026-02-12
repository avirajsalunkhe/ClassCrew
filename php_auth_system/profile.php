<?php
// profile.php - Handles authentication, user dashboard display, and activity log
session_start(); // <-- Added: ensure session is started
require_once 'db_config.php';
require_once 'User.class.php';

// --- Helper Functions ---
if (!function_exists('formatBytes')) {
    function formatBytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $bytes = max((int)$bytes, 0);
        if ($bytes === 0) return '0 B';
        $pow = floor(log($bytes, 1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}

// --- Authentication & Redirection ---
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['user_id'])) {
    header('Location: index.php?error=not_logged_in');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_obj = new User();
$user_details = $user_obj->getUserById($user_id);

// NEW: Fetch Admin's unread message count
$unread_count = 0;
try {
    // Assuming getUnreadMessagesForUser is correctly implemented in User.class.php
    $unread_count = $user_obj->getUnreadMessagesForUser($user_id);
} catch (Exception $e) {
    error_log("Failed to fetch unread message count: " . $e->getMessage());
}


if (!$user_details) {
    // If user details can't be found, force logout
    header('Location: logout.php');
    exit;
}

// === ADMIN REDIRECTION LOGIC (Ensure user is not an Admin trying to use the regular profile) ===
if (!empty($user_details['is_admin'])) {
    header('Location: admin_dashboard.php');
    exit;
}
// ==============================================================================================

// Prepare variables for HTML display
$needs_local_password = empty($user_details['password_hash']);
$status_message = $_GET['status'] ?? $_GET['error'] ?? '';
$is_google_only = empty($user_details['password_hash']) && !empty($user_details['google_id']);

// Fetch last 10 activities
try {
    // NOTE: This assumes User.class.php has a getLatestActivities method
    $activities = $user_obj->getLatestActivities($user_id, 10);
} catch (Exception $e) {
    $activities = [];
    error_log("Failed to load activities for user {$user_id}: " . $e->getMessage());
}

// Prepare Drive Quota data if available in session (set by google_callback.php)
$quota_available = isset($_SESSION['drive_quota']);
if ($quota_available) {
    $quota = $_SESSION['drive_quota'];
    $used = $quota['usage'];
    $limit = $quota['limit'];
    $percentage = ($limit > 0) ? round(($used / $limit) * 100, 2) : 0;
}

// Check for existing theme setting (default to 'day')
$theme = $_COOKIE['theme'] ?? 'day';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo htmlspecialchars($theme); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Profile | DFS Console</title>
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
            --error-color: #dc3545;
            --success-color: #2ecc71;
            --warning-color: #ffc107;
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
            --error-color: #ff6b81;
            --success-color: #00b894;
            --warning-color: #feca57;
        }

        /* BASE AND PHPMA STYLE RESET */
        body { 
            font-family: 'Consolas', monospace, 'Segoe UI', Tahoma, sans-serif; 
            padding: 15px; 
            background-color: var(--bg-primary); 
            margin: 0;
            line-height: 1.4;
            color: var(--text-color);
            transition: background-color 0.3s, color 0.3s;
            font-size: 14px;
        }
        .container {
            max-width: 1200px; 
            width: 95%; 
            margin: 0 auto;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color); 
            padding: 20px; 
            border-radius: 4px;
        }

        /* HEADER & NAVIGATION */
        .header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 15px; 
            padding-bottom: 8px; 
            border-bottom: 2px solid var(--border-color); 
        }
        .header h1 { 
            color: var(--text-color); 
            font-weight: bold; 
            font-size: 1.5em; 
            margin: 0;
        }
        .settings-gear {
            background-color: var(--header-bg);
            border: 1px solid var(--border-color);
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 1.4em;
            cursor: pointer;
            color: var(--text-color);
        }
        .settings-action-btn {
            padding: 5px 10px; 
            font-size: 0.9em;
            background-color: var(--link-color);
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .settings-action-btn:hover {
            background-color: #0056b3;
        }
        
        /* CARD STYLES */
        .info-card { 
            background: var(--bg-secondary); 
            padding: 10px;
            border-radius: 4px; 
            border: 1px solid var(--border-color); 
            margin-bottom: 10px;
            box-shadow: none;
        }
        .info-card h2 {
            color: var(--text-color); 
            margin-top: 0;
            padding-bottom: 3px;
            border-bottom: 1px solid var(--border-color);
            font-size: 1.1em;
            font-weight: 600;
        }
        
        /* USER INFO CARD */
        .user-details-text p {
            margin: 2px 0; 
            font-size: 0.85em; 
        }
        .user-details-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .profile-pic {
            width: 40px; 
            height: 40px; 
            border-radius: 50%;
            border: 2px solid var(--link-color);
        }

        /* ACTIVITY LIST (Server Log Style) */
        .activity-list { 
            list-style: none; 
            padding: 0; 
            border: 1px solid var(--border-color);
            border-radius: 3px;
        }
        .activity-list li { 
            background: var(--bg-secondary);
            margin: 0; 
            padding: 3px 8px; 
            border-bottom: 1px dotted var(--border-color); 
            font-size: 0.8em; 
            display: flex;
            align-items: center;
        }
        .activity-list li:last-child { border-bottom: none; }
        
        /* Individual Log Elements */
        .log-timestamp {
            color: var(--text-color); 
            width: 140px; 
            min-width: 140px;
            font-weight: 600;
        }
        .log-type {
            color: var(--link-color);
            width: 100px;
            min-width: 100px;
            font-weight: bold;
        }
        .log-description {
            flex-grow: 1; 
            color: var(--text-color);
            padding-right: 10px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap; 
        }
        .log-ip {
            color: #7f8c8d; 
            font-size: 0.9em;
        }

        /* ALERTS & NOTIFICATIONS */
        .alert-warning, .alert-success, .notification { 
            padding: 8px; 
            margin-bottom: 10px; 
            font-size: 0.9em; 
            border-radius: 4px;
            border: 1px solid;
        }
        .alert-success, .notification.success {
            background-color: var(--success-color);
            color: white;
            border-color: var(--success-color);
        }
        .alert-warning, .notification.error {
            background-color: var(--warning-color);
            color: var(--text-color);
            border-color: var(--warning-color);
        }
        .notification.error {
            background-color: var(--error-color);
            color: white;
            border-color: var(--error-color);
        }
        #notification-container {
            position: sticky;
            top: 0;
            z-index: 999;
            margin-bottom: 15px;
        }

        /* MODAL STYLES (FIXED CENTERING) */
        .modal-backdrop {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.4);
        }
        .modal-content {
            background-color: var(--modal-bg);
            position: fixed; 
            top: 50%; 
            left: 50%; 
            transform: translate(-50%, -50%); 
            border: 1px solid var(--border-color);
            width: 80%; 
            max-width: 650px; 
            border-radius: 5px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.4);
            display: flex; /* Flex for header/body */
            flex-direction: column;
        }

        .app-grid-container {
            display: flex; 
            justify-content: flex-start; /* Aligns apps to the start of the row */
            align-items: flex-start; /* Keep rows aligned to the top */
            flex-wrap: wrap; 
            gap: 15px 10px; /* Vertical and horizontal gap */
            padding-top: 10px;
        }
        .modal-header {
            background-color: var(--table-header-bg);
            color: var(--text-color);
            padding: 10px 15px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: bold;
        }
        .modal-body-container {
            display: flex; 
            height: 400px;
        }
        
        /* SETTINGS MENU (Vertical) */
        .settings-menu-list {
            list-style: none;
            padding: 0;
            margin: 0;
            border-right: 1px solid var(--border-color); /* Separator */
            background-color: var(--header-bg);
            height: 100%;
        }
        .settings-menu-list li {
            border-bottom: 1px solid var(--border-color);
        }
        .settings-menu-list li:last-child {
            border-bottom: none;
        }
        .settings-menu-list a {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 10px;
            text-decoration: none;
            color: var(--text-color);
            transition: background-color 0.1s;
            font-size: 0.9em;
        }
        .settings-menu-list a:hover {
            background-color: var(--active-bg);
        }
        .settings-menu-list .active {
            background-color: var(--active-bg);
            font-weight: bold;
            border-right: 3px solid var(--link-color);
        }
        .settings-tab { padding: 15px; flex-grow: 1; overflow-y: auto; }
        .settings-tab h4 { 
            margin-top: 0; 
            padding-bottom: 5px; 
            font-size: 1em; 
            font-weight: bold;
            border-bottom: 1px solid var(--border-color);
        }
        /* FORM INPUTS */
        #password-form label, #message-form label { display: block; margin-top: 10px; font-size: 0.9em; font-weight: 600; }
        #password-form input, #message-form textarea { width: 95%; padding: 6px; border: 1px solid var(--border-color); border-radius: 3px; background-color: var(--bg-secondary); color: var(--text-color); }
        #password-form button, #message-form button { margin-top: 15px; }

        /* CHAT WINDOW BASE */
        .chat-window {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 350px;
            height: 450px;
            border: 1px solid var(--border-color);
            border-radius: 5px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.3);
            background-color: var(--bg-secondary);
            z-index: 990;
            display: none; 
            flex-direction: column;
            overflow: hidden;
        }
        .chat-header {
            background-color: var(--table-header-bg);
            color: var(--text-color);
            padding: 10px 15px;
            font-weight: bold;
            display: flex;
            justify-content: space-between;
            cursor: move; 
        }
        .chat-interface {
            display: flex;
            flex-grow: 1;
            overflow: hidden;
        }

        /* LEFT PANEL: USERS LIST */
        .user-list-panel {
            flex-basis: 120px;
            border-right: 1px solid var(--border-color);
            overflow-y: auto;
            background-color: var(--bg-primary);
        }
        .user-list-panel ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .user-list-panel li {
            padding: 8px 10px;
            border-bottom: 1px solid var(--border-color);
            cursor: pointer;
            font-size: 0.9em;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .user-list-panel li:hover { background-color: var(--active-bg); }
        .user-list-panel li.active { background-color: var(--active-bg); }

        /* RIGHT PANEL: MESSAGE AREA */
        .message-panel {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }
        .chat-body {
            flex-grow: 1;
            overflow-y: auto;
            padding: 10px;
            list-style: none;
            margin: 0;
        }
        .chat-body li {
            margin-bottom: 8px;
            line-height: 1.3;
            font-size: 0.85em;
            word-wrap: break-word;
        }
        .chat-body .sender-self { color: var(--success-color); font-weight: bold; }
        .chat-body .sender-other { color: var(--link-color); font-weight: bold; }

        .chat-input-area {
            padding: 10px;
            background-color: var(--header-bg);
        }
        .chat-input-area textarea {
            width: 100%;
            resize: none;
            border-radius: 3px;
            padding: 5px;
            border: 1px solid var(--border-color);
            background-color: var(--bg-secondary);
            color: var(--text-color);
        }

        /* NOTIFICATION BADGE */
        .unread-badge {
            background-color: var(--error-color);
            color: white;
            font-size: 0.7em;
            padding: 2px 6px;
            border-radius: 10px;
            margin-left: 5px;
        }
        .online-dot {
            display: inline-block;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            margin-right: 5px;
        }
        .online-dot.green { background-color: var(--success-color); }
        .online-dot.red { background-color: var(--error-color); }

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
        
        .app-svg {
            width: 26px;
            height: 26px;
            color: white; /* Icons inside colored wrapper should be white/light */
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
        

    </style>
</head>
<body>
    <div class="container">
        <div id="notification-container">
            </div>

        <div class="header">
            <h1>Welcome, <?php echo htmlspecialchars($user_details['first_name'] ? $user_details['first_name'] . ' ' . $user_details['last_name'] : $user_details['email']); ?>!</h1>
            <div>
                <span class="settings-gear" onclick="openModal('theme')">⚙️</span>
            </div>
        </div>
        
        <?php
/**
 * Global Notice Marquee Integration
 * Copy this block to index.php, dashboard.php, etc.
 */

// 1. Resolve PDO connection safely
if (isset($user_obj) && method_exists($user_obj, 'getPdo')) {
    $notice_pdo = $user_obj->getPdo();
} else {
    $notice_pdo = $pdo ?? null;
}

if ($notice_pdo):
    // 2. Fetch all active notices
    try {
        $active_notices = $notice_pdo->query("SELECT * FROM notices WHERE is_active = 1 ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $active_notices = [];
    }

    if (!empty($active_notices)):
?>
<style>
    /* Marquee Styling & Animations */
    .global-notice-bar {
        overflow: hidden;
        white-space: nowrap;
        background: var(--bg-secondary, #ffffff);
        border-bottom: 1px solid var(--border-color, #d3dce0);
        padding: 12px 0;
        position: relative;
        z-index: 99;
    }
    .notice-marquee {
        display: inline-block;
        animation: marquee-run 40s linear infinite;
        padding-left: 100%;
    }
    .notice-marquee:hover {
        animation-play-state: paused;
    }
    @keyframes marquee-run {
        0% { transform: translateX(0); }
        100% { transform: translateX(-100%); }
    }
    .marquee-item {
        display: inline-flex;
        align-items: center;
        margin-right: 100px; /* Space between notices */
        font-weight: 900;
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    /* Dynamic Colors based on Category */
    .type-urgent, .type-holiday { color: #ef4444 !important; } /* Red */
    .type-general { color: #10b981 !important; } /* Green */
    .type-update { color: #f59e0b !important; } /* Orange */
</style>

<div class="global-notice-bar no-print">
    <div class="notice-marquee">
        <?php foreach ($active_notices as $notice): 
            $colorClass = match($notice['category']) {
                'Urgent', 'Holiday' => 'type-urgent',
                'General' => 'type-general',
                'Update' => 'type-update',
                default => ''
            };
        ?>
            <span class="marquee-item <?= $colorClass ?>">
                <i class="fas fa-bullhorn mr-3"></i>
                [<?= $notice['category'] ?>] <?= htmlspecialchars($notice['title']) ?>: 
                <?= htmlspecialchars($notice['content']) ?>
            </span>
        <?php endforeach; ?>
    </div>
</div>
<?php 
    endif;
endif; 
?>
        <div class="app-grid-container">
        <a href="chat_console.php" class="app-icon-item">
        <div class="icon-wrapper" style="background-color: #6d6e6eff;">
    
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
    <span>Chat App</span>
        
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
        <span>Drive </span>
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
                        <span>Batch Data</span>
    </a>

    <!-- App Icon 3: schedule File -->
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
                        <span>Attendence</span>
                        
    </a>
    <!-- App Icon 3: Fees Management -->
        <a href="fees_management.php" class="app-icon-item">
                        <div class="icon-wrapper" style="background-color: rgb(216, 216, 216);">
                    
                            <!-- Replaced SVG with Image -->
                            <img 
                                src="https://i.ibb.co/xKB3HDGW/stocks.png"
                                alt="Schedule Icon"
                                class="app-svg"
                                style="width: 100%; height: 100%; object-fit: contain;"
                            />
                    
                        </div>
                        <span>Fees App</span>
        </a>

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
    </div>
        <?php if ($status_message == 'Password_set_success'): ?>
        <div class="alert-success">Local password set successfully!</div>
        <?php endif; ?>
        <?php if ($needs_local_password): ?>
        <div class="info-card alert-warning">
            <h3>🔑 Action Required: Set a Local Password</h3>
            
            <form action="set_password.php" method="POST" style="display: flex; gap: 10px; align-items: center; margin-top: 10px;">
                <input type="password" name="new_password" placeholder="Enter New Password (min 6 chars)" required 
                        style="flex-grow: 1;">
                <button type="submit" class="settings-action-btn set-password-btn">Set Password</button>
            </form>
        </div>
        <?php endif; ?>


        <div class="info-card">
            <h2>User Details & Identity</h2>
            <div class="user-details-content">
                <div class="user-details-text">
                    <p><strong>Email:</strong> <?php echo htmlspecialchars($user_details['email']); ?></p>
                    <p><strong>Full Name:</strong> <?php echo htmlspecialchars($user_details['first_name'] . ' ' . $user_details['last_name']); ?></p>
                    <p><strong>Account ID:</strong> <?php echo htmlspecialchars($user_details['id']); ?></p>
                    <p><strong>Joined:</strong> <?php echo date('Y-m-d H:i:s', strtotime($user_details['created_at'])); ?></p>
                </div>
                <?php if (!empty($user_details['profile_picture_url'])): ?>
                    <img src="<?php echo htmlspecialchars($user_details['profile_picture_url']); ?>" alt="Profile Picture" class="profile-pic">
                <?php endif; ?>
            </div>
        </div>
        
        <div class="info-card">
            <h2>Last 10 Activities (Audit Log)</h2>
            <?php if (!empty($activities)): ?>
                <ul class="activity-list">
                    <?php foreach ($activities as $activity): ?>
                        <li>
                            <span class="log-timestamp">[<?php echo date('Y-m-d H:i:s', strtotime($activity['timestamp'])); ?>]</span>
                            <span class="log-type"><?php echo htmlspecialchars(strtoupper($activity['activity_type'])); ?>:</span>
                            <span class="log-description"><?php echo htmlspecialchars($activity['description']); ?></span>
                            <span class="log-ip">IP: <?php echo htmlspecialchars($activity['ip_address']); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>No activity recorded yet.</p>
            <?php endif; ?>
        </div>
    </div>
    
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
        // --- CLIENT-SIDE FUNCTIONS ---
        // Small helper: safe JSON-encoded user data from PHP
        const currentUserName = <?php echo json_encode(trim($user_details['first_name'] . ' ' . $user_details['last_name'])); ?>;
        const phpCurrentUserId = <?php echo json_encode($user_details['id']); ?>;

        // HTML escape helper for JS
        function htmlspecialchars(str) {
            if (typeof str === 'string') {
                return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
            }
            return str;
        }

        /**
         * Shows a non-blocking notification at the top of the page.
         * @param {string} message The message to display.
         * @param {string} type 'success' or 'error'.
         */
        function showNotification(message, type = 'error') {
            const container = document.getElementById('notification-container');
            if (!container) return;

            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.innerHTML = `<strong>${type.toUpperCase()}:</strong> ${htmlspecialchars(message)}`;
            
            // Prepend the new notification
            container.prepend(notification);

            // Automatically hide after 5 seconds
            setTimeout(() => {
                notification.style.opacity = '0';
                notification.style.transition = 'opacity 0.5s';
                setTimeout(() => notification.remove(), 500); // Remove after transition
            }, 5000);
        }
        
        function openModal(initialTabId = 'theme') {
            const backdrop = document.getElementById('settings-modal');
            if (!backdrop) return;
            backdrop.style.display = 'block';
            const initialElement = document.getElementById(`nav-${initialTabId}`);
            if (initialElement) {
                showSettingsTab(initialTabId, initialElement);
            }
        }

        function closeModal(event) {
            // Close only if the backdrop is clicked
            if (event.target && event.target.id === 'settings-modal') {
                document.getElementById('settings-modal').style.display = 'none';
            }
        }
        
        function showSettingsTab(tabId, clickedElement) {
            // Hide all tabs
            document.querySelectorAll('.settings-tab').forEach(tab => tab.style.display = 'none');
            
            // Remove active class from all nav links
            document.querySelectorAll('.settings-menu-list a').forEach(link => link.classList.remove('active'));
            
            // Show selected tab
            const toShow = document.getElementById(tabId + '-settings');
            if (toShow) toShow.style.display = 'block';
            
            // Set active class on clicked link
            if (clickedElement) clickedElement.classList.add('active');
        }

        function resetThemeAndLogout() {
            // Delete the theme cookie
            document.cookie = "theme=day; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
            // Navigate to the logout script
            window.location.href = "logout.php";
        }

        // --- CHAT LOGIC (Single consolidated implementation) ---
        document.addEventListener('DOMContentLoaded', () => {
            // Elements
            const chatToggleBtn = document.getElementById('chat-toggle-btn');
            const chatWindow = document.getElementById('global-chat-window');
            const sendBtn = document.getElementById('send-chat-btn');
            const chatInput = document.getElementById('chat-input');
            const chatLogElement = document.getElementById('chat-messages-log');
            const userListElement = document.getElementById('user-channel-list');

            // Chat state
            const currentUserId = phpCurrentUserId;
            let activeRecipientId = 0; // 0 means Global Chat
            let chatStatusInterval = null;
            let userStatusInterval = null;

            // Initialize settings tab
            const initialElement = document.getElementById('nav-theme');
            if (initialElement) {
                showSettingsTab('theme', initialElement);
            }

            // --- 1. USER STATUS & LIST POLLING ---
            function loadUserStatuses() {
                // This checks user activity and unread counts for DMs
                fetch('send_message.php?action=check_activity', { method: 'POST' })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success' && data.users) {
                            // Save active element reference before clearing
                            const activeElement = userListElement.querySelector('.active');
                            const activeId = activeElement ? activeElement.getAttribute('data-user-id') : 0;
                            
                            // Save a reference to the Global Chat element before clearing
                            const globalChatLi = userListElement.querySelector('li[data-user-id="0"]');

                            userListElement.innerHTML = '';
                            
                            // Re-add Global Chat Channel (ID 0)
                            if (globalChatLi) {
                                userListElement.appendChild(globalChatLi);
                            } else {
                                const newGlobalLi = document.createElement('li');
                                newGlobalLi.innerHTML = `# Global Chat <span class="unread-badge" id="unread-0" style="display:none;">0</span>`;
                                newGlobalLi.setAttribute('data-user-id', 0);
                                newGlobalLi.onclick = (e) => switchChat(0, newGlobalLi);
                                userListElement.appendChild(newGlobalLi);
                            }

                            // Add all other users
                            data.users.forEach(user => {
                                if (user.id == currentUserId) return; // Skip self
                                
                                // Check if user is already in the list (e.g., from search, though this function clears everything except global)
                                if (userListElement.querySelector(`li[data-user-id="${user.id}"]`)) return;

                                const li = document.createElement('li');
                                const statusColor = user.is_online ? 'green' : 'red';
                                const unreadCount = parseInt(user.unread_count || 0);
                                
                                li.innerHTML = `
                                    <div style="display:flex;align-items:center;">
                                        <span class="online-dot ${statusColor}"></span>
                                        ${htmlspecialchars((user.email || '').split('@')[0] || 'user')}
                                    </div>
                                    <span class="unread-badge" id="unread-${user.id}" style="display:${unreadCount > 0 ? 'inline' : 'none'};">
                                        ${unreadCount}
                                    </span>
                                `;
                                li.setAttribute('data-user-id', user.id);
                                li.onclick = (e) => switchChat(user.id, li);
                                userListElement.appendChild(li);
                            });

                            // Re-apply active class to current selection
                            const activeLi = userListElement.querySelector(`li[data-user-id="${activeId}"]`);
                            if (activeLi) activeLi.classList.add('active');
                            else userListElement.querySelector('li[data-user-id="0"]').classList.add('active'); // Default to global if active user disappeared
                        }
                    })
                    .catch(err => {
                        console.error('loadUserStatuses error', err);
                    });
            }

            // --- 2. MESSAGE HISTORY RETRIEVAL ---
            function loadChatHistory(recipientId) {
                const endpointUrl = 'send_message.php?action=get_history';
                const body = `recipient_id=${encodeURIComponent(recipientId)}`;

                if (chatLogElement) chatLogElement.innerHTML = '';

                fetch(endpointUrl, { 
                    method: 'POST', 
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, 
                    body: body 
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success' && data.messages && chatLogElement) {
                            data.messages.forEach(msg => {
                                const isSelf = (msg.sender_id == currentUserId);
                                // The sender name should be either 'Me' or the sender's name/email alias
                                const senderName = isSelf ? 'Me' : htmlspecialchars(msg.first_name || (msg.email ? msg.email.split('@')[0] : 'user'));
                                
                                const li = document.createElement('li');
                                li.classList.add(isSelf ? 'sender-self' : 'sender-other');
                                li.innerHTML = `
                                    [${new Date(msg.timestamp).toLocaleTimeString()}] 
                                    <span style="font-weight: bold;">${senderName}:</span> 
                                    ${htmlspecialchars(msg.message_text)}
                                `;
                                chatLogElement.appendChild(li);
                            });

                            // Scroll to bottom
                            chatLogElement.scrollTop = chatLogElement.scrollHeight;

                            // If this is a DM, mark the messages as read
                            if (recipientId !== 0) {
                                markMessagesAsRead(recipientId);
                            }
                        } else if (data.status === 'error') {
                            showNotification("Failed to load chat history: " + (data.message || "Network error."), 'error');
                        }
                    })
                    .catch(err => {
                        console.error('loadChatHistory fetch error', err);
                        showNotification("Failed to connect to chat server.", 'error');
                    });
            }

            function markMessagesAsRead(partnerId) {
                fetch('send_message.php?action=set_read', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `partner_id=${encodeURIComponent(partnerId)}`
                }).then(() => {
                    // Update user list to remove unread badge (done silently)
                    loadUserStatuses(); 
                }).catch(err => {
                    console.error('markMessagesAsRead error', err);
                });
            }

            function switchChat(newRecipientId, clickedElement) {
                activeRecipientId = newRecipientId;
                
                // Update header title
                const headerTitle = document.querySelector('#global-chat-window .chat-header span:first-child');
                if (headerTitle) {
                    // Use a slightly different title for DMs
                    let chatTitle;
                    if (newRecipientId === 0) {
                        chatTitle = 'Global Broadcast';
                    } else if (clickedElement && clickedElement.getAttribute('data-user-id') == newRecipientId) {
                        // Extract name/email alias from the list item text (ignoring badges/dots)
                        const textContent = clickedElement.textContent;
                        const alias = textContent.split(' ')[0].trim() || 'User';
                        chatTitle = `Chat with ${htmlspecialchars(alias)}`;
                    } else {
                        chatTitle = 'Direct Message';
                    }
                    headerTitle.textContent = chatTitle;
                }
                
                // Update UI state
                document.querySelectorAll('.user-list-panel li').forEach(li => li.classList.remove('active'));
                
                // If clickedElement is provided (i.e., clicked from the list), make it active.
                if (clickedElement) {
                    clickedElement.classList.add('active');
                } else {
                    // If switching from search result, try to find the new user in the list to activate it.
                    const newActiveLi = userListElement.querySelector(`li[data-user-id="${newRecipientId}"]`);
                    if (newActiveLi) newActiveLi.classList.add('active');
                }

                // Load history for the new recipient
                loadChatHistory(newRecipientId);
                
                // Reset chat polling interval to refresh immediately
                if (chatStatusInterval) clearInterval(chatStatusInterval);
                chatStatusInterval = setInterval(() => loadChatHistory(activeRecipientId), 5000);
            }

            // --- 4. USER SEARCH FOR NEW DM (New Functionality) ---
            async function searchAndSwitchChat(query) {
                const searchInput = document.getElementById('user-search-input');
                const email = query.trim();

                if (!email) {
                    showNotification("Please enter an email address to search.", 'warning');
                    return;
                }
                
                if (email.toLowerCase() === userDetails.email.toLowerCase()) {
                    showNotification("You cannot start a chat with yourself.", 'warning');
                    return;
                }
                
                // Call the backend to find the user ID associated with the email
                showNotification("Searching for user...", 'success');
                try {
                    const response = await fetch('send_message.php?action=search_user_by_email', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `email=${encodeURIComponent(email)}`
                    });

                    const data = await response.json();

                    if (data.status === 'success' && data.user_id && data.email_alias) {
                        const newUserId = data.user_id;
                        const emailAlias = data.email_alias;

                        // Check if the user is already in the list
                        let li = userListElement.querySelector(`li[data-user-id="${newUserId}"]`);

                        if (!li) {
                            // If user is not currently in the visible list (no previous chat/not active)
                            li = document.createElement('li');
                            li.innerHTML = `
                                <div style="display:flex;align-items:center;">
                                    <span class="online-dot red"></span> 
                                    ${htmlspecialchars(emailAlias)} (New)
                                </div>
                                <span class="unread-badge" id="unread-${newUserId}" style="display:none;">0</span>
                            `;
                            li.setAttribute('data-user-id', newUserId);
                            li.onclick = (e) => switchChat(newUserId, li);
                            
                            // Insert the new user right after the Global Chat, but before other users
                            const globalLi = userListElement.querySelector('li[data-user-id="0"]');
                            globalLi.after(li);
                        }

                        // Switch to the newly found/activated chat
                        switchChat(newUserId, li);
                        showNotification(`Chat started with ${htmlspecialchars(emailAlias)}.`, 'success');
                        
                    } else {
                        showNotification("User not found on the platform.", 'error');
                    }
                } catch (err) {
                    console.error('User search failed:', err);
                    showNotification("Failed to connect to backend search service.", 'error');
                }
            }
            window.searchAndSwitchChat = searchAndSwitchChat; // Make globally accessible

            // --- 3. SENDING MESSAGES ---
            function sendMessage() {
                const message = chatInput ? chatInput.value.trim() : '';
                // If recipientId is 0 (Global), we pass 0 to PHP
                const recipientIdToSend = activeRecipientId === 0 ? 0 : activeRecipientId; 

                if (!message) return;

                fetch('send_message.php?action=send_message', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `message=${encodeURIComponent(message)}&recipient_id=${encodeURIComponent(recipientIdToSend)}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        if (chatInput) chatInput.value = '';
                        loadChatHistory(activeRecipientId); // Refresh history to show new message
                        loadUserStatuses(); // Update unread counts/status
                    } else {
                        showNotification("Error sending message: " + (data.message || "Unknown server error."), 'error');
                    }
                })
                .catch(err => {
                    console.error('sendMessage fetch error', err);
                    showNotification("Error sending message: Failed to connect to backend service.", 'error');
                });
            }

            // Toggle chat window
            if (chatToggleBtn) {
                chatToggleBtn.addEventListener('click', () => {
                    const isVisible = chatWindow.style.display === 'flex';
                    chatWindow.style.display = isVisible ? 'none' : 'flex';
                    chatWindow.setAttribute('aria-hidden', String(isVisible ? 'true' : 'false'));
                    
                    if (!isVisible) {
                        // Opened
                        loadChatHistory(activeRecipientId); // Load active chat history
                        
                        // Start polling for messages
                        if (chatStatusInterval) clearInterval(chatStatusInterval);
                        chatStatusInterval = setInterval(() => loadChatHistory(activeRecipientId), 5000); // Poll every 5 seconds
                    } else {
                        // Closed
                        if (chatStatusInterval) clearInterval(chatStatusInterval);
                    }
                });
            }

            // Send button + Enter key
            if (sendBtn && chatInput) {
                sendBtn.addEventListener('click', sendMessage);
                chatInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        sendMessage();
                    }
                });
            }

            // Start availability polling globally (runs even when chat closed)
            loadUserStatuses();
            userStatusInterval = setInterval(loadUserStatuses, 30000); // Check user availability/unread count every 30 seconds

            // Clean up intervals when leaving page
            window.addEventListener('beforeunload', () => {
                if (chatStatusInterval) clearInterval(chatStatusInterval);
                if (userStatusInterval) clearInterval(userStatusInterval);
            });

            // Make sure Global Chat is active on load
            const initialActive = userListElement.querySelector('li[data-user-id="0"]');
            if (initialActive) initialActive.classList.add('active');
        });
    </script>
</body>
</html>