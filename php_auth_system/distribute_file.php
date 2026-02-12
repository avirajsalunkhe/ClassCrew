<?php
// distribute_file.php - DFS Management Console (FINAL ASYNCHRONOUS VERSION)

// Setting execution time to unlimited for synchronous GET actions (Download/Delete)
set_time_limit(0); 
ini_set('max_execution_time', 0); 

require_once 'db_config.php';
require_once 'vendor/autoload.php';
require_once 'User.class.php';

// --- CHUNKING & ENCRYPTION CONFIGURATION ---
const CHUNK_SIZE = 3145728; // 3 MB chunk size (3 * 1024 * 1024 bytes)

// Placeholder Decryption Function (Used for GET actions)
function decrypt_data($data, $key) { 
    return openssl_decrypt(base64_decode($data), 'AES-256-CBC', $key, 0, '1234567890123456'); 
}
// --- Helper Function for Time Display ---
function formatTimeElapsed($start_time, $end_time = null) {
    if (!$start_time) return "N/A";
    
    $start_ts = strtotime($start_time);
    $end_ts = $end_time ? strtotime($end_time) : time();
    $diff = $end_ts - $start_ts;
    
    if ($diff < 0) $diff = 0; // Handle cases where end time is before start time (shouldn't happen)

    $hours = floor($diff / 3600);
    $minutes = floor(($diff % 3600) / 60);
    $seconds = $diff % 60;
    
    return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
}
// ----------------------------------------------------

// --- CRITICAL FIX: READ THEME COOKIE AND DEFINE $theme VARIABLE ---
$theme = $_COOKIE['theme'] ?? 'day'; 
// ----------------------------------------------------

// --- Setup and Auth ---
$user_obj = new User();
$status_messages = [];
$user_id = $_SESSION['user_id'] ?? null;
$client_template = new Google_Client();
$client_template->setClientId(GOOGLE_CLIENT_ID);
$client_template->setClientSecret(GOOGLE_CLIENT_SECRET);

// 1. ADMIN ACCESS CHECK
if (!isset($_SESSION['logged_in']) || !$user_id) { header('Location: index.php?error=access_denied'); exit; }
$admin_user = $user_obj->getUserById($user_id);
if (empty($admin_user['is_admin'])) { header('Location: profile.php?error=admin_access_denied'); exit; }


// =================================================================
// PRIMARY GET ACTIONS (DOWNLOAD / DELETE)
// =================================================================

$action = $_GET['action'] ?? null;
$fileUUID = $_GET['uuid'] ?? null;
$confirm_delete = $_GET['confirm_delete'] ?? null; // NEW: Confirmation flag

