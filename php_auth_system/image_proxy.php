<?php
// image_proxy.php - Server-Side Image Proxy with Caching
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0); // Suppress all errors for a clean image output

session_start();
require_once 'vendor/autoload.php';
require_once 'db_config.php';
require_once 'User.class.php';

use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;

// --- CONFIGURATION ---
$cache_dir = __DIR__ . '/cache/chat_images/';
$cache_lifetime = 60 * 60 * 24 * 7; // 7 days cache

if (!is_dir($cache_dir)) {
    @mkdir($cache_dir, 0777, true);
}

$file_id = $_GET['id'] ?? null;
$user_id = $_SESSION['user_id'] ?? null;

if (empty($file_id) || empty($user_id)) {
    http_response_code(400);
    exit;
}

$cache_file_path = $cache_dir . $file_id . '.cache';
$user_obj = null;

try {
    $user_obj = new User();
} catch (\Throwable $e) {
    http_response_code(500);
    exit;
}

// --- 1. CHECK LOCAL CACHE ---
if (file_exists($cache_file_path) && (time() - filemtime($cache_file_path) < $cache_lifetime)) {
    // Cache hit: Serve cached image
    $mime_type = mime_content_type($cache_file_path);
    header("Content-Type: {$mime_type}");
    header('Cache-Control: max-age=' . $cache_lifetime);
    readfile($cache_file_path);
    exit;
}

// --- 2. FETCH FROM GOOGLE DRIVE (Cache Miss) ---
$user_tokens = $user_obj->getUserWithTokens($user_id);
$refreshToken = $user_tokens['google_refresh_token'] ?? null;

if (empty($refreshToken)) {
    // If the user is not linked, we can't fetch the file.
    http_response_code(404); 
    exit;
}

try {
    // Initialize client for a non-chat user's token (the current user)
    $client = new Client();
    $client->setClientId(GOOGLE_CLIENT_ID);
    $client->setClientSecret(GOOGLE_CLIENT_SECRET);
    $client->setAccessType('offline');
    $client->setScopes([Drive::DRIVE_FILE, Drive::DRIVE_READONLY]); 
    
    $client->refreshToken = $refreshToken;
    $client->fetchAccessTokenWithRefreshToken($refreshToken);
    
    if (!$client->getAccessToken()) {
        throw new Exception("Failed to refresh Google Access Token.");
    }
    
    $service = new Drive($client);

    // CRITICAL: Use alt=media for direct download of file content.
    // We request the image content directly from Google Drive.
    $response = $service->files->get($file_id, [
        'alt' => 'media',
        'spaces' => 'appDataFolder' // Required to find the hidden chat image
    ]);
    
    $content = $response->getBody()->getContents();
    $mime_type = $response->getHeaderLine('Content-Type');

    // Get the actual file extension from the mime type for better caching
    $extension = 'jpg'; // Default
    if (strpos($mime_type, 'png') !== false) $extension = 'png';
    elseif (strpos($mime_type, 'gif') !== false) $extension = 'gif';
    elseif (strpos($mime_type, 'jpeg') !== false) $extension = 'jpeg';
    
    $final_cache_path = $cache_dir . $file_id . '.' . $extension;
    
    // --- 3. SAVE TO CACHE ---
    file_put_contents($final_cache_path, $content);

    // --- 4. SERVE IMAGE ---
    header("Content-Type: {$mime_type}");
    header('Cache-Control: max-age=' . $cache_lifetime);
    echo $content;
    exit;

} catch (\Exception $e) {
    error_log("Image Proxy Error (File ID: {$file_id}): " . $e->getMessage());
    http_response_code(503); // Service Unavailable/Temporary Error
    exit;
}
?>