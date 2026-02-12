<?php
// chat_console.php - Dedicated, full-screen chat interface (WhatsApp-like)

// CRITICAL FIX 1: Ensure full error reporting for debugging AJAX crashes
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// CRITICAL FIX 2: Global Exception and Error Handler for Fatal Errors
set_exception_handler(function($e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'FATAL EXCEPTION: ' . $e->getMessage()]);
    exit;
});
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // Output error only if headers haven't been sent, ensuring JSON integrity.
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'PHP FATAL SHUTDOWN ERROR: ' . $error['message'] . ' on line ' . $error['line']]);
        }
    }
});


session_start();
// Assuming these are implemented and working correctly
require_once 'db_config.php';
require_once 'User.class.php';

// CRITICAL REQUIREMENT: Load Google Client Library for Drive interaction
require_once 'vendor/autoload.php'; 

use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\Permission;
use Google\Service\Oauth2;


// --- Initialization & User Setup ---
$user_obj = null;
try {
    // Attempt to instantiate the user object
    $user_obj = new User();
} catch (\Throwable $e) {
    // This catch is usually for PDO errors caught in User::__construct
    if (isset($_REQUEST['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'FATAL INIT ERROR: Cannot instantiate User class: ' . $e->getMessage()]);
        exit;
    }
}

if (!$user_obj || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['user_id'])) {
    if (isset($_REQUEST['action'])) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Not authenticated or initialization failed.']);
        exit;
    }
    header('Location: index.php?error=not_logged_in');
    exit;
}

$user_id = $_SESSION['user_id'];
// CRITICAL: Fetch tokens needed for Google Drive upload/delete
$user_details = $user_obj->getUserById($user_id); 
$user_tokens = $user_obj->getUserWithTokens($user_id);


if (!$user_details || !$user_tokens) {
    if (isset($_REQUEST['action'])) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'User details or tokens not found.']);
        exit;
    }
    header('Location: logout.php');
    exit;
}

// Prepare variables for HTML display
$current_user_alias = htmlspecialchars(trim($user_details['first_name'] . ' ' . $user_details['last_name'] ?: $user_details['email']));
$is_admin = !empty($user_details['is_admin']); 
$theme = $_COOKIE['theme'] ?? 'day';

// Helper function to initialize Google Client for API calls
function initializeGoogleClient($refreshToken) {
    if (!defined('GOOGLE_CLIENT_ID') || !defined('GOOGLE_CLIENT_SECRET')) {
        throw new Exception("Google API credentials are missing from db_config.php.");
    }

    $client = new Client();
    $client->setClientId(GOOGLE_CLIENT_ID);
    $client->setClientSecret(GOOGLE_CLIENT_SECRET);
    $client->setAccessType('offline');
    $client->setScopes([Drive::DRIVE_FILE, Drive::DRIVE_APPDATA]); // DRIVE_FILE for R/W
    
    $client->refreshToken = $refreshToken;
    $client->fetchAccessTokenWithRefreshToken($refreshToken);
    
    if (!$client->getAccessToken()) {
        throw new Exception("Failed to refresh Google Access Token. Token may be revoked.");
    }
    return new Drive($client);
}


// --- AJAX ENDPOINTS (Consolidated) ---

if (isset($_REQUEST['action'])) {
    $action = $_REQUEST['action'];
    $response = ['status' => 'error', 'message' => 'Unknown action.'];

    // CRITICAL FIX: Output the JSON content type header 
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    
    // --- Drive API Helper Logic ---

    // New: Helper function to extract bare File ID from the full URL saved in DB
    function getFileIdFromUrl($dfsUrl) {
        // Matches the ID parameter in the uc?export... format
        if (preg_match('/id=([a-zA-Z0-9_-]+)/', $dfsUrl, $matches)) {
            return $matches[1];
        }
        // Matches the ID in the file/d/... format
        if (preg_match('/file\/d\/([a-zA-Z0-9_-]+)/', $dfsUrl, $matches)) {
            return $matches[1];
        }
        return null;
    }

    // New: Drive File Deletion Endpoint
    if ($action === 'delete_drive_file') {
        ob_start(); // Start output buffering
        $dfs_url = $_POST['dfs_url'] ?? null;
        $fileId = getFileIdFromUrl($dfs_url);

        if (empty($user_tokens['google_refresh_token'])) {
             $response = ['status' => 'warning', 'message' => 'Google Account not linked. File not deleted from Drive.'];
        } elseif (empty($fileId)) {
             $response = ['status' => 'warning', 'message' => 'Invalid Drive file URL provided for deletion.'];
        } else {
            try {
                $service = initializeGoogleClient($user_tokens['google_refresh_token']);
                
                // Attempt to delete the file permanently
                $service->files->delete($fileId);
                
                $response = ['status' => 'success', 'message' => 'File successfully deleted from Google Drive.'];

            } catch (\Google\Service\Exception $e) {
                // Ignore 404 error (File not found, maybe already deleted)
                if ($e->getCode() == 404) {
                     $response = ['status' => 'success', 'message' => 'File was not found on Drive, deletion assumed successful.'];
                } else {
                    error_log("Drive Deletion Error (File ID: $fileId): " . $e->getMessage());
                    // IMPORTANT: We still allow the message to be deleted from DB, but warn the user.
                    $response = ['status' => 'warning', 'message' => "Drive deletion failed: " . $e->getMessage()];
                }
            } catch (\Exception $e) {
                $response = ['status' => 'warning', 'message' => "Local configuration issue: " . $e->getMessage()];
            }
        }
        ob_end_clean(); 
        echo json_encode($response);
        exit;
    }

    switch ($action) {
        case 'get_chat_list': 
            $users_data = $user_obj->getChatUserList($user_id);
            $response = ['status' => 'success', 'users' => $users_data];
            break;

        case 'send_message':
            $recipient_id = (int)($_POST['recipient_id'] ?? 0);
            $message = $_POST['message'] ?? '';
            $dfs_file_id = $_POST['dfs_file_id'] ?? null; 
            $is_global = ($recipient_id === 0);

            if ($is_global && !$is_admin) {
                $response = ['status' => 'error', 'message' => 'Permission denied. Only Admins can send global broadcasts.'];
            } elseif (empty($message) && empty($dfs_file_id)) {
                $response = ['status' => 'error', 'message' => 'Message content or file is empty.'];
            } else {
                // saveMessage signature: saveMessage($sender_id, $recipient_id, $message, $driveFileId)
                $success = $user_obj->saveMessage($user_id, $recipient_id, $message, $dfs_file_id); 
                if ($success) {
                    $response = ['status' => 'success', 'message' => 'Message sent successfully.', 'recipient_id' => $recipient_id];
                } else {
                    $response = ['status' => 'error', 'message' => 'Database error while saving message.'];
                }
            }
            break;

        case 'unsend_message':
            $message_id = (int)($_POST['message_id'] ?? 0);
            
            if ($message_id > 0) {
                // unsendMessage now returns the dfs_file_id (URL) if it exists
                $dfs_url_to_delete = $user_obj->unsendMessage($message_id, $user_id);
                
                if ($dfs_url_to_delete !== false) {
                    $response = [
                        'status' => 'success', 
                        'message' => 'Message unsent successfully.',
                        // Pass the Drive URL back to JavaScript to initiate Drive deletion
                        'dfs_url_to_delete' => $dfs_url_to_delete 
                    ];
                } else {
                    $response = ['status' => 'error', 'message' => 'Unsend failed: Message not found, you are not the sender, or a database error occurred.'];
                }
            } else {
                $response = ['status' => 'error', 'message' => 'Invalid message ID for unsend action.'];
            }
            break;
            
        case 'get_history':
            $recipient_id = (int)($_POST['recipient_id'] ?? 0);
            $messages = $user_obj->getConversationHistory($user_id, $recipient_id);
            $response = ['status' => 'success', 'messages' => $messages];
            break;

        case 'set_read':
            $partner_id = (int)($_POST['partner_id'] ?? 0);
            if ($partner_id > 0) {
                $user_obj->resetUnreadCount($user_id, $partner_id);
                $response = ['status' => 'success', 'message' => 'Messages marked as read.'];
            } else {
                $response = ['status' => 'error', 'message' => 'Invalid partner ID.'];
            }
            break;

        case 'search_user_by_email':
            $email = $_POST['email'] ?? '';
            $user_info = $user_obj->getUserByEmail($email);

            if ($user_info && $user_info['id'] != $user_id) {
                $response = [
                    'status' => 'success',
                    'user_id' => $user_info['id'],
                    'email_alias' => htmlspecialchars($user_info['email'])
                ];
            } else {
                $response = ['status' => 'error', 'message' => 'User not found or cannot chat with self.'];
            }
            break;
            
        case 'toggle_reaction':
            $message_id = (int)($_POST['message_id'] ?? 0);
            $emoji = $_POST['emoji'] ?? '';

            if ($message_id > 0 && !empty($emoji)) {
                $success = $user_obj->toggleMessageReaction($message_id, $user_id, $emoji);
                if ($success) {
                    $response = ['status' => 'success', 'message' => 'Reaction toggled.'];
                } else {
                    $response = ['status' => 'error', 'message' => 'Failed to save reaction to database.'];
                }
            } else {
                $response = ['status' => 'error', 'message' => 'Invalid message ID or emoji.'];
            }
            break;

        case 'get_reaction_users': 
            $message_id = (int)($_POST['message_id'] ?? 0);
            
            if ($message_id > 0) {
                $reaction_users = $user_obj->getMessageReactionUsers($message_id);
                $response = ['status' => 'success', 'users' => $reaction_users];
            } else {
                $response = ['status' => 'error', 'message' => 'Invalid message ID.'];
            }
            break;

        // CRITICAL FIX: INTEGRATED GOOGLE DRIVE UPLOAD LOGIC
        case 'dfs_upload_image':
            
            // CRITICAL FIX: Start output buffering to suppress any incidental PHP warnings/notices
            ob_start();
            
            if (empty($_FILES['image_file']['tmp_name'])) {
                $response = ['status' => 'error', 'message' => 'No file received.'];
                ob_end_clean();
                break;
            }
            
            // CRITICAL CHECK: Ensure Google Constants are defined
            if (!defined('GOOGLE_CLIENT_ID') || !defined('GOOGLE_CLIENT_SECRET')) {
                $response = ['status' => 'error', 'message' => 'Google API credentials are missing from db_config.php. Cannot upload.'];
                ob_end_clean();
                break;
            }
            
            $uploaded_file = $_FILES['image_file'];
            $file_name = $uploaded_file['name'] ?? 'image.jpg';
            
            if (empty($user_tokens['google_refresh_token'])) {
                 $response = ['status' => 'error', 'message' => 'Drive access denied. Please link your Google Account via the Profile or Admin Dashboard.'];
                 ob_end_clean();
                 break;
            }
            
            try {
                // 1. Initialize Google Client
                $client = new Client();
                $client->setClientId(GOOGLE_CLIENT_ID);
                $client->setClientSecret(GOOGLE_CLIENT_SECRET);
                $client->setAccessType('offline');
                $client->setScopes([Drive::DRIVE_FILE]); // Need write permission
                
                // 2. Set Token and Refresh Access Token
                $client->refreshToken = $user_tokens['google_refresh_token'];
                // This call attempts to refresh and will throw an exception on failure (e.g., token revoked)
                $client->fetchAccessTokenWithRefreshToken($user_tokens['google_refresh_token']);
                
                if (!$client->getAccessToken()) {
                    throw new Exception("Failed to refresh Google Access Token. Token may be revoked. Please re-link your Google account.");
                }

                $service = new Drive($client);
                $mime_type = $uploaded_file['type'] ?: 'application/octet-stream';

                // 3. Prepare File Metadata
                $fileMetadata = new DriveFile([
                    'name' => 'Chat Image: ' . $file_name,
                    'mimeType' => $mime_type,
                    'parents' => ['appDataFolder'],
                    'description' => "Uploaded via DFS Chat by User ID {$user_id}.",
                    'writersCanShare' => false 
                ]);

                // 4. Upload File
                $drive_file = $service->files->create($fileMetadata, [
                    'data' => file_get_contents($uploaded_file['tmp_name']),
                    'mimeType' => $mime_type,
                    'uploadType' => 'multipart',
                    // CRITICAL: Request ID and the webViewLink (for fallback)
                    'fields' => 'id, webViewLink' 
                ]);
                
                // CRITICAL FIX: Use the stable getter method
                $fileId = $drive_file->getId();
                $webViewLink = $drive_file->getWebViewLink();
                
                // CRITICAL FAILSAFE CHECK & SOLUTION
                if (empty($fileId)) {
                    // --- SOLUTION IMPLEMENTED: Fallback to parsing ID from webViewLink ---
                    if (!empty($webViewLink) && preg_match('/file\/d\/([a-zA-Z0-9_-]+)/', $webViewLink, $matches)) {
                        $fileId = $matches[1];
                        error_log("DRIVE WARNING: Missing ID, successfully extracted ID from webViewLink: {$fileId}");
                    } else {
                        // Final failure: Throw exception if ID is still empty
                        error_log("DRIVE ERROR: Live upload failed. WebLink: " . ($webViewLink ?? 'N/A'));
                        throw new Exception("Drive file upload failed. The API did not return a file ID or parsable webViewLink.");
                    }
                }
                
                // --- DEBUG LOGGING ---
                error_log("DRIVE UPLOAD SUCCESS: File ID Retrieved: {$fileId}");

                // 5. Set File Permissions to Public (Anyone with link can view)
                $permission = new Permission([
                    'type' => 'anyone',
                    'role' => 'reader'
                ]);
                // This call ensures the file is publicly viewable for embedding
                $service->permissions->create($fileId, $permission);

                // 6. Return ONLY the File ID, let User.class.php rebuild the URL for final storage.
                
                $response = [
                    'status' => 'success',
                    'message' => 'Image successfully uploaded to Drive and shared.',
                    // CRITICAL CHANGE: Pass only the File ID back to JavaScript
                    'dfs_file_id' => $fileId, 
                    'file_name' => $file_name
                ];

            } catch (\Google\Service\Exception $e) {
                // Catch specific API errors (403 Permission denied, 401 Invalid Credentials, etc.)
                 $response = ['status' => 'error', 'message' => 'Google API Error: ' . $e->getMessage()];
            } catch (\Exception $e) {
                // Catch general PHP/Network errors
                $response = ['status' => 'error', 'message' => 'Drive Upload Failed: ' . $e->getMessage()];
            }
            
            // CRITICAL FIX: Ensure buffer is cleaned before encoding JSON
            ob_end_clean(); 
            break;
    }
    
    echo json_encode($response);
    exit;
}
// --- END PHP AJAX BLOCK ---

