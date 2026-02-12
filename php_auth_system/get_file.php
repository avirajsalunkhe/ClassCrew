<?php
// get_file.php
// Secure Endpoint to retrieve a distributed, encrypted file (image) by its DFS ID.

// CRITICAL FIX 1: Enable aggressive error reporting to diagnose fatal crashes
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ----------------------------------------------------
// 1. INITIALIZATION & SECURITY CHECKS
// ----------------------------------------------------

// START OUTPUT BUFFERING HERE to capture warnings/errors
// This is essential to prevent PHP output corruption of binary streams.
ob_start();

session_start();

// FIX: Ensure correct relative paths using __DIR__ for file includes
require_once __DIR__ . '/vendor/autoload.php'; 
require_once __DIR__ . '/db_config.php';          
require_once __DIR__ . '/User.class.php';      

use Google\Client;
use Google\Service\Drive;

// ADDED: Global error handler for fatal errors (for better debugging feedback)
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // Clear the buffer containing corrupted image data/warnings
        ob_end_clean(); 
        
        http_response_code(500);
        header('Content-Type: text/plain');
        error_log("FATAL PHP ERROR in get_file.php: " . $error['message'] . " on line " . $error['line']);
        die("Fatal server error during file processing (code 500). Check server logs.");
    }
});


// Check user authentication
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    ob_end_clean();
    die('Unauthorized access.');
}

// Check for required input (DFS Master File ID)
$dfs_file_id = $_GET['id'] ?? null;
if (empty($dfs_file_id) || !preg_match('/^DFS-[0-9a-f]{8}$/i', $dfs_file_id)) { 
    http_response_code(400);
    ob_end_clean();
    die('Invalid file identifier.');
}

// CRITICAL FIX: Use try-catch around User instantiation for clean error feedback
try {
    $user_obj = new User();
} catch (\Throwable $e) {
    http_response_code(500);
    ob_end_clean();
    error_log('FATAL INIT ERROR in get_file.php: ' . $e->getMessage());
    die('FATAL INIT ERROR: Database or User class failed to load. Check server logs.');
}

// ----------------------------------------------------
// 2. CORE CRYPTOGRAPHIC AND DRIVE HELPER FUNCTIONS
// ----------------------------------------------------

/**
 * Decrypts an encrypted chunk using the shared AES parameters.
 * IMPORTANT: This function now handles returning the mock data directly if triggered by the MOCK_CONTENT flag.
 */
function decrypt_chunk($encrypted_data, $key) {
    // CRITICAL MOCK FIX: Check for the mock flag BEFORE attempting real decryption
    if ($encrypted_data == 'MOCK_CONTENT') {
        // Mock PNG data: 34 bytes of raw binary data (decoded)
        return base64_decode('R0lGODlhIAAgAPIAAP///wAAAAYGBgAAACH5BAEKAAAAALAAAAAAIAAgAAADQAAAjL+AywABgAEiQQqJQQQAOw==');
    }

    $iv = '1234567890123456'; 
    $cipher = 'AES-256-CBC';
    
    $decrypted_data = openssl_decrypt(
        base64_decode($encrypted_data), 
        $cipher, 
        $key, 
        0, 
        $iv
    );
    
    if ($decrypted_data === false) {
        error_log("Decryption failed for chunk. Key: {$key}.");
        return ''; 
    }
    
    return $decrypted_data; 
}

/**
 * Downloads a raw file chunk from Google Drive using the provided authenticated client.
 */
function download_drive_chunk(Client $client, $drive_file_id) {
    // CRITICAL MOCK FIX: Check for the mock ID
    if ($drive_file_id == 'MOCK_DRIVE_ID_1') {
        return 'MOCK_CONTENT'; 
    }

    try {
        $service = new Drive($client);
        
        $response = $service->files->get($drive_file_id, [
            'alt' => 'media',
            'spaces' => 'appDataFolder'
        ]);

        return $response->getBody()->getContents();

    } catch (\Exception $e) {
        error_log("Drive download failed for ID {$drive_file_id}: " . $e->getMessage());
        return false;
    }
}


// ----------------------------------------------------
// 3. FILE REASSEMBLY AND STREAMING
// ----------------------------------------------------

$chunks = $user_obj->getChunksByMasterId($dfs_file_id);

if (empty($chunks)) {
    http_response_code(404);
    ob_end_clean();
    die('File metadata not found or chunks missing.'); 
}

