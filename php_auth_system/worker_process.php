<?php
// worker_process.php - CLI Script for Asynchronous Job Processing
// NOTE: This script runs indefinitely via CRON/Scheduled Task.

// Ensure this script can only be run via CLI
if (php_sapi_name() !== 'cli') {
    die("Access Denied: Worker must be run from the command line.");
}

// Set maximum execution time to unlimited for large file processing
set_time_limit(0);
ini_set('max_execution_time', 0); 
error_reporting(E_ALL & ~E_NOTICE); // Suppress NOTICEs common in CLI

require_once 'db_config.php';
require_once 'vendor/autoload.php';
require_once 'User.class.php';

// --- CHUNKING & ENCRYPTION CONFIGURATION ---
// REDUNDANCY_FACTOR is removed; upload is 1:1
const CHUNK_SIZE = 3145728; // 3 MB chunk size

// Placeholder Encryption/Decryption Functions (CRITICAL: Implement securely!)
function encrypt_data($data, $key) { 
    // The fixed IV '1234567890123456' is used. For production, a random IV should be generated and stored/passed with the chunk.
    return base64_encode(openssl_encrypt($data, 'AES-256-CBC', $key, 0, '1234567890123456')); 
}
function decrypt_data($data, $key) { 
    return openssl_decrypt(base64_decode($data), 'AES-256-CBC', $key, 0, '1234567890123456'); 
}
function generate_random_key() { 
    return bin2hex(random_bytes(16));
}
// ----------------------------------------------------

$user_obj = new User();
$pdo = $user_obj->getPdo();
$client_template = new Google_Client();
$client_template->setClientId(GOOGLE_CLIENT_ID);
$client_template->setClientSecret(GOOGLE_CLIENT_SECRET);

echo "Worker process started. Monitoring queue...\n";

// Loop continuously, checking for jobs every 5 seconds
while (true) {
    // 1. Fetch one PENDING job
    $pdo->beginTransaction();
    $stmt = $pdo->query("
        SELECT * FROM distribution_queue 
        WHERE status = 'PENDING' 
        ORDER BY created_at ASC 
        LIMIT 1 FOR UPDATE
    ");
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($job) {
        $job_id = $job['job_id'];
        $pdo->commit(); 
        
        echo "Processing Job ID: {$job_id} for file: {$job['master_file_name']}...\n";
        
        // Update status to PROCESSING immediately
        $pdo->prepare("UPDATE distribution_queue SET status = 'PROCESSING', started_at = NOW() WHERE job_id = ?")
            ->execute([$job_id]);
        
        $job_success = false;
        $error_message = null;
        $successful_chunks = 0;

        try {
            // ** 2. EXECUTE HEAVY CHUNKING AND UPLOAD LOGIC **
            
            $users = $user_obj->getAuthorizedDriveUsers();
            if (empty($users)) { throw new Exception("No authorized user drives available."); }

            $filePath = $job['local_file_path'];
            if (!file_exists($filePath)) { throw new Exception("Uploaded file not found locally at path: {$filePath}"); }

            $fileName = $job['master_file_name'];
            // File UUID generation is critical for retrieval/deletion (Using a more robust combination)
            $fileUUID = md5($job['local_file_path'] . $job_id . $fileName); 
            $users_to_share_count = count($users);
            $fileHandle = fopen($filePath, 'rb');
            $userIndex = 0;
            $chunkSequence = 0;

            while (!feof($fileHandle)) {
                $chunk = fread($fileHandle, CHUNK_SIZE);
                if (empty($chunk)) continue;

                $chunkSequence++;
                $encryptionKey = generate_random_key();
                
                // --- CORE ENCRYPTION STEP ---
                $encryptedChunk = encrypt_data($chunk, $encryptionKey);
                
                // --- Select User for Chunk Storage ---
                $holder = $users[$userIndex % $users_to_share_count]; 
                
                // 1. AUTHENTICATE & SETUP USER SERVICE 
                $client_user = clone $client_template;
                $client_user->fetchAccessTokenWithRefreshToken($holder['google_refresh_token']);
                $service_user = new Google_Service_Drive($client_user);

                // 2. UPLOAD CHUNK (SEQUENTIAL EXECUTION)
                $encryptedFileName = $fileUUID . "_" . $chunkSequence . "_" . generate_random_key();
                // Inside worker_process.php
                $fileMetadata = new Google_Service_Drive_DriveFile([
                    'name' => $chunk_name,
                    'parents' => ['appDataFolder'] // This hides the chunk from the user's view
                ]);
                
                $drive_file = $service_user->files->create($fileMetadata, [
                    'data' => $encrypted_data,
                    'mimeType' => 'application/octet-stream',
                    'uploadType' => 'multipart',
                    'fields' => 'id'
                ]);
                $uploaded_chunk = $service_user->files->create($fileMetadata, [
                    'data' => $encryptedChunk,
                    'mimeType' => 'application/octet-stream',
                    'uploadType' => 'multipart',
                    'fields' => 'id'
                ]);
                
                // 3. REGISTER CHUNK METADATA (FIXED ARGUMENT KEYS)
                $user_obj->registerChunk([
                    'masterFileName'        => $fileName, 
                    'masterFileUUID'        => $fileUUID, 
                    'chunkSequenceNumber'   => $chunkSequence, 
                    'userId'                => $holder['id'], 
                    'driveFileId'           => $uploaded_chunk->getId(),
                    'chunkSize'             => strlen($chunk), // Note: Storing original chunk size
                    'encryptionKey'         => $encryptionKey
                ]);
                
                $successful_chunks++;
                $userIndex++; // Only increment by 1
            }
            fclose($fileHandle);
            
            // Cleanup: Delete the local temporary file after successful upload
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            $job_success = true;

        } catch (Exception $e) {
            $error_message = $e->getMessage();
            $job_success = false;
        }

        // 3. Update Job Status
        if ($job_success) {
            $pdo->prepare("UPDATE distribution_queue SET status = 'COMPLETE', finished_at = NOW() WHERE job_id = ?")
                ->execute([$job_id]);
            echo "Job {$job_id} completed successfully. Chunks uploaded: {$successful_chunks}\n";
        } else {
            // Log failure status and error message
            $pdo->prepare("UPDATE distribution_queue SET status = 'FAILED', finished_at = NOW(), error_message = ? WHERE job_id = ?")
                ->execute([$error_message, $job_id]);
            echo "Job {$job_id} failed: {$error_message}\n";
        }
        
    } else {
        $pdo->rollBack(); // Release the lock if no job was found
        
        // --- CLI Warning Fixes ---
        echo "[" . date('H:i:s.v') . "] Checking for PENDING jobs...\n";
        sleep(5); 
    }
}
?>