// --- HTML PAGE START ---
?><!DOCTYPE html>
<html lang="en" data-theme="<?php echo htmlspecialchars($theme); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DFS Secure Chat Console</title>
    <!-- Assuming CryptoJS is available for encryption/decryption -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/crypto-js/4.1.1/crypto-js.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
/* THEME BASE */
:root {
    --bg-primary: #f0f2f5; 
    --bg-secondary: #ffffff; 
    --text-color: #111b21; 
    --border-color: #e9edef; 
    --header-bg: #f7f7f7; 
    --link-color: #007bff;
    --active-bg: #e5f1f8; 
    --chat-bubble-self: #dcf8c6; 
    --chat-bubble-other: #ffffff; 
    --success-color: #2ecc71;
    --error-color: #dc3545;
    --seen-color: #34b7f1; /* BLUE for seen (Default Day Mode) */
    --chat-bg-day: #e3e0d2; 
    --chat-pattern-day: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='8' height='8' viewBox='0 0 8 8'%3E%3Cg fill='%23ccc' fill-opacity='0.1'%3E%3Cpath fill-rule='evenodd' d='M0 0h4v4H0V0zm4 4h4v4H4V4z'/%3E%3C/g%3E%3C/svg%3E");
}

/* NIGHT MODE OVERRIDE (WhatsApp dark theme inspired) */
html[data-theme='night'] {
    --bg-primary: #121212; 
    --bg-secondary: #1e1e1e;
    --text-color: #e9edef;
    --border-color: #313d45;
    --header-bg: #2a2f32;
    --link-color: #00a884; 
    --active-bg: #233138;
    --chat-bubble-self: #005c4b; 
    --chat-bubble-other: #2a3942;
    --success-color: #00a884;
    --error-color: #ff6b81;
    --seen-color: #53c5f5; /* LIGHT BLUE for seen in dark mode */
    --chat-bg-night: #1f2c35; 
    --chat-pattern-night: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='8' height='8' viewBox='0 0 8 8'%3E%3Cg fill='%23666' fill-opacity='0.1'%3E%3Cpath fill-rule='evenodd' d='M0 0h4v4H4V4z'/%3E%3C/g%3E%3C/svg%3E");
}

/* BASE STYLES */
body, html {
    height: 100%;
    margin: 0;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background-color: var(--bg-primary);
    color: var(--text-color);
    transition: background-color 0.3s, color 0.3s;
    overflow: hidden;
}

/* MAIN LAYOUT */
.chat-app-container {
    display: flex;
    height: 100vh;
    width: 100vw;
    max-width: 1600px;
    margin: 0 auto;
    border-left: 1px solid var(--border-color);
    border-right: 1px solid var(--border-color);
}

.left-panel {
    flex-basis: 350px;
    min-width: 250px;
    max-width: 400px;
    background-color: var(--bg-secondary);
    border-right: 1px solid var(--border-color);
    display: flex;
    flex-direction: column;
    /* overflow-y: hidden; <-- KEEP OR REMOVE: Leaving as is to respect the original flow, but removing allows the item content to visually flow out */

    /* --- MODIFICATION TO SHIFT 100PX LEFT --- */
    /* This shifts the entire panel and its content 100px left. */
    margin-left: -100px;
    
    /* Ensure the width expands to compensate for the negative margin,
       to maintain the position of the right edge relative to the right panel. 
       This works best if flex-basis is ignored due to max-width/min-width, 
       but setting the width explicitly is safer. */
    width: 450px; /* Original flex-basis 350px + 100px shift */
    min-width: 450px; /* Ensure it takes the minimum space */
    max-width: 500px; /* Original max-width 400px + 100px shift */
}

/* LEFT PANEL HEADER */
.panel-header {
    padding: 10px 15px;
    background-color: var(--header-bg);
    border-bottom: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-weight: bold;
}
.header-controls a {
    color: var(--text-color);
    margin-left: 15px;
    text-decoration: none;
    font-size: 1.2em;
}
.header-controls a:hover {
    color: var(--link-color);
}
.user-alias {
    font-size: 1.1em;
    color: var(--link-color);
}

/* SEARCH BAR */
.search-area {
    padding: 10px 15px;
    border-bottom: 1px solid var(--border-color);
    background-color: var(--header-bg);
}
.search-area input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--border-color);
    border-radius: 20px;
    background-color: var(--bg-secondary);
    color: var(--text-color);
    box-sizing: border-box;
    font-size: 0.9em;
}
.search-area button {
    display: none;
}

/* USER/CHANNEL LIST */
.user-list {
    flex-grow: 1;
    overflow-y: auto;
    list-style: none;
    padding: 0;
    margin: 0;
}
.user-list li {
    /* Existing Styles */
    display: flex;
    align-items: center;
    padding: 12px 15px;
    cursor: pointer;
    border-bottom: 1px solid var(--border-color);
    transition: background-color 0.1s;

    /* --- OPTIONAL: TO SHIFT ONLY LIST ITEM CONTENT LEFT --- */
    /* margin-left: -100px; */ /* This would extend the content, but likely break layout */
    /* width: calc(100% + 100px); */
}
.user-list li.active { 
    background-color: var(--active-bg); 
    border-left: 4px solid var(--link-color);
    padding-left: 11px;
}

/* PROFILE PICTURE */
.profile-pic-small {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
    margin-right: 10px;
    border: 1px solid var(--border-color);
}

/* WHATSAPP STYLE USER ENTRY LAYOUT */
.user-list-content {
    display: flex;
    flex-direction: column;
    flex-grow: 1;
    padding-right: 10px;
}
.user-info-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    width: 100%;
}
.user-info-row .name {
    font-weight: bold;
    font-size: 0.95em;
    display: flex;
    align-items: center;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 70%; 
}
.last-message-time {
    font-size: 0.7em;
    color: #777;
}
.last-message-text {
    font-size: 0.85em;
    color: #888;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 250px;
}
.last-message-text.unread {
    color: var(--link-color);
    font-weight: bold;
}

.online-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-right: 8px;
}
.online-dot.green { background-color: var(--success-color); }
.online-dot.red { background-color: var(--error-color); }
.unread-badge {
    background-color: var(--link-color);
    color: white;
    font-size: 0.7em;
    padding: 3px 8px;
    border-radius: 12px;
    font-weight: bold;
    min-width: 8px;
    text-align: center;
}
.disabled-chat-input {
    opacity: 0.6;
    cursor: not-allowed;
}

/* RIGHT PANEL: Conversation Window */
.right-panel {
    flex-grow: 1;
    display: flex;
    flex-direction: column;
}

.chat-title-bar {
    padding: 15px;
    background-color: var(--header-bg);
    border-bottom: 1px solid var(--border-color);
    font-weight: 600;
    font-size: 1.1em;
    text-align: center;
}

/* MESSAGE LOG - DYNAMIC BACKGROUND SWITCH */
.message-log {
    flex-grow: 1;
    overflow-y: auto;
    padding: 20px 8%;
    list-style: none;
    margin: 0;
    background-repeat: repeat;
    /* Default Day Mode */
    background-color: var(--chat-bg-day); 
    background-image: var(--chat-pattern-day); 
}

/* Night Mode Override for Background */
html[data-theme='night'] .message-log {
    background-color: var(--chat-bg-night);
    background-image: var(--chat-pattern-night);
}

.message-log li {
    margin-bottom: 12px;
    display: flex;
    max-width: 80%;
    position: relative; 
}

/* CHAT BUBBLES */
.chat-bubble {
    padding: 8px 12px;
    border-radius: 8px;
    max-width: 100%;
    word-wrap: break-word;
    box-shadow: 0 1px 0.5px rgba(0,0,0,0.13);
    position: relative; 
    /* NEW: Add space for the + reaction button */
    padding-right: 35px;
}

/* NEW: Reaction button container - positions + button */
.reaction-trigger-container {
    position: absolute;
    top: -5px;
    right: -30px;
    opacity: 0;
    transition: opacity 0.1s;
    cursor: pointer;
    z-index: 50;
}
.message-log li:hover .reaction-trigger-container,
.message-log li:focus-within .reaction-trigger-container {
    opacity: 1;
}

