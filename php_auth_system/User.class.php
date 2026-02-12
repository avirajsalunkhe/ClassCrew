<?php
// User.class.php - All Database and API Helper Methods
require_once 'db_config.php';

// Include the Google API Client classes (necessary for fetchAndSetDriveQuota / getRealTimeQuotaForUser)
use Google\Client;
use Google\Service\Drive;
use Google\Service\Oauth2; // Added for safety

class User {
    private $pdo;
    private $session_timeout = 300; 
    private $db;
    /**
     * Get all users to display in the batch enrollment list
     * This fixes the 'Call to undefined method' error
     */
    public function getAllUsers() {
        if (!$this->db) {
            // Return empty array instead of crashing if DB is null
            return [];
        }
        $stmt = $this->db->prepare("SELECT id, first_name, last_name, email FROM users ORDER BY first_name ASC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function __construct() {
        global $pdo; 
        global $db; // Assuming $db is initialized in db_config.php
        $this->db = $db;
        if (isset($pdo) && $pdo !== null) {
            $this->pdo = $pdo;
        } else {
            // CRITICAL FIX: The constructor handles PDO instantiation safely
            if (!defined('DB_SERVER') || !defined('DB_NAME') || !defined('DB_USERNAME') || !defined('DB_PASSWORD')) {
                error_log("FATAL: Database configuration constants are missing. Check db_config.php.");
                throw new \PDOException("Database configuration error."); // Throw exception for graceful error handling
            }
            $dsn = 'mysql:host=' . DB_SERVER . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            try {
                $this->pdo = new PDO($dsn, DB_USERNAME, DB_PASSWORD, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, // Ensure PDO throws exceptions for better debugging
                ]);
            } catch (PDOException $e) {
                error_log("Database connection failed in User constructor: " . $e->getMessage());
                throw $e; // Re-throw the exception
            }
        }
    }

    // --- Core Database Utility Functions ---
    public function getPdo() { return $this->pdo; }