$reassembled_file_data = '';
$master_file_name = $chunks[0]['master_file_name'] ?? 'image.jpg'; 

// Attempt to determine Mime Type based on the file extension
$file_extension = pathinfo($master_file_name, PATHINFO_EXTENSION);

switch (strtolower($file_extension)) {
    case 'jpg': case 'jpeg':
        $mime_type = 'image/jpeg';
        break;
    case 'png':
        $mime_type = 'image/png';
        break;
    case 'gif':
        $mime_type = 'image/gif';
        break;
    default:
        $mime_type = 'application/octet-stream'; 
        break;
}


// Initialize Google Client for API calls (Done once)
$client = new Client();
// FIX: Using dedicated Client ID/Secret defined in db_config.php
if (!defined('GOOGLE_CLIENT_ID') || !defined('GOOGLE_CLIENT_SECRET')) {
    error_log("Google API constants are missing. Cannot authenticate Drive access.");
    // If constants are missing, API calls below will fail, but mock data path is still available.
} else {
    $client->setClientId(GOOGLE_CLIENT_ID);
    $client->setClientSecret(GOOGLE_CLIENT_SECRET);
    $client->addScope(Drive::DRIVE_READONLY);
    $client->setAccessType('offline');
    $client->setPrompt('none'); 
}


// ----------------------------------------------------
// Iterate over chunks, download, and decrypt
// ----------------------------------------------------

foreach ($chunks as $chunk) {
    $refreshToken = $chunk['google_refresh_token'];
    $driveFileId = $chunk['drive_file_id'];
    
    // Only attempt Drive API access if the ID is NOT the mock ID
    if ($driveFileId !== 'MOCK_DRIVE_ID_1' && empty($refreshToken)) {
        error_log("Chunk ID {$driveFileId} missing refresh token for User {$chunk['user_id']}. Skipping.");
        continue; 
    }
    
    try {
        if ($driveFileId !== 'MOCK_DRIVE_ID_1') {
            // Path for actual Drive access
            $client->setAccessType('offline');
            $client->refreshToken = $refreshToken; 
            
            $token_array = $client->fetchAccessTokenWithRefreshToken($refreshToken);
            
            if (empty($token_array['access_token'])) {
                throw new Exception("Failed to get Access Token for chunk holder.");
            }

            $client->setAccessToken($token_array); 
        }
        
        // Step 3: Download the encrypted chunk data (handles mock internally)
        $encrypted_chunk = download_drive_chunk($client, $driveFileId);
        
        if ($encrypted_chunk === false) {
            error_log("Failed to download encrypted chunk {$driveFileId}. Skipping.");
            continue;
        }
        
        // Step 4: Decrypt the chunk data
        $decrypted_chunk = decrypt_chunk($encrypted_chunk, $chunk['encryption_key']);
        
        if (empty($decrypted_chunk)) { // Check for empty string (failed decryption)
             error_log("Decryption yielded empty data for chunk {$driveFileId}. Skipping.");
             continue;
        }
        
        // Step 5: Append the decrypted data to the reassembled file
        $reassembled_file_data .= $decrypted_chunk;

    } catch (\Exception $e) {
        error_log("Error processing chunk {$driveFileId}: " . $e->getMessage());
        continue; // Move to the next chunk
    }
}

// ----------------------------------------------------
// 4. STREAM FILE TO BROWSER
// ----------------------------------------------------

if (empty($reassembled_file_data)) {
    http_response_code(404);
    ob_end_clean();
    die('Failed to reassemble file from all available chunks. Data loss may have occurred.');
}

// CRITICAL STEP: Clear everything captured in the output buffer before streaming the binary data
ob_end_clean(); 

// CRITICAL FIX 2: Ensure Mime Type is set correctly for browser display
if ($mime_type === 'application/octet-stream' || $chunks[0]['drive_file_id'] === 'MOCK_DRIVE_ID_1') {
    // If we rely on mock content, force PNG mime type
    $mime_type = 'image/png';
    $master_file_name = 'mock_image.png';
}

header('Content-Type: ' . $mime_type);
header('Content-Length: ' . strlen($reassembled_file_data));
// Use 'inline' so the <img> tag in chat_console.php displays the content
header('Content-Disposition: inline; filename="' . $master_file_name . '"'); 

echo $reassembled_file_data;
exit;