.sender-other {
    justify-content: flex-start;
}
.sender-other .chat-bubble {
    background-color: var(--chat-bubble-other);
    color: var(--text-color);
    border-top-left-radius: 0;
}
.sender-self {
    justify-content: flex-end;
    margin-left: auto;
}
.sender-self .chat-bubble {
    background-color: var(--chat-bubble-self);
    color: var(--text-color);
    text-align:right;
    border-top-right-radius: 0;
}

/* Link Styles */
.chat-bubble a {
    color: var(--link-color);
    text-decoration: none;
    font-weight: 500;
    word-break: break-all;
}
.chat-bubble a:hover {
    text-decoration: underline;
}

/* Context Menu Styling */
#context-menu {
    position: fixed; /* Fixed for better handling */
    z-index: 1000;
    background-color: var(--bg-secondary);
    border: 1px solid var(--border-color);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    border-radius: 8px;
    overflow: hidden;
    display: none;
    min-width: 120px;
    padding: 5px 0;
}
.context-menu-item {
    padding: 8px 15px;
    cursor: pointer;
    font-size: 0.9em;
    color: var(--text-color);
}
.context-menu-item:hover {
    background-color: var(--active-bg);
}


/* Reaction Picker Menu */
#reaction-menu {
    position: fixed;
    z-index: 1001; 
    background-color: var(--bg-secondary);
    border: 1px solid var(--border-color);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    border-radius: 25px;
    padding: 5px;
    display: none;
    white-space: nowrap;
}
.reaction-menu-item {
    font-size: 1.5em;
    padding: 5px;
    cursor: pointer;
    transition: transform 0.1s;
    border-radius: 50%;
}
.reaction-menu-item:hover {
    background-color: var(--active-bg);
    transform: scale(1.1);
}

/* Reaction Popover (User List) Styles */
#reaction-users-popover {
    position: fixed; 
    z-index: 1002;
    background-color: var(--bg-secondary);
    border: 1px solid var(--border-color);
    box-shadow: 0 6px 12px rgba(0, 0, 0, 0.2);
    border-radius: 8px;
    max-height: 200px; 
    width: 220px; 
    overflow-y: auto;
    padding: 10px;
    display: none;
}
.popover-header {
    font-weight: bold;
    font-size: 1em;
    padding-bottom: 5px;
    border-bottom: 1px solid var(--border-color);
    margin-bottom: 5px;
    color: var(--text-color);
}
.reaction-user-entry {
    display: flex;
    align-items: center;
    padding: 4px 0; 
    font-size: 0.9em;
    justify-content: space-between;
    transition: background-color 0.1s;
}
.reaction-user-entry:hover {
    background-color: var(--active-bg);
    border-radius: 4px;
}
.reaction-user-entry .emoji {
    font-size: 1.1em;
    margin-right: 8px;
}
.reaction-user-entry .email {
    flex-grow: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Reaction Badge Styles */
.message-reactions {
    position: absolute;
    bottom: -8px; 
    z-index: 20; 
    display: flex;
    align-items: center;
    font-size: 0.8em;
    padding: 0;
    margin: 0;
}
.reaction-badge {
    display: inline-flex;
    align-items: center;
    height: 20px; 
    line-height: 1;
    background-color: var(--header-bg);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    padding: 2px 6px; 
    margin: 0 3px;
    cursor: pointer;
    white-space: nowrap;
    font-weight: 600;
}
.sender-self .message-reactions {
    right: 0; /* Align self reactions to the right edge of the bubble */
}
.sender-other .message-reactions {
    left: 0; /* Align other reactions to the left edge of the bubble */
}

/* NEW: Image message display styles */
.chat-image {
    max-width: 100%;
    max-height: 300px; 
    border-radius: 8px;
    margin-bottom: 5px;
    object-fit: contain;
    cursor: zoom-in;
}

/* INPUT AREA */
.chat-input-area {
    padding: 10px 15px;
    background-color: var(--header-bg);
    display: flex;
    gap: 10px;
    flex-direction: column; 
}

/* Image Preview Container */
#image-preview-container {
    padding: 10px;
    border-bottom: 1px solid var(--border-color);
    background-color: var(--bg-secondary);
    border-radius: 8px 8px 0 0;
    position: relative;
    /* FIX 2: Ensure the container has visible space */
    width: auto;
    min-height: 100px; 
    max-height: 120px;
    overflow: hidden;
    align-items: center;
    gap: 10px;
    display: flex; /* Ensure it uses flex layout for alignment when visible */
}
#image-preview {
    max-width: 100px;
    min-width: 100px; /* Ensure minimum width */
    max-height: 100px;
    border-radius: 4px;
    object-fit: cover;
}
#remove-image-btn {
    position: absolute;
    top: 5px;
    right: 5px;
    background: var(--error-color);
    color: white;
    border: none;
    border-radius: 50%;
    width: 20px;
    height: 20px;
    line-height: 1;
    font-size: 14px;
    cursor: pointer;
    box-shadow: 0 1px 3px rgba(0,0,0,0.2);
}
.image-caption-area {
    flex-grow: 1;
    font-size: 0.9em;
    color: #555;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Inline Emoji Picker Container - Styled like a floating modal */
#inline-emoji-picker {
    background-color: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    margin-bottom: 10px;
    max-width: 400px; /* Constrain width for a compact look */
    height: 250px; /* Fixed height for scrolling */
    display: none;
    flex-direction: column; /* Stacks categories and grid */
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    align-self: flex-start; /* Aligns to the left/start of the flex container */
    overflow: hidden; /* Hide anything outside the fixed height */
}

/* Category Tabs */
#emoji-categories {
    display: flex;
    justify-content: space-around;
    padding: 8px 10px;
    border-bottom: 1px solid var(--border-color);
    background-color: var(--header-bg);
    border-radius: 12px 12px 0 0;
}
.emoji-category-tab {
    cursor: pointer;
    padding: 5px 8px;
    font-size: 1.2em;
    border-radius: 6px;
    transition: background-color 0.1s, transform 0.1s;
    opacity: 0.6;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-grow: 1;
}
.emoji-category-tab:hover {
    background-color: var(--active-bg);
    opacity: 1;
}
.emoji-category-tab.active {
    opacity: 1;
    border-bottom: 3px solid var(--link-color);
    margin-bottom: -10px; /* Compensate for the border moving content */
    padding-bottom: 7px;
}

/* Emoji Grid */
#emoji-grid-container {
    overflow-y: auto;
    flex-grow: 1;
    padding: 10px;
}

#emoji-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr); /* 7 columns for a dense grid */
    gap: 10px;
}
.emoji-item {
    font-size: 1.5em;
    padding: 5px;
    cursor: pointer;
    border-radius: 6px;
    transition: background-color 0.1s;
    text-align: center;
    line-height: 1.2;
}
.emoji-item:hover {
    background-color: var(--active-bg);
}


.input-controls {
    display: flex;
    gap: 10px;
}

.chat-input-area textarea {
    flex-grow: 1;
    padding: 10px;
    border: 1px solid var(--border-color);
    border-radius: 20px;
    resize: none;
    font-size: 1em;
    background-color: var(--bg-secondary);
    color: var(--text-color);
    box-sizing: border-box;
    max-height: 100px;
    overflow-y: auto;
}
.action-btn {
    background-color: var(--header-bg);
    color: var(--text-color);
    border: 1px solid var(--border-color);
    border-radius: 50%;
    width: 40px;
    height: 40px;
    font-size: 1.2em;
    line-height: 40px;
    cursor: pointer;
    transition: background-color 0.2s;
    margin-top: 5px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}
.action-btn:hover {
    background-color: var(--active-bg);
}
.send-btn {
    background-color: var(--link-color);
    color: white;
    border: none;
}
.send-btn:hover {
    background-color: var(--success-color);
}
.send-btn:disabled {
    background-color: #9e9e9e;
    cursor: not-allowed;
}
.file-upload-icon {
    font-size: 1em;
}

/* Custom Confirmation Modal (Replaces window.confirm) */
#custom-confirm-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
    /* HIDDEN - REMOVE IF NEEDED */
    display: none; 
    /* --- */
    justify-content: center;
    align-items: center;
    z-index: 10000;
}
.modal-content {
    background-color: var(--bg-secondary);
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.3);
    max-width: 400px;
    width: 90%;
    text-align: center;
    color: var(--text-color);
}
.modal-buttons {
    margin-top: 20px;
    display: flex;
    justify-content: space-around;
}

.message-content-html.image-caption-text& {
    float: right;
    margin-left: auto;
}
.modal-buttons button {
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: bold;
    transition: background-color 0.2s;
}
#confirm-yes {
    background-color: var(--error-color);
    color: white;
}
#confirm-no {
    background-color: var(--header-bg);
    color: var(--text-color);
}
.message-time-wrapper{
    float: right;
    margin-left: auto;
}
/* NOTIFICATION/STATUS MESSAGES (Top of the page, non-blocking) */
#notification-container {
    position: fixed;
    top: 0;
    left: 50%;
    transform: translateX(-50%);
    z-index: 10000; /* Ensure it's above everything */
    width: 90%;
    max-width: 600px;
}
.notification { 
    padding: 10px; 
    margin-top: 10px;
    font-size: 0.9em; 
    border-radius: 4px;
    color: white;
    text-align: center;
}
.notification.error {
    background-color: var(--error-color);
}
.notification.success {
    background-color: var(--success-color);
}
.notification.warning {
    background-color: #ffc107;
    color: #333;
}


/* Responsiveness */
@media (max-width: 768px) {
    .left-panel {
        flex-basis: 40%;
    }
    .message-log {
        padding: 20px 5%;
    }
}
@media (max-width: 500px) {
    .left-panel {
        flex-basis: 100%;
        position: absolute;
        z-index: 50;
        transition: transform 0.3s;
    }
    .right-panel {
        flex-grow: 1;
        width: 100%;
    }
    .chat-app-container {
        border: none;
    }
    /* Hide right panel content when left panel is visible (simulated mobile chat switching) */
    .chat-app-container.chat-list-open .right-panel {
        opacity: 0;
        pointer-events: none;
    }
    .chat-app-container.chat-list-open .left-panel {
        transform: translateX(0);
    }
}
.message-status.seen {
        color: var(--seen-color); /* This is the blue color variable */
    }
    </style>
