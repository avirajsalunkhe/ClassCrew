<?php
// send_message.php - Handles saving and retrieving chat messages (FINAL CHAT API)

session_start();
require_once 'db_config.php';
require_once 'User.class.php';

// Check user authentication
if (!isset($_SESSION['logged_in']) || !isset($_SESSION['user_id'])) { 
    http_response_code(401);
    die(json_encode(['status' => 'error', 'message' => 'Unauthorized. Session required.'])); 
}

$sender_id = $_SESSION['user_id'];
$user_obj = new User();
$action = $_GET['action'] ?? null; 
$method = $_SERVER['REQUEST_METHOD'];

header('Content-Type: application/json');

try {
    // MODE 1: SEARCH USER BY EMAIL
    if ($action === 'search_user_by_email' && $method === 'POST') {
        $email = trim($_POST['email'] ?? '');
        if (empty($email)) { http_response_code(400); die(json_encode(['status' => 'error', 'message' => 'Email required for search.'])); }

        $target_user = $user_obj->getUserByEmail($email); 
        
        if ($target_user && $target_user['id'] != $sender_id) {
            $email_alias = $target_user['first_name'] 
                         ? trim($target_user['first_name'] . ' ' . $target_user['last_name'])
                         : $target_user['email'];

            http_response_code(200);
            echo json_encode([
                'status' => 'success', 
                'user_id' => $target_user['id'], 
                'email_alias' => $email_alias
            ]);
            exit;
        }

        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'User not found or is the current user.']);
        exit;
    }
    
    // MODE 2: FETCH CHAT HISTORY
    if ($action === 'get_history') {
        $recipient_id = $_POST['recipient_id'] ?? 0;
        $messages = $user_obj->getConversationHistory($sender_id, $recipient_id);

        http_response_code(200);
        echo json_encode(['status' => 'success', 'messages' => $messages]);
        exit;
    }
    
    // MODE 3: MARK MESSAGE AS READ
    if ($action === 'set_read') {
        $conversation_partner_id = $_POST['partner_id'] ?? null;
        if ($conversation_partner_id && $conversation_partner_id != 0) { 
            // Assuming this helper exists in User.class.php and works
            $user_obj->resetUnreadCount($sender_id, $conversation_partner_id); 
            http_response_code(200);
            echo json_encode(['status' => 'success', 'message' => 'Messages marked read.']);
            exit;
        }

        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Valid Partner ID required to set read status.']);
        exit;
    }

    // MODE 4: FETCH USER ACTIVITY/AVAILABILITY
    if ($action === 'check_activity') {
        $user_statuses = $user_obj->getChatUserList($sender_id); // Use the correct method
        http_response_code(200);
        echo json_encode(['status' => 'success', 'users' => $user_statuses]);
        exit;
    }
    
    // MODE 5: SEND NEW MESSAGE
    if ($action === 'send_message' && $method === 'POST') {
        
        $message = $_POST['message'] ?? '';
        $recipient_id = (int)($_POST['recipient_id'] ?? 0);
        // CRITICAL: Retrieve the Drive File ID from the POST payload sent by JavaScript
        $dfs_file_id = $_POST['dfs_file_id'] ?? null; 
        
        // FIX: Allow sending if either the message (text/caption) or the file ID is present
        if (empty($message) && empty($dfs_file_id)) { 
            http_response_code(400); 
            die(json_encode(['status' => 'error', 'message' => 'Message content or file is empty.'])); 
        }

        $is_global = ($recipient_id == 0);
        $sender_details = $user_obj->getUserById($sender_id);

        if ($is_global && empty($sender_details['is_admin'])) {
            http_response_code(403); 
            die(json_encode(['status' => 'error', 'message' => 'Only Admins can send global broadcasts.'])); 
        }
        
        // Correct call signature: saveMessage($sender_id, $recipient_id, $message, $dfsFileId)
        // $message contains the encrypted caption/placeholder.
        // $dfs_file_id contains the full public URL or null.
        $success = $user_obj->saveMessage($sender_id, $recipient_id, $message, $dfs_file_id);
        
        if ($success) {
            http_response_code(200);
            echo json_encode(['status' => 'success']);
        } else {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Database error saving message.']);
        }
        exit;
    }

} catch (Exception $e) {
    error_log("Chat API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal server error processing chat.']);
    exit;
}

http_response_code(405); 
echo json_encode(['status' => 'error', 'message' => 'Method not allowed or action missing.']);
?>