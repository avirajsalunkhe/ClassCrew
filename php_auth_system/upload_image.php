<?php
// upload_image.php - Handles image upload to the user's Drive, sets public permissions, and returns the direct link.

// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require_once 'vendor/autoload.php';
require_once 'db_config.php';
require_once 'User.class.php';

use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\Permission;

header('Content-Type: application/json');

// --- 1. AUTHENTICATION AND PRE-CHECKS ---

if (!isset($_SESSION['logged_in']) || !isset($_SESSION['user_id']) || empty($_FILES['image_file']['tmp_name'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized or no file received.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$uploaded_file = $_FILES['image_file'];
$file_name = $uploaded_file['name'];

try {
    $user_obj = new User();
    $user_details = $user_obj->getUserWithTokens($user_id);
    $refreshToken = $user_details['google_refresh_token'] ?? null;

    if (empty($refreshToken)) {
        throw new Exception("User is not linked to Google Drive.");
    }
    
    // --- 2. INITIALIZE GOOGLE CLIENT FOR UPLOADER ---
    $client = new Client();
    $client->setClientId(GOOGLE_CLIENT_ID);
    $client->setClientSecret(GOOGLE_CLIENT_SECRET);
    $client->setAccessType('offline');
    $client->refreshToken = $refreshToken;

    $token_array = $client->fetchAccessTokenWithRefreshToken($refreshToken);
    if (empty($token_array['access_token'])) {
        throw new Exception("Failed to refresh Google Access Token. Re-link Drive.");
    }
    $client->setAccessToken($token_array);
    $driveService = new Drive($client);

    // --- 3. UPLOAD FILE TO DRIVE ---
    $fileMetadata = new DriveFile([
        'name' => $file_name,
        // Set the file type from the uploaded file's mime type
        'mimeType' => $uploaded_file['type'],
        'parents' => ['appDataFolder']
    ]);

    $content = file_get_contents($uploaded_file['tmp_name']);

    $file = $driveService->files->create($fileMetadata, [
        'data' => $content,
        'mimeType' => $uploaded_file['type'],
        'uploadType' => 'multipart',
        'fields' => 'id, webContentLink' // Request the ID and the webContentLink
    ]);

    $fileId = $file->id;

    // --- 4. SET PUBLIC SHARING PERMISSION ---
    $permission = new Permission([
        'type' => 'anyone',
        'role' => 'reader',
        // CRITICAL: This is needed to allow direct display in <img> tags
        'allowFileDiscovery' => false 
    ]);
    $driveService->permissions->create($fileId, $permission);
    
    // --- 5. GENERATE FINAL EMBED/PREVIEW LINK ---
    // The webContentLink often requires a Google login. We need the "embed" link structure.
    // Base Download URL: https://drive.google.com/uc?export=view&id={FILE_ID}
    $public_url = "https://drive.google.com/uc?export=view&id={$fileId}";

    // --- 6. RETURN SUCCESS ---
    echo json_encode([
        'status' => 'success',
        'message' => 'Image successfully uploaded and shared.',
        'dfs_file_id' => $public_url // Renamed to keep the chat client consistent
    ]);

} catch (\Exception $e) {
    http_response_code(500);
    error_log("Image Upload Failed: " . $e->getMessage());
    echo json_encode([
        'status' => 'error', 
        'message' => 'Server or Drive API Error: ' . $e->getMessage()
    ]);
}
?>