</head>
<body>

    <div id="notification-container"></div>
    
    <!-- Custom Confirmation Modal (REMOVED - Not needed if direct unsend is desired) -->
    <!-- Removed: <div id="custom-confirm-modal">...</div> -->
    
    <div class="chat-app-container">
        
        <div class="left-panel">
            
            <div class="panel-header">
                <span class="user-alias"><?php echo $current_user_alias; ?></span>
                <div class="header-controls">
                    <a href="profile.php" title="Back to Profile">🏠</a>
                    <a href="javascript:void(0)" onclick="toggleTheme()" title="Toggle Theme">🌙</a>
                </div>
            </div>

            <div class="search-area">
                <input type="text" id="user-search-input" placeholder="Search user email or #Global" 
                    onkeyup="if(event.key === 'Enter') searchAndSwitchChat(this.value)">
                <button onclick="searchAndSwitchChat(document.getElementById('user-search-input').value)">Find User</button>
            </div>
            
            <ul id="user-channel-list" class="user-list">
                <li data-user-id="0" data-is-admin="false" class="active" onclick="switchChat(0, this)">
                    <img src="https://placehold.co/40x40/cccccc/333333?text=G" class="profile-pic-small" alt="Global">
                    <div class="user-list-content">
                        <div class="user-info-row">
                            <span class="name"># Global Chat</span>
                            <span class="last-message-time" id="time-0"></span>
                        </div>
                        <span class="last-message-text" id="msg-0">Broadcast Channel</span>
                    </div>
                    <span class="unread-badge" id="unread-0" style="display:none;">0</span>
                </li>
            </ul>
        </div>

        <div class="right-panel">
            <div id="chat-title-bar" class="chat-title-bar">
                # Global Chat
            </div>
            
            <ul id="chat-messages-log" class="message-log">
            </ul>

            <div class="chat-input-area">
                <!-- NEW: Inline Emoji Picker with Categories -->
                <div id="inline-emoji-picker" style="display: none;">
                    <div id="emoji-categories">
                        <!-- Category Tabs will be generated here by JS -->
                    </div>
                    <div id="emoji-grid-container">
                        <div id="emoji-grid">
                            <!-- Emojis will be rendered here by JS -->
                        </div>
                    </div>
                </div>
                
                <!-- NEW: Image Preview Container -->
                <div id="image-preview-container" style="display: none;">
                    <img id="image-preview" src="" alt="Image Preview">
                    <div class="image-caption-area">Image ready to send. Add optional caption below.</div>
                    <button id="remove-image-btn" title="Remove Image">X</button>
                </div>

                <div class="input-controls">
                    <!-- Hidden file input -->
                    <input type="file" id="image-file-input" accept="image/*" style="display:none;">
                    
                    <!-- File attachment button -->
                    <button id="attach-file-btn" class="action-btn" onclick="document.getElementById('image-file-input').click()" title="Attach Image">
                        <i class="fas fa-paperclip file-upload-icon"></i>
                    </button>
                    
                    <!-- Emoji Input Button (NEW) -->
                    <button id="emoji-input-btn" class="action-btn" title="Insert Emoji">
                        <i class="far fa-smile"></i>
                    </button>

                    <textarea id="chat-input" placeholder="Type an encrypted message..." rows="1" oninput="autoExpand(this)"></textarea>
                    <button id="send-chat-btn" class="send-btn action-btn">➤</button>
                </div>
            </div>
        </div>

    </div>
    
    <div id="context-menu">
        <div class="context-menu-item" id="context-copy">Copy</div>
        <div class="context-menu-item" id="context-download">⬇️ Download Image</div>
        <div class="context-menu-item" id="context-unsend">🗑️ Unsend</div>
    </div>
    
    <div id="reaction-menu">
        <span class="reaction-menu-item" data-emoji="👍">👍</span>
        <span class="reaction-menu-item" data-emoji="❤️">❤️</span>
        <span class="reaction-menu-item" data-emoji="😂">😂</span>
        <span class="reaction-menu-item" data-emoji="🔥">🔥</span>
        <span class="reaction-menu-item" data-emoji="🙏">🙏</span>
        <span class="reaction-menu-item" data-emoji="🎉">🎉</span>
    </div>
    <div id="reaction-users-popover">
    </div>
    <script>
        // --- ENCRYPTION CONFIGURATION ---
        const AES_SECRET_KEY = 'secure-dfs-chat-key';

        function encryptAES(text) {
            try {
                return CryptoJS.AES.encrypt(text, AES_SECRET_KEY).toString();
            } catch (e) {
                console.error("Encryption failed:", e);
                return text; 
            }
        }

        function decryptAES(ciphertext) {
            try {
                const bytes = CryptoJS.AES.decrypt(ciphertext, AES_SECRET_KEY);
                return bytes.toString(CryptoJS.enc.Utf8);
            } catch (e) {
                console.error("Decryption failed:", e);
                return ciphertext; 
            }
        }
        
        // --- PHP DATA INTEGRATION ---
        const phpCurrentUserId = <?php echo json_encode($user_details['id']); ?>;
        const isAdmin = <?php echo json_encode($is_admin); ?>;
        // CRITICAL: All AJAX calls, including upload, point to this file
        const AJAX_ENDPOINT = 'chat_console.php';
        
        // --- CHAT STATE AND POLLING ---
        const chatLogElement = document.getElementById('chat-messages-log');
        let userListElement = document.getElementById('user-channel-list');
        const chatInput = document.getElementById('chat-input');
        const sendBtn = document.getElementById('send-chat-btn');
        const contextMenu = document.getElementById('context-menu');
        
        // NEW: Image state variables
        const imageFileInput = document.getElementById('image-file-input');
        const imagePreview = document.getElementById('image-preview');
        const imagePreviewContainer = document.getElementById('image-preview-container');
        const removeImageBtn = document.getElementById('remove-image-btn');
        let selectedImageFile = null; 
        
        // NEW: Emoji Input State
        const emojiInputBtn = document.getElementById('emoji-input-btn');
        const inlineEmojiPicker = document.getElementById('inline-emoji-picker');
        
        // NEW: Reaction state variables
        const reactionMenu = document.getElementById('reaction-menu');
        const reactionUsersPopover = document.getElementById('reaction-users-popover');
        let reactionTargetMessageId = null; 
        let reactionTargetLiElement = null; 

        // --- EMOJI PICKER CATEGORY DATA ---
        const EMOJI_CATEGORIES = [
            { name: 'Recents', icon: '🕒', key: 'recents' }, // Dynamic category
            { name: 'Faces & People', icon: '😀', key: 'faces' },
            { name: 'Hearts & Love', icon: '❤️', key: 'hearts' },
            { name: 'Gestures', icon: '👍', key: 'gestures' },
            { name: 'Celebration', icon: '🎉', key: 'celebration' },
            { name: 'Objects', icon: '💡', key: 'objects' },
            { name: 'Nature', icon: '☀️', key: 'nature' },
            { name: 'Weather', icon: '🌧️', key: 'weather' },
            { name: 'Animals', icon: '🐶', key: 'animals' },
            { name: 'Food & Drink', icon: '🍔', key: 'food' },
            { name: 'Travel & Places', icon: '🚗', key: 'travel' },
            { name: 'Activities & Sports', icon: '⚽', key: 'activities' },
            { name: 'Time & Calendar', icon: '⏳', key: 'time' },
            { name: 'Tools & Equipment', icon: '🛠️', key: 'tools' },
            { name: 'Symbols', icon: '❗', key: 'symbols' }
        ];
        // Full map for lookup
        const EMOJI_MAP = {
            faces: ['😀','😃','😄','😁','😆','😂','🤣','😊','😇','🙂','😉','😍','🤩','😘','😋','😜','😝','😛','🤪','🤨','🧐','🤓','😎','🥳','🤯','😱','😨','😰','😢','😭','😡','🤬','😤','😴','😪','🤤','🤗','🤮','🤒','🤕'],
            hearts: ['❤️','💛','💚','💙','💜','🖤','🤍','🤎','💔','❣️','💕','💞','💓','💘'],
            gestures: ['👍','👎','👌','🤌','🙏','🙌','👏','🤝','✋','🤚','🖐️','✌️','🤟','🤘'],
            celebration: ['🎉','🎊','🥳','🎁','🎈','✨','🌟','💥'],
            objects: ['✅','❌','💡','⚙️','🖥️','📚','💰','🚀','👑','💎','🎯','📌','📝','📞','📢','⌛','⏳','🕒','🎧','📷','🎥','🔒','🔑','⚡','💥','🎀','🔔'],
            nature: ['🌈','☀️','🌧️','⛈️','❄️','🌙','⭐','🌍','🌿','🔥'],
            weather: ['☁️','🌤️','🌩️','🌪️','🌫️','🌨️'],
            animals: ['🐶','🐱','🐭','🐹','🐰','🦊','🐻','🐼','🐯','🦁','🐮','🐷','🐸','🐵'],
            food: ['🍎','🍇','🍉','🍌','🍓','🍒','🍑','🍍','🥭','🍔','🍕','🌭','🍟','🍜','🍩'],
            travel: ['🚗','🏍️','🚌','🚆','🚄','✈️','🚁','🚀','🚢','🛵'],
            activities: ['🛠️','🧰','💻','📱','📦','🎁','🏆','⚽','🏏','🏀','🎮','🎤','🎸','⚾'],
            time: ['⌛','⏳','🕒','⏰','🕕','⏱️','📅','📆'],
            tools: ['🔧','🔨','⚒️','🪚','🔩','🔗','🧱','⚙️'],
            symbols: ['❗','❓','⚠️','♻️','✳️','✴️','⭕','🔴','🟢','🔵','⬆️','⬇️','➡️','⬅️']
        };


        let recentEmojis = JSON.parse(localStorage.getItem('recentEmojis')) || [];
        const MAX_RECENT_EMOJIS = 15;
        let activeEmojiCategory = 'faces'; // Default category
        
        // Helper function to save recent emojis
        function addRecentEmoji(emoji) {
            // Remove if already exists to move it to the front
            recentEmojis = recentEmojis.filter(e => e !== emoji);
            // Add to the front
            recentEmojis.unshift(emoji);
            // Limit size
            if (recentEmojis.length > MAX_RECENT_EMOJIS) {
                recentEmojis.pop();
            }
            localStorage.setItem('recentEmojis', JSON.stringify(recentEmojis));
        }
        
        // Function to render the emoji grid for the active category
        function renderEmojiGrid() {
            const grid = document.getElementById('emoji-grid');
            grid.innerHTML = ''; // Clear previous emojis

            let emojisToDisplay = [];
            if (activeEmojiCategory === 'recents') {
                emojisToDisplay = recentEmojis;
                if (emojisToDisplay.length === 0) {
                     grid.innerHTML = '<div style="grid-column: 1 / -1; text-align: center; color: #999;">Start using emojis to see your recents here!</div>';
                     return;
                }
            } else {
                emojisToDisplay = EMOJI_MAP[activeEmojiCategory];
            }
            
            // Create and append emoji items
            emojisToDisplay.forEach(emoji => {
                const item = document.createElement('span');
                item.className = 'emoji-item';
                item.textContent = emoji;
                item.setAttribute('data-emoji', emoji);
                item.addEventListener('click', (e) => {
                    // This click handler is moved inside renderEmojiGrid() now
                    e.stopPropagation();
                    handleEmojiInsertion(emoji);
                    addRecentEmoji(emoji); // Update recents
                    if (activeEmojiCategory === 'recents') {
                        renderEmojiGrid(); // Re-render recents list to show new order
                    }
                });
                grid.appendChild(item);
            });
        }

        // Function to switch category
        function switchEmojiCategory(categoryKey) {
            activeEmojiCategory = categoryKey;
            // Update active class on tabs
            document.querySelectorAll('.emoji-category-tab').forEach(tab => {
                if (tab.getAttribute('data-category-key') === categoryKey) {
                    tab.classList.add('active');
                } else {
                    tab.classList.remove('active');
                }
            });
            // Render the new grid
            renderEmojiGrid();
        }

        // Function to initialize the entire picker (tabs + initial grid)
        function initializeEmojiPicker() {
            const categoriesContainer = document.getElementById('emoji-categories');
            categoriesContainer.innerHTML = '';
            
            EMOJI_CATEGORIES.forEach(cat => {
                const tab = document.createElement('span');
                tab.className = 'emoji-category-tab';
                tab.textContent = cat.icon;
                tab.title = cat.name;
                tab.setAttribute('data-category-key', cat.key);
                tab.addEventListener('click', () => switchEmojiCategory(cat.key));
                categoriesContainer.appendChild(tab);
            });
            
            // Set initial active category based on recents presence
            if (recentEmojis.length > 0) {
                activeEmojiCategory = 'recents';
            } else {
                activeEmojiCategory = 'faces';
            }
            switchEmojiCategory(activeEmojiCategory);
        }
        // --- END EMOJI PICKER CATEGORY DATA ---

        let activeRecipientId = localStorage.getItem('activeChatId') ? parseInt(localStorage.getItem('activeChatId')) : 0;
        let chatStatusInterval = null;
        let userStatusInterval = null;
        const USER_STATUS_POLL_INTERVAL = 10000;
        const CHAT_HISTORY_POLL_INTERVAL = 3000;
        
        // Stores the message ID and text of the message currently right-clicked
        let contextMessageData = { id: null, text: null, isSelf: false, isImage: false, dfsUrl: null, liElement: null };

        // --- UTILITY FUNCTIONS ---
        
        function htmlspecialchars(str) {
            if (typeof str === 'string') {
                return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
            }
            return str;
        }

        function showNotification(message, type = 'error') {
            const container = document.getElementById('notification-container');
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.innerHTML = `<strong>${type.toUpperCase()}:</strong> ${htmlspecialchars(message)}`;
            
            container.prepend(notification);

            setTimeout(() => {
                notification.style.opacity = '0';
                notification.style.transition = 'opacity 0.5s';
                setTimeout(() => notification.remove(), 500);
            }, 5000);
        }
        // Exposing searchAndSwitchChat globally for the input element