if ($fileUUID && ($action === 'download_dfs' || $action === 'delete_dfs')) {
    
    $chunks_metadata = $user_obj->getChunksByMasterId($fileUUID);
    
    if (empty($chunks_metadata)) {
        header("Location: distribute_file.php?status=" . urlencode("Error: File metadata not found."));
        exit;
    }
    
    $masterFileName = $chunks_metadata[0]['master_file_name'];
    $assembledContent = '';
    $success = true;
    
    // --- DELETE LOGIC (Sequential Deletion) ---
    if ($action === 'delete_dfs' && $confirm_delete === 'true') {
        $successful_deletes = 0;
        
        foreach ($chunks_metadata as $chunk) {
            try {
                $client_user = clone $client_template;
                $client_user->fetchAccessTokenWithRefreshToken($chunk['google_refresh_token']);
                $service_user = new Google_Service_Drive($client_user);
                
                $service_user->files->delete($chunk['drive_file_id'], [
                    'spaces' => 'appDataFolder' // Ensures the delete command looks in the hidden folder
                ]);
                $successful_deletes++;
            } catch (Exception $e) {
                error_log("Failed to delete chunk {$chunk['chunk_id']}: " . $e->getMessage());
            }
        }
        $user_obj->deleteMasterFileMetadata($fileUUID);
        header("Location: distribute_file.php?status=" . urlencode("SUCCESS: Deleted {$successful_deletes} chunks and metadata for {$masterFileName}."));
        exit;
    } 
    
    // --- DOWNLOAD/ASSEMBLE LOGIC (Concurrent Retrieval via cURL multi) ---
    if ($action === 'download_dfs') {
        $chunk_groups = [];
        foreach ($chunks_metadata as $chunk) { $chunk_groups[$chunk['chunk_sequence_number']][] = $chunk; }
        
        $multi_handle = curl_multi_init();
        $curl_map = []; 
        $assembled_chunks = [];
        $required_sequences = array_keys($chunk_groups);
        
        foreach ($chunk_groups as $sequence_number => $copies) {
            $chunk_found = false;
            $chunk = $copies[0]; 
            
            try {
                $client_user = clone $client_template;
                $client_user->fetchAccessTokenWithRefreshToken($chunk['google_refresh_token']);
                $token = $client_user->getAccessToken();
                
                if (empty($token['access_token'])) { continue; }
                
                $download_url = "https://www.googleapis.com/drive/v3/files/{$chunk['drive_file_id']}?alt=media&spaces=appDataFolder";
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $download_url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array( 'Authorization: Bearer ' . $token['access_token'] ));
                
                curl_multi_add_handle($multi_handle, $ch);
                
                $curl_map[(int)$ch] = [ 'seq' => $sequence_number, 'key' => $chunk['encryption_key'] ];
                $chunk_found = true;
                
            } catch (Exception $e) {
                error_log("Setup failed for chunk {$sequence_number}: " . $e->getMessage());
            }

            if (!$chunk_found) { $success = false; }
        }

        $running = null;
        do { curl_multi_exec($multi_handle, $running); usleep(10000); } while ($running > 0);

        while ($info = curl_multi_info_read($multi_handle)) {
            $ch = $info['handle']; 
            $ch_id = (int)$ch;      
            
            if (!isset($curl_map[$ch_id])) continue; 
            
            $meta = $curl_map[$ch_id];
            
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
            $encryptedContent = curl_multi_getcontent($ch);
            
            if ($http_code === 200 && $encryptedContent !== false) {
                $decryptedContent = decrypt_data($encryptedContent, $meta['key']);
                $assembled_chunks[$meta['seq']] = $decryptedContent;
            } else {
                error_log("Chunk {$meta['seq']} download failed (HTTP {$http_code}).");
                $success = false;
            }
            
            curl_multi_remove_handle($multi_handle, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi_handle);

        if (count($required_sequences) !== count($assembled_chunks)) { $success = false; }
        else { ksort($assembled_chunks); $assembledContent = implode('', $assembled_chunks); }
        
        if ($success) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="DFS_ASSEMBLED_' . $masterFileName . '"');
            header('Content-Length: ' . strlen($assembledContent));
            echo $assembledContent;
            exit;
        } else {
            header("Location: distribute_file.php?status=" . urlencode("FAILURE: Assembly failed. Missing " . (count($required_sequences) - count($assembled_chunks)) . " chunks."));
            exit;
        }
    }
}


// =================================================================
// PRIMARY POST ACTION (ASYNCHRONOUS JOB CREATION - INSTANT RESPONSE)
// =================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['shareFile'])) {
    $uploaded_file = $_FILES['shareFile'];
    $admin_id = $_SESSION['user_id'];
    
    $temp_dir = 'temp_uploads/';
    if (!is_dir($temp_dir)) {
        if (!mkdir($temp_dir, 0777, true)) {
            $status_messages[] = "CRITICAL ERROR: Failed to create temporary upload directory.";
            goto end_of_post_logic; 
        }
    }
    
    if ($uploaded_file['error'] === UPLOAD_ERR_OK) {
        
        $unique_filename = time() . '_' . basename($uploaded_file['name']);
        $local_path = $temp_dir . $unique_filename;
        
        // 1. Move the uploaded file to the temporary location
        if (move_uploaded_file($uploaded_file['tmp_name'], $local_path)) {
            
            // 2. Create the job record in the database and capture the ID
            $new_job_id = $user_obj->createDistributionJob($admin_id, $uploaded_file['name'], $local_path);

            // 3. Set the successful message (INSTANT FEEDBACK)
            $message_status = "SUCCESS: Distribution job created for '{$uploaded_file['name']}'. Processing will begin in the background (Job ID: {$new_job_id}).";
            
            // 4. Implement PRG pattern: Redirect to GET method immediately to prevent resubmission
            $_SESSION['active_job_id'] = $new_job_id;
            header("Location: distribute_file.php?status=" . urlencode($message_status));
            exit; 

        } else {
            $status_messages[] = "UPLOAD FAILED: Could not save file to temporary server location.";
        }

    } else {
        $status_messages[] = "UPLOAD FAILED: Error code " . $uploaded_file['error'] . ". Check max_upload_filesize in php.ini.";
    }
}