    public function getUserById($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id = ?"); 
        $stmt->bindParam(1, $id, PDO::PARAM_INT); 
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function getUserByEmail($email) {
        $stmt = $this->pdo->prepare("SELECT id, email, first_name, last_name, profile_picture_url, password_hash, google_id, is_admin, current_session_id, last_activity_timestamp FROM users WHERE email = ?");
        $stmt->execute([$email]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getUserWithTokens($id) {
        $stmt = $this->pdo->prepare("SELECT id, email, google_refresh_token, is_admin FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getAllFaculty() {
        if (!$this->db) return [];
        $stmt = $this->db->prepare("SELECT id, first_name, last_name FROM users WHERE is_teacher = 1 OR is_admin = 1 ORDER BY first_name ASC");
        $stmt->execute();
        return $stmt->fetchAll();
    }
    /**
     * Retrieves all user records required for the admin dashboard monitoring table.
     * Includes refresh token and activity data needed for online status and quota checks.
     * @return array Array of user records.
     */
    public function getAllUsersForAdmin() {
        $sql = "
            SELECT 
                u.id, 
                u.email, 
                u.google_refresh_token, 
                u.is_admin, 
                MAX(CASE WHEN al.activity_type = 'LOGIN' OR al.activity_type = 'PASSWORD_UPDATE' THEN al.timestamp END) AS last_activity,
                (SELECT activity_type FROM activity_log WHERE user_id = u.id ORDER BY timestamp DESC LIMIT 1) AS last_activity_type
            FROM 
                users u
            LEFT JOIN 
                activity_log al ON u.id = al.user_id
            GROUP BY 
                u.id
            ORDER BY 
                u.id ASC
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function upsertGoogleUser($google_data, $refreshToken = null) {
        // NOTE: Ensuring all required session fields are fetched here for safety.
        $stmt = $this->pdo->prepare("SELECT id, current_session_id, last_activity_timestamp, google_refresh_token, password_hash, is_admin FROM users WHERE google_id = :google_id OR email = :email");
        $stmt->execute(['google_id' => $google_data['id'], 'email' => $google_data['email']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $fields = [
            'google_id' => $google_data['id'],
            'first_name' => $google_data['given_name'],
            'last_name' => $google_data['family_name'],
            'profile_picture_url' => $google_data['picture'],
            'email' => $google_data['email'],
            'google_email' => $google_data['email']
        ];

        if ($user) {
            $update_parts = [
                'google_id = :google_id', 
                'first_name = :first_name', 
                'last_name = :last_name', 
                'profile_picture_url = :profile_picture_url',
                'google_email = :google_email'
            ];
            
            if ($refreshToken && empty($user['google_refresh_token'])) {
                $update_parts[] = 'google_refresh_token = :refresh_token';
                $fields['refresh_token'] = $refreshToken;
            }

            $sql = "UPDATE users SET " . implode(', ', $update_parts) . " WHERE id = :id";
            $fields['id'] = $user['id'];
            
            $update_stmt = $this->pdo->prepare($sql);
            $update_stmt->execute($fields);
            
            return $user['id']; 

        } else {
            $sql = "INSERT INTO users (email, google_id, first_name, last_name, profile_picture_url, google_refresh_token, google_email, password_hash) 
                    VALUES (:email, :google_id, :first_name, :last_name, :profile_picture_url, :refresh_token, :google_email, :password_hash)";
            
            $fields['refresh_token'] = $refreshToken ?? null; 
            $fields['password_hash'] = NULL;

            $insert_stmt = $this->pdo->prepare($sql);
            $insert_stmt->execute($fields);
            
            return $this->pdo->lastInsertId();
        }
    }
    
    public function setLocalPassword($userId, $password) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $this->pdo->prepare("
            UPDATE users 
            SET password_hash = ? 
            WHERE id = ?
        ");
        return $stmt->execute([$hash, $userId]);
    }

    public function trackActivity($user_id, $type, $description) {
        $ip = $_SERVER['REMOTE_ADDR'];
        $stmt = $this->pdo->prepare("INSERT INTO activity_log (user_id, activity_type, description, ip_address) VALUES (?, ?, ?, ?)");
        return $stmt->execute([$user_id, $type, $description, $ip]); 
    }

    public function getLatestActivities($user_id, $limit = 10) {
        $stmt = $this->pdo->prepare("SELECT * FROM activity_log WHERE user_id = ? ORDER BY timestamp DESC LIMIT ?");
        $stmt->bindParam(1, $user_id, PDO::PARAM_INT);
        $stmt->bindParam(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // --- CHAT & MESSAGING METHODS ---
    
    /**
     * Saves an encrypted message or an image File ID.
     * @param int $sender_id
     * @param int $recipient_id 0 for global
     * @param string $message The encrypted text or encrypted placeholder/caption.
     * @param string|null $dfsFileId The Google Drive File ID.
     */
    public function saveMessage($sender_id, $recipient_id, $message, ?string $dfsFileId = null) {
        
        $recipient_db_id = $recipient_id === 0 ? NULL : $recipient_id;
        $is_global_flag = $recipient_id === 0 ? 1 : 0;
        
        $message_text_content = $message; 
        
        // --- CRITICAL FIX START: Rebuild the full URL if only the File ID is passed ---
        $dfs_file_id_content = NULL;

        if (!empty($dfsFileId)) {
            // Check if it looks like a bare File ID (typically 28-34 chars, no slashes/protocol)
            // If it's a bare ID, rebuild the full public URL for storage using the "view" export type for embedding
            if (preg_match('/^[a-zA-Z0-9_-]{28,34}$/', $dfsFileId)) {
                $dfs_file_id_content = "https://drive.google.com/uc?export=view&id={$dfsFileId}";
            } else {
                // If it already contains a protocol (it's a full URL), store it as is (for backwards compatibility)
                $dfs_file_id_content = $dfsFileId;
            }
        }
        // --- CRITICAL FIX END ---
        
        $sql = "INSERT INTO chat_messages (sender_id, recipient_id, message_text, is_read, dfs_file_id, is_global, timestamp) 
                VALUES (?, ?, ?, 0, ?, ?, NOW())";
        
        $stmt = $this->pdo->prepare($sql);
        
        return $stmt->execute([
            $sender_id, 
            $recipient_db_id, 
            $message_text_content, 
            $dfs_file_id_content, // Now guaranteed to be a full URL or NULL
            $is_global_flag
        ]);
    }

    /**
     * Deletes a message and returns the associated Google Drive URL if present.
     * @param int $messageId
     * @param int $senderId
     * @return string|false Returns the DFS URL if deleted successfully, or false on failure.
     */
    public function unsendMessage($messageId, $senderId) {
        try {
            $this->pdo->beginTransaction();

            // 1. Fetch dfs_file_id before deletion
            $selectStmt = $this->pdo->prepare("SELECT dfs_file_id FROM chat_messages WHERE message_id = ? AND sender_id = ?");
            $selectStmt->execute([$messageId, $senderId]);
            $result = $selectStmt->fetch(PDO::FETCH_ASSOC);

            if (!$result) {
                $this->pdo->rollBack();
                return false; // Message not found or wrong sender
            }

            $dfs_url = $result['dfs_file_id'];

            // 2. Delete the message
            $deleteStmt = $this->pdo->prepare("DELETE FROM chat_messages WHERE message_id = ? AND sender_id = ?");
            $deleteStmt->execute([$messageId, $senderId]);
            
            if ($deleteStmt->rowCount() > 0) {
                $this->pdo->commit();
                // Return the URL for the caller to handle Drive deletion
                return $dfs_url; 
            } else {
                $this->pdo->rollBack();
                return false;
            }
            
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            error_log("Database unsend failed: " . $e->getMessage());
            return false;
        }
    }

    public function toggleMessageReaction($messageId, $userId, $emojiCode) {
        try {
            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare("SELECT reaction_id, emoji_code FROM chat_reactions WHERE message_id = ? AND user_id = ?");
            $stmt->execute([$messageId, $userId]);
            $existingReaction = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existingReaction) {
                if ($existingReaction['emoji_code'] === $emojiCode) {
                    $deleteStmt = $this->pdo->prepare("DELETE FROM chat_reactions WHERE reaction_id = ?");
                    $deleteStmt->execute([$existingReaction['reaction_id']]);
                } else {
                    $updateStmt = $this->pdo->prepare("UPDATE chat_reactions SET emoji_code = ? WHERE reaction_id = ?");
                    $updateStmt->execute([$emojiCode, $existingReaction['reaction_id']]);
                }
            } else {
                $insertStmt = $this->pdo->prepare("INSERT INTO chat_reactions (message_id, user_id, emoji_code) VALUES (?, ?, ?)");
                $insertStmt->execute([$messageId, $userId, $emojiCode]);
            }
            
            $this->pdo->commit();
            return true;

        } catch (PDOException $e) {
            $this->pdo->rollBack();
            error_log("Reaction toggle failed: " . $e->getMessage());
            return false;
        }
    }
    
    public function getAllReactionsForConversation($current_user_id, $recipient_id) {
        if ($recipient_id == 0) {
            $sql = "SELECT cr.message_id, cr.user_id, cr.emoji_code 
                    FROM chat_reactions cr 
                    JOIN chat_messages m ON cr.message_id = m.message_id 
                    WHERE m.is_global = 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
        } else {
            $sql = "SELECT cr.message_id, cr.user_id, cr.emoji_code 
                    FROM chat_reactions cr
                    JOIN chat_messages m ON cr.message_id = m.message_id
                    WHERE (m.sender_id = :sender AND m.recipient_id = :recipient) 
                    OR (m.sender_id = :recipient AND m.recipient_id = :sender)";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':sender' => $current_user_id, ':recipient' => $recipient_id]);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getConversationHistory($current_user_id, $recipient_id) {
        
        $messages = [];
        
        if ($recipient_id == 0) {
            $sql = "SELECT m.message_id AS id, m.*, u.first_name, u.email, u.is_admin, m.dfs_file_id 
                    FROM chat_messages m 
                    JOIN users u ON m.sender_id = u.id 
                    WHERE m.is_global = 1
                    ORDER BY m.timestamp ASC LIMIT 100";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
        } else {
            $sql = "SELECT m.message_id AS id, m.*, u.first_name, u.email, u.is_admin, m.dfs_file_id 
                    FROM chat_messages m 
                    JOIN users u ON m.sender_id = u.id 
                    WHERE (m.sender_id = :sender AND m.recipient_id = :recipient) 
                    OR (m.sender_id = :recipient AND m.recipient_id = :sender)
                    ORDER BY m.timestamp ASC LIMIT 100";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':sender' => $current_user_id, ':recipient' => $recipient_id]);
        }
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // --- CRITICAL FIX: Ensure correct URL format is returned for display ---
        foreach ($messages as &$msg) {
            $link = $msg['dfs_file_id'];
            
            // Check if the stored link is a bare file ID (doesn't start with http/https)
            if (!empty($link) && !preg_match('/^https?:\/\//', $link)) {
                // If it's a bare ID, rebuild the full public URL for the client (shouldn't happen with new saveMessage)
                $msg['dfs_file_id'] = "https://drive.google.com/uc?export=view&id={$link}";
            }
        }
        unset($msg);
        // --- END CRITICAL FIX ---


        $rawReactions = $this->getAllReactionsForConversation($current_user_id, $recipient_id);
        
        $reactionsMap = [];
        foreach ($rawReactions as $reaction) {
            $msgId = $reaction['message_id'];
            $emoji = $reaction['emoji_code'];
            $userId = $reaction['user_id'];

            if (!isset($reactionsMap[$msgId])) {
                $reactionsMap[$msgId] = ['summary' => [], 'user_reaction' => null];
            }

            if (!isset($reactionsMap[$msgId]['summary'][$emoji])) {
                $reactionsMap[$msgId]['summary'][$emoji] = 0;
            }
            $reactionsMap[$msgId]['summary'][$emoji]++;

            if ($userId == $current_user_id) {
                $reactionsMap[$msgId]['user_reaction'] = $emoji;
            }
        }
        
        foreach ($messages as &$msg) {
            $msgId = $msg['id'];
            if (isset($reactionsMap[$msgId])) {
                $summary = $reactionsMap[$msgId]['summary'];
                $summaryString = implode(';', array_map(
                    fn($emoji, $count) => "{$emoji}:{$count}",
                    array_keys($summary),
                    array_values($summary)
                ));

                $msg['reactions_summary'] = $summaryString;
                $msg['user_reaction'] = $reactionsMap[$msgId]['user_reaction'];
            } else {
                $msg['reactions_summary'] = null;
                $msg['user_reaction'] = null;
            }
        }
        unset($msg); 

        return $messages;
    }
    
    public function getMessageReactionUsers($messageId) {
        $sql = "SELECT cr.emoji_code, u.email 
                FROM chat_reactions cr
                JOIN users u ON cr.user_id = u.id
                WHERE cr.message_id = ?
                ORDER BY cr.emoji_code, u.email";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$messageId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getUnreadCount($current_user_id, $partner_id) {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(message_id) FROM chat_messages 
            WHERE sender_id = ? AND recipient_id = ? AND is_read = 0
        ");
        $stmt->execute([$partner_id, $current_user_id]);
        return (int) $stmt->fetchColumn();
    }
    
    public function resetUnreadCount($current_user_id, $partner_id) {
        $stmt = $this->pdo->prepare("
            UPDATE chat_messages SET is_read = 1 
            WHERE sender_id = ? AND recipient_id = ? AND is_read = 0
        ");
        $stmt->execute([$partner_id, $current_user_id]); 
        return true;
    }

    public function getChatUserList($currentUserId) {
        
        $sql_latest_message_id = "
            SELECT 
                MAX(cm.message_id) AS last_message_id,
                CASE 
                    WHEN cm.sender_id = :user_id THEN cm.recipient_id 
                    ELSE cm.sender_id 
                END AS partner_id
            FROM chat_messages cm
            WHERE cm.is_global = 0 -- Exclude Global Chat
            AND (cm.sender_id = :user_id_1 OR cm.recipient_id = :user_id_2)
            GROUP BY partner_id
        ";
        
        $sql = "
            SELECT 
                u.id AS user_id, 
                u.first_name,
                u.last_name, 
                u.email, 
                u.profile_picture_url, 
                u.last_activity_timestamp, 
                
                cm_latest.message_text AS last_message_preview,
                UNIX_TIMESTAMP(cm_latest.timestamp) AS last_message_time,
                
                (
                    -- Unread count calculation
                    SELECT COUNT(cm_unread.message_id) 
                    FROM chat_messages cm_unread 
                    WHERE cm_unread.sender_id = u.id 
                    AND cm_unread.recipient_id = :current_user_id
                    AND cm_unread.is_read = 0
                ) AS unread_count

            FROM users u
            JOIN ({$sql_latest_message_id}) LMI ON u.id = LMI.partner_id
            JOIN chat_messages cm_latest ON cm_latest.message_id = LMI.last_message_id
            
            -- ORDERING: Users I have recently chatted with appear at the top of the list, 
            -- ordered by most recent message time.
            ORDER BY cm_latest.timestamp DESC 
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $currentUserId,
            ':user_id_1' => $currentUserId,
            ':user_id_2' => $currentUserId,
            ':current_user_id' => $currentUserId // For the unread count subquery
        ]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function createDistributionJob($userId, $fileName, $localPath) {
        $pdo = $this->getPdo();
        $stmt = $pdo->prepare("
            INSERT INTO distribution_queue 
            (user_id, master_file_name, local_file_path)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$userId, $fileName, $localPath]);
        return $this->pdo->lastInsertId();
    }

    public function getPendingAndProcessingJobs() {
        $pdo = $this->getPdo();
        $stmt = $pdo->query("
            SELECT job_id, master_file_name, status, created_at, started_at, finished_at, error_message
            FROM distribution_queue
            WHERE status IN ('PENDING', 'PROCESSING', 'FAILED')
            ORDER BY created_at ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAllDistributionJobs() {
        $pdo = $this->getPdo();
        $stmt = $pdo->query("
            SELECT job_id, master_file_name, status, created_at, started_at, finished_at, error_message
            FROM distribution_queue
            ORDER BY created_at DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Drive API Helpers Implementation
    
    /**
     * Attempts to refresh the client's access token and fetch the Drive storage quota.
     */
    public function fetchAndSetDriveQuota($client): bool {
        try {
            if ($client->isAccessTokenExpired() && $client->getRefreshToken()) {
                $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            }

            if (!$client->getAccessToken()) {
                return false;
            }
            
            $service = new Google_Service_Drive($client);
            $about = $service->about->get(['fields' => 'storageQuota']);
            $storage_quota = $about->getStorageQuota();
            
            $used = (int)$storage_quota->getUsage();
            $limit = (int)$storage_quota->getLimit(); 
            $percentage = ($limit > 0) ? round(($used / $limit) * 100, 2) : 0;

            $_SESSION['drive_quota'] = [
                'usage' => $used,
                'limit' => $limit,
                'percentage' => $percentage
            ];

            return true;

        } catch (Exception $e) {
            error_log("Failed to fetch/set Drive quota for current user: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Fetches real-time Drive storage quota for any user based on their refresh token.
     */
    public function getRealTimeQuotaForUser($refreshToken, $clientTemplate) {
        if (empty($refreshToken)) {
            return false;
        }
        
        try {
            $client = clone $clientTemplate;
            $client->setAccessType('offline');
            $client->refreshToken = $refreshToken; 
            
            $client->fetchAccessTokenWithRefreshToken($refreshToken);
            
            if (!$client->getAccessToken()) {
                return false;
            }

            $service = new Google_Service_Drive($client);
            $about = $service->about->get(['fields' => 'storageQuota']);
            $storage_quota = $about->getStorageQuota();
            
            $used = (int)$storage_quota->getUsage();
            $limit = (int)$storage_quota->getLimit();
            $percentage = ($limit > 0) ? round(($used / $limit) * 100, 2) : 0;
            
            return [
                'used' => $used,
                'limit' => $limit,
                'percentage' => $percentage
            ];

        } catch (Exception $e) {
            error_log("Failed to fetch real-time Drive quota for user (Token: {$refreshToken}): " . $e->getMessage());
            return false;
        }
    }

    public function getUnreadMessagesForUser($userId) {
        $sql = "
            SELECT COUNT(message_id) FROM chat_messages 
            WHERE 
                (recipient_id = :user_id OR is_global = 1)
                AND is_read = 0
                AND sender_id != :user_id_sender
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':user_id_sender' => $userId
        ]);
        return (int) $stmt->fetchColumn();
    }

    // --- FILE DISTRIBUTION REGISTRY METHODS ---

/**
 * Retrieves the unique master file entries from the chunk_registry 
 * for files that have been successfully distributed.
 * This aggregates file names and UUIDs from the chunks.
 * * FIX: Decouple from distribution_queue status for retrieval display.
 * The file remains "retrievable" as long as chunk metadata exists.
 * @return array Array of distributed master file records.
 */
public function getDistributedMasterFiles() {
    $pdo = $this->getPdo();
    $stmt = $pdo->query("
        SELECT DISTINCT
            cr.master_file_unique_id, 
            cr.master_file_name
        FROM 
            chunk_registry cr
        ORDER BY 
            cr.chunk_id DESC 
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Retrieves chunk metadata by the master file's unique ID for download/delete actions.
 *
 * NOTE: This logic uses the combined 'chunk_registry' table.
 */
public function getChunksByMasterId($fileUUID) {
    $pdo = $this->getPdo();
    // Joins chunk_registry to users to get the refresh token for accessing the file.
    $stmt = $pdo->prepare("
        SELECT 
            cr.chunk_id, 
            cr.drive_file_id, 
            cr.chunk_sequence_number, 
            cr.encryption_key,
            cr.master_file_name,
            u.google_refresh_token
        FROM 
            chunk_registry cr
        JOIN 
            users u ON cr.user_id = u.id 
        WHERE 
            cr.master_file_unique_id = ?
        ORDER BY 
            cr.chunk_sequence_number ASC
    ");
    $stmt->execute([$fileUUID]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Deletes all chunks associated with a master file UUID and updates the queue status.
 */
public function deleteMasterFileMetadata($fileUUID) {
    $pdo = $this->getPdo();
    try {
        $pdo->beginTransaction();
        
        // 1. Get the master_file_name associated with the UUID
        $selectStmt = $pdo->prepare("SELECT DISTINCT master_file_name FROM chunk_registry WHERE master_file_unique_id = ?");
        $selectStmt->execute([$fileUUID]);
        $result = $selectStmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            $pdo->rollBack();
            return false;
        }
        $masterFileName = $result['master_file_name'];
        
        // Get the most recent COMPLETE job ID for this file name 
        // We check for various finalized statuses to ensure we can update the history if it still exists.
        $jobStmt = $pdo->prepare("SELECT job_id FROM distribution_queue WHERE master_file_name = ? AND status IN ('COMPLETE', 'FAILED', 'FILE_DELETED') ORDER BY created_at DESC LIMIT 1");
        $jobStmt->execute([$masterFileName]);
        $job = $jobStmt->fetch(PDO::FETCH_ASSOC);
        $jobId = $job ? $job['job_id'] : null;


        // 2. Delete the chunks from chunk_registry
        $deleteChunksStmt = $pdo->prepare("DELETE FROM chunk_registry WHERE master_file_unique_id = ?");
        $deleteChunksStmt->execute([$fileUUID]);
        
        // 3. Update the job status IF the job history still exists.
        if ($jobId) {
            // Note: Update status to indicate the file is now gone.
            $updateJobStmt = $pdo->prepare("UPDATE distribution_queue SET status = 'FILE_DELETED', finished_at = NOW(), error_message = 'File Deleted by Admin.' WHERE job_id = ? AND status != 'FILE_DELETED'");
            $updateJobStmt->execute([$jobId]); 
        }

        $pdo->commit();
        return true;
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Failed to delete master file metadata ({$fileUUID}): " . $e->getMessage());
        return false;
    }
}
/**
 * Retrieves a list of users who have a Google Refresh Token, 
 * making their Drive authorized for file distribution.
 * @return array Array of user records with refresh tokens.
 */
public function getAuthorizedDriveUsers() {
    $pdo = $this->getPdo();
    $stmt = $pdo->prepare("
        SELECT 
            id, 
            email, 
            google_refresh_token 
        FROM 
            users 
        WHERE 
            google_refresh_token IS NOT NULL AND google_refresh_token != ''
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
/**
 * Registers the metadata for a single successfully distributed chunk 
 * into the chunk_registry table, accepting all data as a single array.
 * * Expected keys in $chunkData:
 * - masterFileUUID
 * - masterFileName
 * - chunkSequenceNumber
 * - userId
 * - driveFileId
 * - chunkSize
 * - encryptionKey
 * @param array $chunkData An associative array containing all chunk metadata.
 * @return bool True on success, false on failure.
 */
public function registerChunk(array $chunkData) {
    // Safely extract data, assuming the worker process passes a structured array.
    $masterFileUUID = $chunkData['masterFileUUID'] ?? null;
    $masterFileName = $chunkData['masterFileName'] ?? null;
    $chunkSequenceNumber = $chunkData['chunkSequenceNumber'] ?? null;
    $userId = $chunkData['userId'] ?? null;
    $driveFileId = $chunkData['driveFileId'] ?? null;
    $chunkSize = $chunkData['chunkSize'] ?? null;
    $encryptionKey = $chunkData['encryptionKey'] ?? null;

    // Basic validation
    if (empty($masterFileUUID) || empty($driveFileId) || empty($encryptionKey) || $userId === null) {
        error_log("registerChunk failed due to missing required data.");
        return false;
    }
    
    $pdo = $this->getPdo();
    $sql = "
        INSERT INTO chunk_registry 
        (master_file_name, master_file_unique_id, chunk_sequence_number, user_id, drive_file_id, chunk_size, encryption_key)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ";
    
    $stmt = $pdo->prepare($sql);
    
    return $stmt->execute([
        $masterFileName,
        $masterFileUUID,
        $chunkSequenceNumber,
        $userId,
        $driveFileId,
        $chunkSize,
        $encryptionKey
    ]);
}
public function getNotesByBatch($batchId, $onlyVisible = false) {
    $pdo = $this->getPdo();
    $sql = "
        SELECT 
            n.*, 
            cr.master_file_name,
            u.first_name as uploader_name,
            b.batch_name
        FROM notes_registry n
        JOIN (SELECT DISTINCT master_file_unique_id, master_file_name FROM chunk_registry) cr 
            ON n.master_file_unique_id = cr.master_file_unique_id
        JOIN users u ON n.uploaded_by = u.id
        JOIN batches b ON n.batch_id = b.id
        WHERE n.batch_id = ?
    ";
    
    if ($onlyVisible) {
        $sql .= " AND n.is_visible = 1";
    }
    
    $sql .= " ORDER BY n.upload_date DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$batchId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

public function toggleNoteVisibility($noteId, $status) {
    $pdo = $this->getPdo();
    $stmt = $pdo->prepare("UPDATE notes_registry SET is_visible = ? WHERE note_id = ?");
    return $stmt->execute([$status, $noteId]);
}

public function getStudentBatches($userId) {
    $pdo = $this->getPdo();
    $stmt = $pdo->prepare("
        SELECT b.id, b.batch_name 
        FROM batches b
        JOIN batch_students bs ON b.id = bs.batch_id
        WHERE bs.user_id = ?
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

public function getAllBatches() {
    $pdo = $this->getPdo();
    $stmt = $pdo->query("SELECT id, batch_name FROM batches ORDER BY batch_name ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
}