window.searchAndSwitchChat = async function(query) {
    query = query.trim();
    if (query.toLowerCase() === '#global') {
        // Special case: Switch to the Global Chat
        const globalLi = userListElement.querySelector('li[data-user-id="0"]');
        if (globalLi) {
            switchChat(0, globalLi);
            document.getElementById('user-search-input').value = '';
        }
        return;
    }
    
    if (query.length < 3 || !query.includes('@')) {
        showNotification("Please enter a valid user email (minimum 3 characters, must include '@') or '#Global'.", 'warning');
        return;
    }

    showNotification(`Searching for user: ${query}...`, 'warning');

    try {
        const response = await fetch(`${AJAX_ENDPOINT}?action=search_user_by_email`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `email=${encodeURIComponent(query)}`
        });

        const data = await response.json();

        if (data.status === 'success' && data.user_id) {
            const userId = data.user_id;
            const alias = data.email_alias;

            // 1. Check if the user is already in the list
            let liElement = userListElement.querySelector(`li[data-user-id="${userId}"]`);

            if (!liElement) {
                // 2. If not found, dynamically create and prepend the list item
                const tempLi = document.createElement('li');
                tempLi.setAttribute('data-user-id', userId);
                tempLi.setAttribute('data-is-admin', 'false'); // Assuming search results are not admin unless explicitly checked
                tempLi.onclick = (e) => switchChat(userId, tempLi);
                
                const initial = alias.charAt(0).toUpperCase();
                const placeholderUrl = `https://placehold.co/40x40/cccccc/333333?text=${initial}`;
                const imgOnError = `this.onerror=null; this.src='${placeholderUrl}'`;

                tempLi.innerHTML = `
                    <img src="${placeholderUrl}" class="profile-pic-small" alt="Profile for ${alias}" onerror="${imgOnError}">
                    <div class="user-list-content">
                        <div class="user-info-row">
                            <span class="name" title="${alias}">
                                <span class="online-dot red"></span>${alias}
                            </span>
                            <span class="last-message-time" id="time-${userId}"></span>
                        </div>
                        <span class="last-message-text" id="msg-${userId}">New Chat - Click to start</span>
                    </div>
                    <span class="unread-badge" id="unread-${userId}" style="display:none;">0</span>
                `;
                // Prepend the new chat entry right after the Global Chat
                const globalChatLi = userListElement.querySelector('li[data-user-id="0"]');
                if (globalChatLi) {
                    globalChatLi.after(tempLi);
                } else {
                    userListElement.prepend(tempLi);
                }
                liElement = tempLi;
            }
            
            // 3. Switch to the chat
            switchChat(userId, liElement);
            document.getElementById('user-search-input').value = '';
            showNotification(`Chat with ${alias} opened.`, 'success');

        } else {
            showNotification(data.message || `User '${query}' not found.`, 'error');
        }

    } catch (err) {
        console.error('User search error:', err);
        showNotification("Failed to connect to the server for user search.", 'error');
    }
}
        
        function toggleTheme() {
            const currentTheme = document.documentElement.getAttribute('data-theme');
            const newTheme = currentTheme === 'night' ? 'day' : 'night';
            
            document.documentElement.setAttribute('data-theme', newTheme);
            document.cookie = `theme=${newTheme}; max-age=${365 * 24 * 60 * 60}; path=/`; 
        }

        function autoExpand(field) {
            field.style.height = 'auto';
            field.style.height = (field.scrollHeight) + 'px';
        }
        
        function formatTimeForList(timestamp) {
            if (!timestamp) return '';
            const now = new Date();
            const date = new Date(timestamp * 1000); 
            
            if (date.toDateString() === now.toDateString()) {
                return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            } 
            const yesterday = new Date(now);
            yesterday.setDate(now.getDate() - 1);
            if (date.toDateString() === yesterday.toDateString()) {
                return 'Yesterday';
            }
            return date.toLocaleDateString();
        }

        /**
         * Provides the delivery status icon for messages sent by the current user.
         */
        function getDeliveryStatusIcon(isSelf, isRead) {
            if (!isSelf) return '';
            
            let icon = '✔✔'; 
            // The statusClass is applied if the message is read (isRead == 1).
            let statusClass = (isRead == 1) ? 'seen' : ''; 
            
            // NEW: Add is_read status to title for debugging blue tick status
            let title = `Status: ${isRead == 1 ? 'Seen (Blue)' : 'Delivered (Gray)'} [is_read=${isRead}]`;

            return `<span class="message-status ${statusClass}" title="${title}">${icon}</span>`;
        }
        window.getDeliveryStatusIcon = getDeliveryStatusIcon;
        
        function copyMessage(text) {
            // Using execCommand for better iframe compatibility
            const tempInput = document.createElement('textarea');
            tempInput.value = text;
            document.body.appendChild(tempInput);
            tempInput.select();
            document.execCommand('copy');
            document.body.removeChild(tempInput);

            showNotification('Message copied to clipboard! (Plain Text)', 'success');
        }
        
        /**
         * Converts plain text (potentially containing URLs) into HTML with clickable links.
         */
        function linkify(text) {
             // Replace newlines with <br> for display
            const textWithBreaks = text.replace(/\n/g, '<br>');
            
            const urlRegex = /(\b(https?|ftp):\/\/[-A-Z0-9+&@#\/%?=~_|!:,.;]*[-A-Z0-9+&@#\/%=~_|])|(\b(www\.)[-A-Z0-9+&@#\/%?=~_|!:,.;]*[-A-Z0-9+&@#\/%=~_|])|(\b[A-Z0-9.-]+\.[A-Z]{2,4}\b\/?[^\s<]*)/ig;
            
            return textWithBreaks.replace(urlRegex, function(url) {
                if (url.toLowerCase().endsWith('<br>')) return url; 

                let finalUrl = url;
                if (!url.match(/^(https?|ftp):\/\//i)) {
                    finalUrl = 'http://' + url;
                }
                const safeUrl = htmlspecialchars(finalUrl);
                const safeText = htmlspecialchars(url);
                return `<a href="${safeUrl}" target="_blank" rel="noopener noreferrer">${safeText}</a>`;
            });
        }
        
        // --- CHAT CONTEXT MENU LOGIC ---
        
        function hideReactionMenu() {
            reactionMenu.style.display = 'none';
            reactionTargetMessageId = null;
            reactionTargetLiElement = null;
        }

        function hideReactionUsersPopover() {
            reactionUsersPopover.style.display = 'none';
            reactionUsersPopover.innerHTML = '';
        }

        function showContextMenu(e, messageId, messageText, isSelf, isImage, dfsUrl, liElement) {
            e.preventDefault(); 
            e.stopPropagation(); 
            hideReactionMenu(); 
            hideReactionUsersPopover(); 
            
            // CRITICAL FIX: Ensure messageId is treated as a number
            contextMessageData = { 
                id: parseInt(messageId), 
                text: messageText, 
                isSelf: isSelf, 
                isImage: isImage, 
                dfsUrl: dfsUrl, // Store the Drive URL
                liElement: liElement 
            };

            // Calculate position
            const menuWidth = contextMenu.offsetWidth;
            const menuHeight = contextMenu.offsetHeight;
            let top = e.clientY;
            let left = e.clientX;

            if (left + menuWidth > window.innerWidth) {
                left = window.innerWidth - menuWidth - 10; 
            }
            if (top + menuHeight > window.innerHeight) {
                top = window.innerHeight - menuHeight - 10; 
            }

            contextMenu.style.top = `${top}px`;
            contextMenu.style.left = `${left}px`;
            
            // Show/Hide options based on message type and sender
            const unsendItem = document.getElementById('context-unsend');
            const copyItem = document.getElementById('context-copy');
            const downloadItem = document.getElementById('context-download');

            // Unsend is only for self
            unsendItem.style.display = isSelf ? 'block' : 'none';

            // Download is only for image messages
            downloadItem.style.display = isImage ? 'block' : 'none';

            // Copy is for text messages (or captions on images)
            // If it's an image, text might be 'IMAGE_SENT' placeholder, so we check for that too.
            const hasCopyableText = messageText && messageText.trim() !== 'IMAGE_SENT';
            copyItem.style.display = hasCopyableText ? 'block' : 'none';
            
            contextMenu.style.display = 'block';
        }

        function hideContextMenu() {
            contextMenu.style.display = 'none';
            // Reset context data to null/false to prevent accidental re-use
            contextMessageData = { id: null, text: null, isSelf: false, isImage: false, dfsUrl: null, liElement: null }; 
        }
        
        // Global listener to hide menus when clicking elsewhere
        document.addEventListener('click', (e) => {
             // Check if click target is outside of the file input area before hiding everything
             if (!e.target.closest('.input-controls')) {
                 hideContextMenu();
                 hideReactionMenu();
                 hideReactionUsersPopover();
                 // Hide inline emoji picker when clicking away
                 inlineEmojiPicker.style.display = 'none';
             }
        });
        
        // Prevent hiding the menus if clicking on the menu itself
        contextMenu.addEventListener('click', (e) => e.stopPropagation());
        reactionMenu.addEventListener('click', (e) => e.stopPropagation());
        reactionUsersPopover.addEventListener('click', (e) => e.stopPropagation());
        inlineEmojiPicker.addEventListener('click', (e) => e.stopPropagation()); // Keep picker open on click inside


        // FIX: Update right-click listener to ensure it gets the correct LI element
        chatLogElement.addEventListener('contextmenu', function(e) {
            // Search for the closest ancestor that is an LI and has the data-message-id attribute
            const li = e.target.closest('li[data-message-id]');
            
            if (li) {
                const messageId = li.getAttribute('data-message-id');
                const isSelf = li.classList.contains('sender-self');
                
                // Get the raw decrypted text and image status from the data attributes
                const messageText = li.getAttribute('data-raw-text') || ''; 
                const isImage = li.hasAttribute('data-is-image');
                const dfsUrl = li.getAttribute('data-dfs-url') || null; // NEW: Get the Drive URL
                
                // Show context menu on right click
                showContextMenu(e, messageId, messageText, isSelf, isImage, dfsUrl, li);
            }
        });
        
        // Context menu action: Copy
        document.getElementById('context-copy').addEventListener('click', () => {
            if (contextMessageData.text && contextMessageData.text.trim() !== 'IMAGE_SENT') {
                copyMessage(contextMessageData.text);
            } else {
                showNotification("Cannot copy: Message content is empty.", 'warning');
            }
            hideContextMenu();
        });

        // Context menu action: Download Image (NEW)
        document.getElementById('context-download').addEventListener('click', () => {
            if (contextMessageData.isImage && contextMessageData.dfsUrl) {
                downloadImage(contextMessageData.dfsUrl);
            } else {
                showNotification("This message does not contain a downloadable file.", 'warning');
            }
            hideContextMenu();
        });

        // Context menu action: Unsend
        document.getElementById('context-unsend').addEventListener('click', () => {
            // CRITICAL CHECK: Ensure ID is valid before proceeding
            if (contextMessageData.isSelf && contextMessageData.id && contextMessageData.id > 0) { 
                // Direct call to unsend without confirmation modal
                unsend(contextMessageData.id, contextMessageData.liElement, contextMessageData.dfsUrl);
            } else {
                showNotification("Unsend error: Invalid or missing message ID.", 'error');
            }
            hideContextMenu();
        });
        // --- END CHAT CONTEXT MENU LOGIC ---
        
        // --- DOWNLOAD LOGIC (NEW) ---
        function downloadImage(dfsUrl) {
            if (dfsUrl.includes("export=view")) {
                const downloadUrl = dfsUrl.replace("export=view", "export=download");
                
                // Use a temporary link element to trigger the download
                const link = document.createElement('a');
                link.href = downloadUrl;
                link.target = '_blank';
                link.download = 'chat_image_' + Date.now(); // Provide a default file name
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                showNotification('Image download started.', 'success');
            } else {
                showNotification('Could not create a valid download link.', 'error');
            }
        }
        
        // --- REACTION LOGIC (Existing code structure maintained) ---

        function toggleReaction(messageId, emoji) {
            fetch(`${AJAX_ENDPOINT}?action=toggle_reaction`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `message_id=${encodeURIComponent(messageId)}&emoji=${encodeURIComponent(emoji)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    loadChatHistory(activeRecipientId); 
                } else {
                    showNotification(data.message || "Failed to toggle reaction.", 'error');
                }
            })
            .catch(err => {
                console.error('Reaction toggle error:', err);
                showNotification("Error connecting to server for reaction.", 'error');
            });
        }


        function showReactionMenu(e, li) {
            let clientX = e ? e.clientX : null;
            let clientY = e ? e.clientY : null;
            
            e.preventDefault();
            e.stopPropagation(); 

            const messageId = li.getAttribute('data-message-id');
            
            reactionTargetMessageId = messageId;
            reactionTargetLiElement = li;

            const rect = li.querySelector('.chat-bubble').getBoundingClientRect();
            
            const menuWidth = 250; 
            let left = rect.left + (rect.width / 2) - (menuWidth / 2);
            let top = rect.top - 50; 
            
            // If triggered by a mouse event, try to position near the cursor
            if (clientX !== null && clientY !== null) {
                top = clientY - 50; 
                left = clientX - 125; // Estimate center of 250px wide menu
            }


            if (left < 10) left = 10;
            if (top < 10) top = 10;
            // Re-calculate the actual width of the element since popoverWidth was undefined
            const currentMenuWidth = reactionMenu.offsetWidth || 250; 
            if (left + currentMenuWidth > window.innerWidth) left = window.innerWidth - currentMenuWidth - 10;


            reactionMenu.style.top = `${top}px`;
            reactionMenu.style.left = `${left}px`;
            reactionMenu.style.display = 'flex';
            
            hideContextMenu(); 
            hideReactionUsersPopover(); 
        }
        
        // Primary trigger: Double click to open reaction menu
        chatLogElement.addEventListener('dblclick', function(e) {
            const li = e.target.closest('li[data-message-id]');
            // CRITICAL FIX: Only allow reaction dblclick if NOT an image message
            if (li && !li.hasAttribute('data-is-image')) { 
                showReactionMenu(e, li);
            }
        });
        
        // NEW FIX: Click handler for the '+' reaction button
        function handleReactionButtonClick(event) {
            event.stopPropagation();
            const li = event.target.closest('li[data-message-id]');
            // CRITICAL FIX: Only allow reaction button click if NOT an image message
            if (li && !li.hasAttribute('data-is-image')) {
                showReactionMenu(event, li);
            }
        }

        // Handle reaction selection click
        document.querySelectorAll('.reaction-menu-item').forEach(item => {
            item.addEventListener('click', (e) => {
                e.stopPropagation();
                if (reactionTargetMessageId) {
                    const emoji = item.getAttribute('data-emoji');
                    toggleReaction(reactionTargetMessageId, emoji);
                }
                hideReactionMenu();
            });
        });

        function fetchAndShowReactionUsers(messageId, targetElement) {
            hideReactionUsersPopover(); 
            hideReactionMenu(); 

            fetch(`${AJAX_ENDPOINT}?action=get_reaction_users`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `message_id=${encodeURIComponent(messageId)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success' && data.users.length > 0) {
                    let html = '<div class="popover-header">Reactions:</div>';
                    
                    const groupedUsers = data.users.reduce((acc, user) => {
                        if (!acc[user.emoji_code]) {
                            acc[user.emoji_code] = [];
                        }
                        acc[user.emoji_code].push(htmlspecialchars(user.email));
                        return acc;
                    }, {});

                    for (const emoji in groupedUsers) {
                        groupedUsers[emoji].forEach(email => {
                            html += `
                                <div class="reaction-user-entry">
                                    <span class="emoji">${emoji}</span>
                                    <span class="email">${email}</span>
                                </div>
                            `;
                        });
                    }

                    reactionUsersPopover.innerHTML = html;
                    reactionUsersPopover.setAttribute('data-message-id', messageId); 

                    // Ensure popover is displayed briefly to calculate size
                    reactionUsersPopover.style.display = 'block';
                    const targetRect = targetElement.getBoundingClientRect();
                    const popoverWidth = reactionUsersPopover.offsetWidth; 
                    const popoverHeight = reactionUsersPopover.offsetHeight;
                    
                    reactionUsersPopover.style.display = 'none';

                    let top = targetRect.top - popoverHeight - 5; 
                    let left = targetRect.left + (targetRect.width / 2) - (popoverWidth / 2); 

                    if (top < 10) {
                        top = targetRect.bottom + 5; 
                    }
                    if (left < 10) left = 10;
                    if (left + popoverWidth > window.innerWidth) left = window.innerWidth - popoverWidth - 10;
                    
                    reactionUsersPopover.style.top = `${top}px`;
                    reactionUsersPopover.style.left = `${left}px`;
                    reactionUsersPopover.style.display = 'block';

                } else {
                    showNotification('No detailed reaction data available.', 'warning');
                }
            })
            .catch(err => {
                console.error('Fetch reaction users error:', err);
                showNotification("Could not fetch user reactions from server.", 'error');
            });
        }
        // --- END REACTION LOGIC ---


        /**
         * Deletes a message from the DB and optionally deletes the file from Drive.
         * @param {number} messageId 
         * @param {HTMLElement} liElement 
         * @param {string|null} dfsUrl The Drive URL fetched from data-dfs-url (used if DB response is missed).
         */
        async function unsend(messageId, liElement, dfsUrl) {
            try {
                // 1. Delete message from the database
                const response = await fetch(`${AJAX_ENDPOINT}?action=unsend_message`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `message_id=${encodeURIComponent(messageId)}`
                });

                const data = await response.json();
                
                if (data.status === 'success') {
                    // 2. Determine file URL: prefer URL returned by PHP (more reliable check)
                    const driveUrl = data.dfs_url_to_delete || dfsUrl;

                    if (driveUrl) {
                        showNotification("Message unsent. Now deleting file from Google Drive...", 'warning');
                        
                        // Async call to delete file from Drive
                        fetch(`${AJAX_ENDPOINT}?action=delete_drive_file`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `dfs_url=${encodeURIComponent(driveUrl)}`
                        })
                        .then(response => response.json())
                        .then(driveData => {
                             // Log success or warning from Drive API, but message is already gone from chat.
                            showNotification("Unsend Complete. " + driveData.message, driveData.status); 
                        })
                        .catch(err => {
                            console.error('Drive deletion error:', err);
                            showNotification("Message unsent, but failed to connect for Drive file deletion.", 'warning');
                        });
                    } else {
                        showNotification("Message unsent.", 'success');
                    }
                    
                    if (liElement) {
                        liElement.remove();
                    }
                    loadUserStatuses(); 
                } else {
                    // This error comes from the PHP block now and is more diagnostic
                    showNotification(data.message || "Failed to unsend message.", 'error'); 
                }
            } catch (err) {
                console.error('Unsend operation error:', err);
                showNotification("Error connecting to the server for unsend request.", 'error');
            }
        }
        window.unsend = unsend;


        function removeImage() {
            selectedImageFile = null;
            imageFileInput.value = '';
            imagePreviewContainer.style.display = 'none';
            chatInput.setAttribute('placeholder', 'Type an encrypted message...');
            chatInput.value = ''; // Clear caption too
            chatInput.focus();
            autoExpand(chatInput);
        }

        // Variable to store the chat log's scroll position threshold
        let scrollThreshold = 100; // If user scrolls up more than 100px from bottom, stop auto-scrolling
        let lastScrollHeight = 0; // Tracks the height before the new message arrival

        // Variable to store the existing message element references by ID
        const existingMessages = new Map();

        function loadUserStatuses() {
             fetch(`${AJAX_ENDPOINT}?action=get_chat_list`, { method: 'POST' })
                 .then(response => response.json())
                 .then(data => {
                     if (data.status === 'success' && data.users) {
                         const activeId = activeRecipientId;
                         const usersData = data.users;
                         const partnerContainer = userListElement;
                         
                         const newContainer = document.createElement('ul');
                         newContainer.id = 'user-channel-list';
                         newContainer.classList.add('user-list');

                         // --- GLOBAL CHAT ENTRY (Fixed ID: 0) ---
                         const tempGlobalLi = document.createElement('li');
                         tempGlobalLi.setAttribute('data-user-id', 0);
                         tempGlobalLi.setAttribute('data-is-admin', 'false');
                         tempGlobalLi.onclick = (e) => switchChat(0, tempGlobalLi); 
                         tempGlobalLi.innerHTML = `<img src="https://placehold.co/40x40/cccccc/333333?text=G" class="profile-pic-small" alt="Global">
                              <div class="user-list-content">
                                  <div class="user-info-row">
                                      <span class="name"># Global Chat</span>
                                      <span class="last-message-time" id="time-0"></span>
                                  </div>
                                  <span class="last-message-text" id="msg-0">Broadcast Channel</span>
                              </div>
                              <span class="unread-badge" id="unread-0" style="display:none;">0</span>`;
                         
                         if (activeId === 0) tempGlobalLi.classList.add('active');
                         newContainer.appendChild(tempGlobalLi);


                         // --- DYNAMIC DM ENTRIES ---
                         const newDmsHtml = usersData.map(user => {
                             const unreadCount = parseInt(user.unread_count || 0);
                             const alias = htmlspecialchars(user.email);
                             
                             const lastActivityTime = user.last_activity_timestamp ? new Date(user.last_activity_timestamp.replace(' ', 'T') + 'Z').getTime() / 1000 : 0;
                             const isOnline = (lastActivityTime > (Date.now() / 1000) - 300);
                             const statusColor = isOnline ? 'green' : 'red'; 
                             
                             const profilePicSrc = user.profile_picture_url || '';
                             const initial = alias.charAt(0).toUpperCase();

                             const placeholderUrl = `https://placehold.co/40x40/cccccc/333333?text=${initial}`;
                             const imgOnError = `this.onerror=null; this.src='${placeholderUrl}'`;

                             const decryptedSnippet = decryptAES(user.last_message_preview || 'Start chatting...');
                             
                             // FIX: Check for image placeholder for preview display
                             const lastMessageText = (decryptedSnippet.trim() === 'IMAGE_SENT') 
                                 ? '🖼️ [Image Sent]' 
                                 : htmlspecialchars(decryptedSnippet);
                             const lastMessageTime = formatTimeForList(user.last_message_time);
                             
                             const textClass = unreadCount > 0 ? 'last-message-text unread' : 'last-message-text';
                             const activeClass = user.user_id == activeId ? 'active' : '';

                             return `
                                 <li data-user-id="${user.user_id}" data-is-admin="false" class="${activeClass}" onclick="switchChat(${user.user_id}, this)">
                                     <img src="${profilePicSrc || placeholderUrl}" 
                                         class="profile-pic-small" 
                                         alt="Profile for ${alias}"
                                         onerror="${imgOnError}">
                                     <div class="user-list-content">
                                         <div class="user-info-row">
                                             <span class="name" title="${alias}">
                                                 <span class="online-dot ${statusColor}"></span>
                                                 ${alias}
                                             </span>
                                             <span class="last-message-time" id="time-${user.user_id}">${lastMessageTime}</span>
                                         </div>
                                         <span class="${textClass}" id="msg-${user.user_id}">${lastMessageText}</span>
                                     </div>
                                     <span class="unread-badge" id="unread-${user.user_id}" style="display:${unreadCount > 0 ? 'inline' : 'none'};">
                                         ${unreadCount}
                                     </span>
                                 </li>
                             `;
                         }).join('');

                         newContainer.insertAdjacentHTML('beforeend', newDmsHtml);
                         
                         partnerContainer.replaceWith(newContainer);
                         userListElement = newContainer;

                         document.querySelectorAll('.user-list li').forEach(li => li.classList.remove('active'));
                         const finalActiveLi = userListElement.querySelector(`li[data-user-id="${activeId}"]`);
                         if (finalActiveLi) finalActiveLi.classList.add('active');
                         else userListElement.querySelector('li[data-user-id="0"]').classList.add('active');

                         enforceGlobalChatInputState(activeId);
                     }
                 })
                 .catch(err => {
                     console.error('loadUserStatuses error', err);
                 });
        }


        /**
         * Parses the Google Drive File ID from the stored URL.
         */
        function getDfsFileIdFromUrl(dfsUrl) {
            if (!dfsUrl) return null;
            // Matches: id=<FILE_ID> OR file/d/<FILE_ID>
            const idMatch = dfsUrl.match(/id=([a-zA-Z0-9_-]+)|file\/d\/([a-zA-Z0-9_-]+)/);
            if (idMatch) {
                // Return the first captured group that isn't null
                return idMatch[1] || idMatch[2];
            }
            return null;
        }

        /**
         * Constructs a stable direct link for embedding images 
         * using the server-side proxy and file ID.
         */
        function getDfsFileDownloadUrl(dfsUrl) {
            const fileId = getDfsFileIdFromUrl(dfsUrl);
            if (!fileId) return null;
            
            // CRITICAL CHANGE: Point to our new server-side proxy
            return `image_proxy.php?id=${fileId}`; 
        }


        /**
         * 
         * Renders a single message element, including reactions and status.
         */
        function createMessageElement(msg, recipientId) {
            const isSelf = (msg.sender_id == phpCurrentUserId);
            const isSenderAdmin = msg.is_admin;
            const isRead = msg.is_read;
            const dfsFileId = msg.dfs_file_id; // This is the full URL stored in the DB
            const messageId = msg.id; 

            let decryptedText = decryptAES(msg.message_text);
            const rawText = decryptedText.trim();
            const formattedText = linkify(decryptedText);
            const isImageMessage = !!dfsFileId; 

            let senderName = '';
            if (recipientId === 0 && !isSelf) {
                if (isSenderAdmin) {
                    senderName = 'Admin';
                } else {
                    senderName = msg.email;
                }
            }
            
            const li = document.createElement('li');
            li.classList.add(isSelf ? 'sender-self' : 'sender-other');
            li.setAttribute('data-message-id', messageId); 
            li.setAttribute('data-raw-text', htmlspecialchars(rawText)); 
            
            if (isImageMessage) {
                li.setAttribute('data-is-image', 'true'); 
                li.setAttribute('data-dfs-url', dfsFileId); // NEW: Store the full URL for the context menu
            }
            
            const bubble = document.createElement('div');
            bubble.className = 'chat-bubble';

            let content = '';
            if (recipientId === 0 && !isSelf) {
                content += `<span class="message-sender">${htmlspecialchars(senderName)}</span><br>`;
            }
            
            // --- Image Display Logic ---
            if (isImageMessage && dfsFileId) {
                // Use the dedicated thumbnail URL for inline display
                const imageSrcUrl = getDfsFileDownloadUrl(dfsFileId); 
                // Keep the original link for opening in a new tab (which uses the document viewer)
                const originalLink = dfsFileId; 
                
                content += `<img src="${imageSrcUrl}" 
                                     class="chat-image" 
                                     onclick="window.open('${originalLink}', '_blank')" 
                                     alt="Sent Image"
                                     onerror="this.onerror=null; this.src='${imageSrcUrl}'; this.style.cursor='default';"><br>`;
            } 
            
            // --- Text/Caption Display Logic ---
            const hasVisibleTextContent = (rawText && rawText !== 'IMAGE_SENT');

            if (hasVisibleTextContent) {
                const textClass = isImageMessage ? 'image-caption-text' : 'text-content';
                
                content += `<div class="message-content-html ${textClass}">${formattedText}</div>`;
            }
            
            // --- Time and Status ---
            content += `<div class="message-time-wrapper">`;
            const messageDate = new Date(msg.timestamp.replace(' ', 'T'));
            content += `<span class="message-time">${messageDate.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</span>`;
            content += getDeliveryStatusIcon(isSelf, isRead); 
            content += `</div>`;
            
            bubble.innerHTML = content;
            li.appendChild(bubble);

            // --- REACTION RENDERING ---
            if (msg.reactions_summary) {
                const reactionDiv = document.createElement('div');
                reactionDiv.className = `message-reactions ${isSelf ? 'sender-self' : 'sender-other'}`; 
                
                const reactions = msg.reactions_summary.split(';');
                const userReaction = msg.user_reaction; 

                reactions.forEach(reactionStr => {
                    const parts = reactionStr.split(':');
                    const emoji = parts[0];
                    const count = parts[1];
                    
                    if (emoji && count) {
                        const badge = document.createElement('span');
                        badge.className = 'reaction-badge';
                        badge.textContent = `${emoji} ${count}`;
                        
                        if (emoji === userReaction) {
                            badge.classList.add('user-reacted');
                        }
                        
                        const isUsersReaction = (emoji === userReaction);
                        
                        if (isUsersReaction) {
                            badge.onclick = (e) => {
                                e.stopPropagation();
                                toggleReaction(messageId, emoji);
                            };
                        } else {
                            badge.onclick = (e) => {
                                e.stopPropagation();
                                fetchAndShowReactionUsers(messageId, badge);
                            };
                        }

                        badge.addEventListener('dblclick', (e) => {
                            e.stopPropagation();
                            if (!isImageMessage) { 
                                showReactionMenu(e, li);
                            }
                        });

                        reactionDiv.appendChild(badge);
                    }
                });
                
                li.appendChild(reactionDiv);
            }

            return li;
        }

        /**
         * Updates reactions and read status on an existing message element.
         */
        function updateExistingMessage(existingLi, newMsg, recipientId) {
            // 1. Update read status/delivery ticks
            const statusSpan = existingLi.querySelector('.message-status');
            const isSelf = (newMsg.sender_id == phpCurrentUserId);
            if (statusSpan && isSelf) {
                statusSpan.outerHTML = getDeliveryStatusIcon(isSelf, newMsg.is_read);
            }

            // 2. Update Reactions
            const existingReactionDiv = existingLi.querySelector('.message-reactions');
            if (existingReactionDiv) {
                 existingReactionDiv.remove();
            }

            // Re-create and append the reaction element if needed (using the same logic as createMessageElement)
            if (newMsg.reactions_summary) {
                const tempLi = createMessageElement(newMsg, recipientId);
                const newReactionDiv = tempLi.querySelector('.message-reactions');
                if (newReactionDiv) {
                    existingLi.appendChild(newReactionDiv);
                }
            }
        }


        let isFirstLoad = true; // Flag for initial full render

        function loadChatHistory(recipientId) {
            const endpointUrl = `${AJAX_ENDPOINT}?action=get_history`;
            const body = `recipient_id=${encodeURIComponent(recipientId)}`;
            
            // FIX 1: Determine if we should auto-scroll before fetching new messages
            const autoScroll = (chatLogElement.scrollHeight - chatLogElement.scrollTop - chatLogElement.clientHeight) < scrollThreshold;
            lastScrollHeight = chatLogElement.scrollHeight; // Save current height

            // Map to track current message IDs for quick lookup
            const currentMessageIds = new Map();
            if (!isFirstLoad) {
                chatLogElement.querySelectorAll('li[data-message-id]').forEach(li => {
                    currentMessageIds.set(li.getAttribute('data-message-id'), li);
                });
            }


            fetch(endpointUrl, { 
                method: 'POST', 
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, 
                body: body 
            })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success' && data.messages) {
                        
                        let shouldScroll = isFirstLoad || autoScroll;
                        
                        if (isFirstLoad) {
                            chatLogElement.innerHTML = '';
                        }

                        data.messages.forEach(msg => {
                            const messageId = String(msg.id);
                            let existingLi = currentMessageIds.get(messageId);

                            if (existingLi) {
                                // Message exists: Only update volatile data (reactions, read status)
                                updateExistingMessage(existingLi, msg, recipientId);
                                currentMessageIds.delete(messageId); // Mark as processed
                            } else {
                                // Message is new: Render and append it
                                const newLi = createMessageElement(msg, recipientId);
                                chatLogElement.appendChild(newLi);
                                shouldScroll = true; // Always scroll on new message arrival
                            }
                        });

                        isFirstLoad = false;
                        
                        // Handle removal of deleted messages (if any were in currentMessageIds but not in data.messages)
                        currentMessageIds.forEach(li => {
                             li.remove();
                        });

                        // Smart Scrolling
                        if (shouldScroll) {
                            chatLogElement.scrollTop = chatLogElement.scrollHeight;
                        }

                        if (recipientId !== 0) {
                            markMessagesAsRead(recipientId); 
                        }
                    } else if (data.status === 'error') {
                        // Only show error message on screen if chat is currently empty/not loaded
                        if (isFirstLoad) {
                            chatLogElement.innerHTML = `<li style="justify-content:center;color:var(--error-color);">Error loading chat: ${htmlspecialchars(data.message || 'Check database connection.')}</li>`;
                        } else {
                            // Log silent error for polling failure
                            console.error('Chat history polling error:', data.message);
                        }
                        isFirstLoad = false;
                    }
                })
                .catch(err => {
                    console.error('loadChatHistory fetch error', err);
                    if (isFirstLoad) {
                        chatLogElement.innerHTML = `<li style="justify-content:center;color:var(--error-color);">Failed to connect to chat server backend.</li>`;
                    }
                    isFirstLoad = false;
                });
        }
        
        
        function markMessagesAsRead(partnerId) {
             fetch(`${AJAX_ENDPOINT}?action=set_read`, {
                 method: 'POST',
                 headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                 body: `partner_id=${encodeURIComponent(partnerId)}`
             })
             .then(response => {
                 if (response.ok) {
                     const badge = userListElement.querySelector(`li[data-user-id="${partnerId}"] .unread-badge`);
                     const msgSpan = userListElement.querySelector(`li[data-user-id="${partnerId}"] .last-message-text`);
                     
                     if (badge) {
                         badge.style.display = 'none';
                         badge.textContent = '0';
                     }
                     if (msgSpan) {
                          msgSpan.classList.remove('unread');
                     }
                 }
                 return response.json();
             })
             .catch(err => {
                 console.error('markMessagesAsRead error', err);
             });
        }

        
        async function uploadAndSendMessage(message, recipientIdToSend) {
            let encryptedMessage = '';
            let dfsFileId = null; 

            if (selectedImageFile) {
                showNotification("Uploading image to Google Drive...", 'success');
                sendBtn.disabled = true;
                
                // 1. Upload file to the self-contained AJAX handler
                const formData = new FormData();
                formData.append('image_file', selectedImageFile);

                try {
                    // CRITICAL: Call the same file, triggering the 'dfs_upload_image' case
                    const uploadResponse = await fetch(`${AJAX_ENDPOINT}?action=dfs_upload_image`, {
                        method: 'POST',
                        body: formData
                    });

                    // We explicitly check the response status before parsing JSON
                    if (!uploadResponse.ok) {
                        const errorText = await uploadResponse.text();
                        console.error('Upload failed with HTTP status:', uploadResponse.status, 'Response text:', errorText);
                        // If it's a fatal PHP error, it might not be JSON, so we rely on the text capture above.
                        throw new Error("HTTP " + uploadResponse.status + " error during upload. Check console for PHP fatal errors.");
                    }
                    
                    const uploadData = await uploadResponse.json();

                    if (uploadData.status !== 'success' || !uploadData.dfs_file_id) {
                        showNotification(uploadData.message || "Image upload failed. Message not sent.", 'error');
                        sendBtn.disabled = false;
                        return;
                    }

                    // CRITICAL CHANGE: The PHP side now returns ONLY the File ID
                    dfsFileId = uploadData.dfs_file_id; 
                    
                    // Set encrypted message to the caption OR a placeholder if no caption provided
                    encryptedMessage = encryptAES(message || 'IMAGE_SENT'); 

                    showNotification(`Image uploaded! Sending message...`, 'success');

                } catch (err) {
                    // This catches network errors, JSON parsing errors, and the custom HTTP error thrown above
                    console.error('Upload fetch/process error:', err);
                    // CRITICAL FIX: Give a more specific error message based on the known failure modes
                    showNotification("Image upload failed. This is often due to a revoked Google Token or missing API configuration. Error: " + err.message, 'error');
                    sendBtn.disabled = false;
                    return;
                }
            } else if (message) {
                // 2. Encrypt text message
                encryptedMessage = encryptAES(message);
            } else {
                // Should not happen if guard is correct, but safe exit
                return;
            }

            // 3. Send message metadata (encrypted text and File ID)
            // CRITICAL FIX: After successful message API call, we clear the input IMMEDIATELY in the success block.
            fetch(`${AJAX_ENDPOINT}?action=send_message`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                // CRITICAL: Send the File ID only in dfs_file_id
                body: `message=${encodeURIComponent(encryptedMessage)}&recipient_id=${encodeURIComponent(recipientIdToSend)}&dfs_file_id=${dfsFileId || ''}`
            })
            .then(response => response.json())
            .then(data => {
                sendBtn.disabled = false;
                if (data.status === 'success') {
                    
                    // FIX: Clear the input on the client-side immediately upon successful message submission
                    // This solves the duplicate caption race condition.
                    removeImage(); // Clears image state, input value, and placeholder
                    
                    // Optional: Redundant clearing, but ensures all is reset
                    if (chatInput) {
                        chatInput.value = '';
                        chatInput.style.height = 'auto'; 
                        chatInput.rows = 1;
                    }

                    loadChatHistory(activeRecipientId);
                    loadUserStatuses(); 
                } else {
                    showNotification("Error sending message: " + (data.message || "Unknown server error."), 'error');
                }
            })
            .catch(err => {
                sendBtn.disabled = false;
                console.error('sendMessage fetch error', err);
                showNotification("Error sending message: Failed to connect to backend service.", 'error');
            });
        }
        
        // Primary sendMessage wrapper
        function sendMessage() {
            const message = chatInput ? chatInput.value.trim() : '';
            const recipientIdToSend = activeRecipientId; 

            // Allow sending if there is text OR an image is selected
            if (!message && !selectedImageFile) return; 
            
            if (recipientIdToSend === 0 && !isAdmin) {
                showNotification("Permission denied. Only Admins can send global broadcasts.", 'error');
                return;
            }
            
            uploadAndSendMessage(message, recipientIdToSend);
        }

        // NEW: Handles inserting an emoji into the textarea at the cursor position
        function handleEmojiInsertion(emoji) {
            const start = chatInput.selectionStart;
            const end = chatInput.selectionEnd;
            const text = chatInput.value;

            // Insert emoji at the cursor position
            chatInput.value = text.substring(0, start) + emoji + text.substring(end);

            // Move cursor to the end of the inserted emoji
            chatInput.selectionStart = chatInput.selectionEnd = start + emoji.length;
            
            // Re-expand the input field if necessary
            autoExpand(chatInput);
        }

        // --- INITIALIZATION ---
        document.addEventListener('DOMContentLoaded', () => {
            
            // Event Listeners
            sendBtn.addEventListener('click', sendMessage);
            removeImageBtn.addEventListener('click', removeImage);
            
            // Initialize the dynamic emoji picker components
            initializeEmojiPicker();

            // Emoji Input Toggle
            emojiInputBtn.addEventListener('click', (e) => {
                e.stopPropagation(); // Prevents global listener from immediately closing it
                // Toggle display state
                const isVisible = inlineEmojiPicker.style.display === 'flex';
                inlineEmojiPicker.style.display = isVisible ? 'none' : 'flex';
                if (!isVisible) {
                    // Re-render the grid (useful for the Recents category)
                    renderEmojiGrid(); 
                }
                // Hide reaction menus just in case
                hideReactionMenu();
                hideReactionUsersPopover();
            });
            
            // Handle image file selection
            imageFileInput.addEventListener('change', (e) => {
                const file = e.target.files[0];
                if (file && file.type.startsWith('image/')) {
                    selectedImageFile = file;
                    const reader = new FileReader();
                    
                    reader.onload = (e) => {
                        imagePreview.src = e.target.result;
                        
                        // FIX: Ensure the container is explicitly visible and display: flex
                        imagePreviewContainer.style.display = 'flex'; 
                        
                        chatInput.setAttribute('placeholder', `Add caption (optional) for ${file.name}...`);
                        autoExpand(chatInput);
                        showNotification('Image loaded and ready to send. Check the preview above.', 'success'); 
                    };
                    
                    reader.onerror = (e) => {
                         showNotification('Error reading file. Try a different file.', 'error');
                         removeImage();
                    };
                    
                    reader.readAsDataURL(file);
                } else {
                    removeImage();
                    showNotification('Please select a valid image file.', 'warning');
                }
            });
            
            // Handle Shift+Enter for new line, Enter for send.
            chatInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    if (!e.shiftKey) {
                        e.preventDefault(); // Prevent default newline behavior
                        sendMessage();
                    } 
                    // If Shift is pressed (Shift+Enter), allow default behavior (newline) 
                    setTimeout(() => autoExpand(this), 0);
                }
            });

            loadUserStatuses();
            userStatusInterval = setInterval(loadUserStatuses, USER_STATUS_POLL_INTERVAL); 

            setTimeout(() => {
                const initialElement = userListElement.querySelector(`li[data-user-id="${activeRecipientId}"]`);
                const globalLi = userListElement.querySelector('li[data-user-id="0"]');

                if (initialElement) {
                    switchChat(activeRecipientId, initialElement);
                } else {
                    switchChat(0, globalLi);
                }
            }, 500); 

            window.addEventListener('beforeunload', () => {
                if (chatStatusInterval) clearInterval(chatStatusInterval);
                if (userStatusInterval) clearInterval(userStatusInterval);
            });
        });
        
        // Exposing switchChat globally for the list items
        window.switchChat = function(newRecipientId, clickedElement) {
             localStorage.setItem('activeChatId', newRecipientId);
             activeRecipientId = newRecipientId;
            
             const chatTitleBar = document.getElementById('chat-title-bar');
            
             document.querySelectorAll('.user-list li').forEach(li => li.classList.remove('active'));
             if (clickedElement) clickedElement.classList.add('active');
            
             if (newRecipientId === 0) {
                 chatTitleBar.textContent = '# Global Chat (Encrypted)';
             } else {
                 const userAliasElement = clickedElement ? clickedElement.querySelector('.name') : null;
                 const alias = userAliasElement ? userAliasElement.getAttribute('title') || 'Direct Message' : 'Direct Message';
                 chatTitleBar.textContent = `Encrypted Chat with ${alias}`;
             }
            
             enforceGlobalChatInputState(newRecipientId);

             // Also clear any pending image state when switching chats
             removeImage(); 

             // When switching, perform a full load to clear old messages
             isFirstLoad = true; 
             loadChatHistory(newRecipientId);
            
             if (chatStatusInterval) clearInterval(chatStatusInterval);
             chatStatusInterval = setInterval(() => loadChatHistory(activeRecipientId), CHAT_HISTORY_POLL_INTERVAL);
        }

        // Exposing enforceGlobalChatInputState globally
        window.enforceGlobalChatInputState = function(recipientId) {
            const isGlobalChat = recipientId === 0;
            const canSendGlobal = isAdmin;
            const attachFileBtn = document.getElementById('attach-file-btn');
            
            if (isGlobalChat && !canSendGlobal) {
                chatInput.setAttribute('disabled', 'disabled');
                chatInput.setAttribute('placeholder', 'Only Admins can send messages to Global Chat (Encrypted).');
                chatInput.classList.add('disabled-chat-input');
                sendBtn.setAttribute('disabled', 'disabled');
                attachFileBtn.setAttribute('disabled', 'disabled');
                emojiInputBtn.setAttribute('disabled', 'disabled'); // Disable emoji input as well
            } else {
                chatInput.removeAttribute('disabled');
                chatInput.setAttribute('placeholder', 'Type an encrypted message...');
                chatInput.classList.remove('disabled-chat-input');
                sendBtn.removeAttribute('disabled');
                attachFileBtn.removeAttribute('disabled');
                emojiInputBtn.removeAttribute('disabled'); // Enable emoji input
            }
        }
        
    </script>
</body>
</html>