end_of_post_logic:
// --- GET DISTRIBUTED FILES FOR DISPLAY ---
$distributed_files = $user_obj->getDistributedMasterFiles();
$all_jobs = $user_obj->getAllDistributionJobs(); 
$status_param = $_GET['status'] ?? null;
if ($status_param) $status_messages[] = htmlspecialchars(urldecode($status_param));

// Get the last submitted job ID for immediate polling display
$initial_job_id = $_SESSION['active_job_id'] ?? null;

// --- FIX: Check all completed jobs and clear the active session flag if the job is done.
if (isset($_SESSION['active_job_id'])) {
    foreach ($all_jobs as $job) {
        if ($job['job_id'] == $_SESSION['active_job_id']) {
            if ($job['status'] === 'COMPLETE' || $job['status'] === 'FAILED' || $job['status'] === 'FILE_DELETED') { // Added FILE_DELETED
                unset($_SESSION['active_job_id']); // Clear the polling flag once the job is finalized
                break;
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <title>DFS Management Console</title>
    <style>
        /* THEME BASE CSS VARIABLES (Inline Copy from theme_config.php) */
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

        /* BASE AND PHPMA STYLE RESET */
        body { 
            font-family: 'Consolas', monospace, 'Segoe UI', Tahoma, sans-serif; 
            padding: 25px; 
            background-color: var(--bg-primary); 
            color: var(--text-color);
            margin: 0;
            line-height: 1.4;
            font-size: 14px;
            transition: background-color 0.3s, color 0.3s;
        }
        .dashboard-container { 
            max-width: 1400px; 
            width: 95%; 
            margin: auto; 
            background: var(--bg-secondary); 
            border-radius: 12px; 
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.1); 
            padding: 30px; 
            border: 1px solid var(--border-color); 
        }
        
        /* HEADERS */
        h2 { color: var(--text-color); border-bottom: 2px solid var(--border-color); padding-bottom: 10px; margin-bottom: 20px; font-weight: 600; font-size: 1.8em; }
        h3 { color: var(--text-color); font-size: 1.4em; margin-top: 30px; padding-bottom: 5px; }
        
        /* BUTTONS & ACTIONS */
        .drive-btn { 
            background-color: #556080; 
            color: white; 
            padding: 8px 15px; 
            border-radius: 4px; 
            font-weight: bold; 
            display: inline-block; 
            transition: background-color 0.2s; 
            border: 1px solid #455070;
            text-decoration: none;
        }
        .drive-btn:hover { background-color: #455070; }
        .share-form-box { margin-top: 20px; padding: 25px; border: 1px dashed var(--border-color); border-radius: 4px; background-color: var(--header-bg); }
        .share-form-box button { width: 100%; padding: 12px 0; font-size: 1.1em; background-color: #2ecc71; border: none; }
        .share-form-box button:hover { background-color: #27ae60; }
        
        /* LOG CONSOLE FIX: Draggable, Floating Window */
        #log-console {
            position: fixed; /* Changed from absolute to fixed for viewport stability */
            bottom: 20px;
            right: 20px;
            width: 350px;
            max-height: 400px;
            background: #1a1a1a; 
            color: #2ecc71; 
            border: 2px solid #555;
            border-radius: 6px;
            overflow-y: auto;
            padding: 0; 
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4);
            font-size: 0.85em;
            display: <?php echo !empty($status_messages) ? 'block' : 'none'; ?>; 
            z-index: 1000;
            cursor: move; 
        }
        #log-console h4 {
            color: white;
            background: #333;
            padding: 8px 10px;
            margin: 0;
            border-bottom: 1px solid #555;
            cursor: move;
            font-size: 1em;
        }
        .cmd-log-content { padding: 10px; }
        .cmd-log-content ul { list-style: none; padding: 0; margin: 0; }
        .cmd-log-content .failure { color: #e74c3c; }
        .cmd-log-content .success { color: #2ecc71; }
        
        /* TABLE LAYOUT */
        .dual-table-wrapper {
            display: flex;
            gap: 25px; 
            margin-top: 20px;
        }
        .log-column {
            flex: 1;
        }
        .dfs-table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-top: 10px; 
            background: var(--bg-secondary);
            border-radius: 4px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--border-color);
        }
        
        /* PHPMA TABLE STYLING */
        .dfs-table th { 
            background-color: var(--header-bg); 
            color: var(--text-color); 
            font-weight: bold; 
            text-transform: uppercase; 
            font-size: 0.8em;
            padding: 8px 10px;
            border-bottom: 1px solid var(--border-color);
            border-right: 1px solid var(--border-color);
        }
        .dfs-table td { 
            padding: 8px 10px; 
            font-size: 0.9em;
            border: 1px solid var(--border-color); 
            white-space: nowrap; 
        }
        .dfs-table tr:nth-child(even) { background-color: var(--bg-secondary); }
        .dfs-table tr:hover { background-color: var(--active-bg); }

        /* PROGRESS BAR FIX: High-Tech Look */
        .queue-progress { width: 100%; height: 6px; background: var(--border-color); border-radius: 3px; overflow: hidden; margin-top: 4px; }
        .queue-progress-bar { height: 100%; background: linear-gradient(90deg, #3498db, #2ecc71); transition: width 0.5s; box-shadow: 0 0 5px #3498db; } 
        .status-complete { color: #28a745; font-weight: bold; }
        .status-failed { color: #dc3545; font-weight: bold; }
        .status-processing { color: #f39c12; font-weight: bold; }
        .status-file_deleted {font-weight: bold; } /* New style for deleted files */
        
        /* ACTION BUTTONS */
        /* Flex container for action links */
        .action-cell { display: flex; gap: 4px; }
        
        .action-link { 
            padding: 4px 6px; 
            font-size: 0.8em; 
            font-weight: 600; 
            display: inline-block; 
            margin-bottom: 2px;
            border-radius: 4px;
            text-decoration: none;
            transition: background-color 0.2s;
        }
        .download-action { background-color: #27ae60; color: white; } 
        .delete-action { background-color: #95a5a6; color: white; } 
        .cancel-action { background-color: #dc3545; color: white; } /* New class for Cancel */
        .retry-action { background-color: #3498db; color: white; } /* New class for Retry */
        .action-link:hover { opacity: 0.8; }

        /* Queue Specific Styles (Final Layout) */
        .queue-table th:nth-child(2) { width: 30%; } 
        .queue-table th:nth-child(4) { width: 10%; } 
        .queue-table th:nth-child(5) { width: 10%; } 
        .queue-table th:nth-child(6) { width: 12%; } 
        .queue-table th:nth-child(7) { width: 15%; } 
        .queue-table th:last-child { width: 18%; } 

        /* Error Message Cell Styling */
        .error-message-cell { font-size: 0.8em; color: #dc3545; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: block; }
        
        /* Modal for Delete Confirmation (Replaces confirm()) */
        #custom-confirm-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 2000;
        }
        .modal-content {
            background-color: var(--bg-secondary);
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            max-width: 350px;
            text-align: center;
        }
        .modal-buttons {
            margin-top: 15px;
            display: flex;
            justify-content: space-around;
        }
        .modal-buttons button {
            padding: 8px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
        }
        #confirm-yes { background-color: #e74c3c; color: white; }
        #confirm-no { background-color: var(--header-bg); color: var(--text-color); }

    </style>
</head>
<body>
    <div class="dashboard-container">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--border-color); margin-bottom: 20px;">
            <h2 style="border-bottom: none; margin-bottom: 0; color: var(--text-color);">DFS Management Console</h2>
            <a href="admin_dashboard.php" class="drive-btn" style="background-color: #556080;">&larr; Back to Admin Console</a>
             <a href="javascript:void(0)" onclick="resetThemeAndLogout()" style="background-color: #dc3545;" class="drive-btn" >Logout</a>
        </div>
        
        <!-- File Upload Form -->
        <div class="share-form-box">
            <h3>1. Upload and Queue New File</h3>
            <p>File will be split, encrypted, and distributed across **<?php echo count($user_obj->getAuthorizedDriveUsers()); ?>** authorized drives.</p>
            <form action="distribute_file.php" method="POST" enctype="multipart/form-data" style="margin-top: 15px;">
                <input type="file" name="shareFile" required style="display: block; margin-bottom: 15px; padding: 8px; border: 1px solid #ccc; border-radius: 4px; width: 100%;">
                <button type="submit" class="drive-btn" style="background-color: #2ecc71; width: 100%;">Queue Distribution Job</button>
            </form>
        </div>

        <!-- ========================================================= -->
        <!-- FLOATING CMD PROCESS LOG -->
        <!-- ========================================================= -->
        <?php if (!empty($status_messages)): ?>
            <div id="log-console">
                <h4>CMD Process Log</h4>
                <div class="cmd-log-content">
                    <ul>
                        <?php foreach ($status_messages as $msg): ?>
                            <li class="<?php echo (strpos($msg, 'SUCCESS') !== false || strpos($msg, 'COMPLETE') !== false) ? 'success' : 'failure'; ?>">
                                > <?php echo htmlspecialchars($msg); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Modal for Delete Confirmation (Replaces confirm()) -->
        <div id="custom-confirm-modal">
            <div class="modal-content">
                <p id="confirm-message">WARNING: Are you sure you want to delete ALL chunks and metadata for this file?</p>
                <div class="modal-buttons">
                    <button id="confirm-yes">Yes, Delete Permanently</button>
                    <button id="confirm-no">Cancel</button>
                </div>
            </div>
        </div>

        <!-- ========================================================= -->
        <!-- 2. DUAL TABLE LAYOUT (JOB HISTORY & FILE ACCESS) -->
        <!-- ========================================================= -->
        <h3 style="margin-top: 30px;">2. DFS Job History and Retrieval</h3>
        
        <div class="dual-table-wrapper">
            
            <!-- LEFT COLUMN: JOB QUEUE HISTORY -->
            <div class="log-column">
                <h4 style="color: var(--text-color); margin-top: 0; padding-bottom: 10px; border-bottom: 1px solid var(--border-color);">Active Queue & Job History</h4>
                
                <?php if (!empty($all_jobs)): ?>
                    <table class="dfs-table queue-table" id="job-queue-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>File Name</th>
                                <th>Status</th>
                                <th>Time Elapsed</th>
                                <th>Error/Details</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_jobs as $job): 
                                $status_class = strtolower($job['status']);
                                
                                // Determine if progress bar should be visible based on status
                                $is_active = ($job['status'] === 'PROCESSING' || $job['status'] === 'PENDING');
                                $progress_percent = ($job['status'] === 'PROCESSING') ? 50 : 0; 
                            ?>
                                <tr data-job-id="<?php echo $job['job_id']; ?>" data-job-status="<?php echo $status_class; ?>">
                                    <td><?php echo $job['job_id']; ?></td>
                                    <td><?php echo htmlspecialchars(substr($job['master_file_name'], 0, 255)); ?></td>
                                    <td><span class="status-<?php echo $status_class; ?>" id="status-<?php echo $job['job_id']; ?>"><?php echo $job['status']; ?></span></td>
                                    <td><?php echo formatTimeElapsed($job['created_at'], $job['finished_at']); ?></td>
                                    <td><span class="error-message-cell" title="<?php echo htmlspecialchars($job['error_message'] ?? '—'); ?>"><?php echo htmlspecialchars(substr($job['error_message'] ?? '—', 0, 30)); ?></span></td>
                                    <td class="action-cell">
                                        <!-- Action Buttons -->
                                        <?php if ($job['status'] === 'FAILED'): ?>
                                            <a href="job_control.php?action=retry&job_id=<?php echo $job['job_id']; ?>" class="action-link retry-action">Retry</a>
                                            <a href="javascript:void(0)" data-action="delete_history" data-job-id="<?php echo $job['job_id']; ?>" class="action-link delete-action job-action-link">Delete</a>
                                        <?php elseif ($job['status'] === 'PENDING' || $job['status'] === 'PROCESSING'): ?>
                                            <!-- FIXED: Replaced inline confirm with data attributes for JS modal -->
                                            <a href="javascript:void(0)" data-action="cancel" data-job-id="<?php echo $job['job_id']; ?>" class="action-link cancel-action job-action-link">Cancel</a>
                                        <?php elseif ($job['status'] === 'COMPLETE' || $job['status'] === 'FILE_DELETED'): ?> <!-- FIX: Added FILE_DELETED -->
                                            <span style="color: #28a745; font-size: 0.9em;"><?php echo $job['status'] === 'FILE_DELETED' ? '' : ''; ?></span>
                                            <a href="javascript:void(0)" data-action="delete_history" data-job-id="<?php echo $job['job_id']; ?>" class="action-link delete-action job-action-link">Delete</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <!-- PROGRESS BAR ROW (Full Width Span) -->
                                <?php if ($is_active): ?>
                                    <tr class="progress-row" data-job-id="<?php echo $job['job_id']; ?>" data-job-status="<?php echo $status_class; ?>">
                                        <td colspan="4" style="border: none; padding-top: 0; padding-bottom: 5px;">
                                            <div class="queue-progress">
                                                <div class="queue-progress-bar" id="bar-<?php echo $job['job_id']; ?>" style="width: <?php echo $progress_percent; ?>%;"></div>
                                            </div>
                                        </td>
                                        <td colspan="2" style="border: none; padding-top: 0; padding-bottom: 5px; font-size: 0.75em; color: var(--text-color);">
                                            Created: <?php echo date('H:i:s', strtotime($job['created_at'])); ?> 
                                            | Started: <?php echo $job['started_at'] ? date('H:i:s', strtotime($job['started_at'])) : '—'; ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>The job queue history is empty.</p>
                <?php endif; ?>
            </div>

            <!-- RIGHT COLUMN: COMPLETED FILES (CHUNK REGISTRY) -->
            <div class="log-column">
                <h4 style="margin-top: 0; padding-bottom: 10px; border-bottom: 1px solid #e0e0e0;">Completed File Retrieval</h4>
                
                <?php if (!empty($distributed_files)): ?>
                    <table class="dfs-table">
                        <thead>
                            <tr>
                                <th>Master File Name</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($distributed_files as $file): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($file['master_file_name']); ?></td>
                                    <td class="action-cell">
                                        <!-- Download Button -->
                                        <a href="distribute_file.php?action=download_dfs&uuid=<?php echo htmlspecialchars($file['master_file_unique_id']); ?>" class="action-link download-action">
                                            Download & Assemble
                                        </a>
                                        <!-- Delete Button -->
                                        <a href="javascript:void(0)" data-uuid="<?php echo htmlspecialchars($file['master_file_unique_id']); ?>" data-action="delete_dfs" class="action-link delete-action dfs-action-link">
                                            Delete All Chunks
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No files are currently stored in the Distributed File System registry.</p>
                <?php endif; ?>
            </div>
        </div>
        
    </div>

    <!-- JAVASCRIPT FOR REAL-TIME POLLING AND DRAGGING -->
    <script>
        const INITIAL_JOB_ID = <?php echo json_encode($initial_job_id); ?>; 
        
        // --- Custom Confirm Modal Logic ---
        const modal = document.getElementById('custom-confirm-modal');
        const confirmMessageEl = modal.querySelector('#confirm-message');
        const confirmYesBtn = modal.querySelector('#confirm-yes');
        const confirmNoBtn = modal.querySelector('#confirm-no');
        let currentCallback = null;

        function showCustomConfirm(message, callback) {
            confirmMessageEl.textContent = message;
            modal.style.display = 'flex';
            currentCallback = callback;
        }

        confirmYesBtn.onclick = function() {
            modal.style.display = 'none';
            if (currentCallback) {
                currentCallback(true);
            }
        };

        confirmNoBtn.onclick = function() {
            modal.style.display = 'none';
            if (currentCallback) {
                currentCallback(false);
            }
        };
        // --- END Custom Confirm Modal Logic ---
        
        
        // --- DFS DELETE ACTION HANDLER (Completed Files Table) ---
        document.querySelectorAll('.dfs-action-link').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const uuid = this.getAttribute('data-uuid');
                const action = this.getAttribute('data-action'); 

                if (action === 'delete_dfs') {
                    showCustomConfirm('WARNING: Are you sure you want to delete ALL chunks and metadata for this file?', (confirmed) => {
                        if (confirmed) {
                            window.location.href = `distribute_file.php?action=delete_dfs&uuid=${uuid}&confirm_delete=true`;
                        }
                    });
                }
            });
        });

        // --- JOB HISTORY ACTIONS HANDLER (Job Queue Table) ---
        document.querySelectorAll('.job-action-link').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const jobId = this.getAttribute('data-job-id');
                const action = this.getAttribute('data-action'); 

                if (action === 'delete_history') {
                    showCustomConfirm('Permanently delete this job history record?', (confirmed) => {
                        if (confirmed) {
                            window.location.href = `job_control.php?action=delete_history&job_id=${jobId}`;
                        }
                    });
                } else if (action === 'cancel') {
                    showCustomConfirm('Are you sure you want to cancel the active job (ID: ' + jobId + ')?', (confirmed) => {
                        if (confirmed) {
                            // Redirect to job_control.php for cancellation
                            window.location.href = `job_control.php?action=cancel&job_id=${jobId}`;
                        }
                    });
                }
            });
        });

        
        function updateJobStatus(jobId) {
            fetch('get_job_status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'job_id=' + jobId
            })
            .then(response => response.json())
            .then(data => {
                const statusElement = document.getElementById('status-' + jobId);
                const progressBar = document.getElementById('bar-' + jobId);
                const progressRow = document.querySelector(`tr[data-job-id="${jobId}"].progress-row`);

                if (statusElement && progressBar) {
                    const statusText = data.status;
                    const percent = data.progress_percent;
                    
                    statusElement.textContent = statusText;
                    statusElement.className = 'status-' + statusText.toLowerCase();
                    progressBar.style.width = percent + '%';
                    
                    // Show/Hide the progress row based on activity
                    if (statusText === 'PENDING' || statusText === 'PROCESSING') {
                         if (progressRow) progressRow.style.display = 'table-row';
                    } else {
                         if (progressRow) progressRow.style.display = 'none'; // Hide if complete/failed
                    }
                    
                    // Check for final statuses that trigger a page reload
                    if (statusText === 'COMPLETE' || statusText === 'FAILED' || statusText === 'FILE_DELETED') {
                        if (window['jobInterval_' + jobId]) {
                             clearInterval(window['jobInterval_' + jobId]);
                        }
                        // Reload the page to refresh the table and show the completed file in the right column
                        window.location.reload(); 
                    }
                }
            })
            .catch(error => {
                console.error('Polling Error:', error);
            });
        }

        function makeDraggable(element) {
            let pos1 = 0, pos2 = 0, pos3 = 0, pos4 = 0;
            const header = element.querySelector('h4');
            
            if (header) {
                header.onmousedown = dragMouseDown;
                header.style.cursor = 'move';
            } else {
                element.onmousedown = dragMouseDown;
            }

            function dragMouseDown(e) {
                e = e || window.event;
                e.preventDefault();
                pos3 = e.clientX;
                pos4 = e.clientY;
                document.onmouseup = closeDragElement;
                document.onmousemove = elementDrag;
            }

            function elementDrag(e) {
                e = e || window.event;
                e.preventDefault();
                pos1 = pos3 - e.clientX;
                pos2 = pos4 - e.clientY;
                pos3 = e.clientX;
                pos4 = e.clientY;
                element.style.top = (element.offsetTop - pos2) + "px";
                element.style.left = (element.offsetLeft - pos1) + "px";
            }

            function closeDragElement() {
                document.onmouseup = null;
                document.onmousemove = null;
            }
        }


        document.addEventListener('DOMContentLoaded', function() {
            // 1. Make the CMD Log Window Draggable
            const logConsole = document.getElementById('log-console');
            if (logConsole) {
                // Initial positioning (fixed position now, so adjust once)
                // logConsole.style.top is automatically handled by the CSS (fixed bottom/right)
                makeDraggable(logConsole);
            }
            
            // --- Real-time Polling Initialization ---
            
            // 1. Check all pre-existing pending/processing jobs in the table and start polling them
            document.querySelectorAll('.log-column tbody tr[data-job-id]').forEach(row => {
                const jobId = row.getAttribute('data-job-id');
                const status = row.getAttribute('data-job-status');
                
                if (status === 'pending' || status === 'processing') {
                     // Start polling for all active jobs
                     window['jobInterval_' + jobId] = setInterval(() => {
                         updateJobStatus(jobId);
                     }, 4000); // Poll slightly slower to avoid congestion
                }
            });

            // 2. Poll the most recently submitted job 
            if (INITIAL_JOB_ID) {
                window['jobInterval_' + INITIAL_JOB_ID] = setInterval(() => {
                    updateJobStatus(INITIAL_JOB_ID); 
                }, 3000); 
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