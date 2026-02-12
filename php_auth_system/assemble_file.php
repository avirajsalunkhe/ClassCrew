<?php
// assemble_file.php - Fetches, Assembles, and Serves Distributed File
require_once 'db_config.php';
require_once 'vendor/autoload.php';
require_once 'User.class.php';

// Placeholder Decryption Function
function decrypt_data($data, $key) { return openssl_decrypt(base64_decode($data), 'AES-256-CBC', $key, 0, '1234567890123456'); }

// Get UUID from URL or POST
$fileUUID = $_GET['uuid'] ?? die("File ID not provided.");
$user_obj = new User();

// 1. Fetch ALL CHUNKS metadata from the registry
$pdo = $user_obj->getPdo();
$stmt = $pdo->prepare("
    SELECT c.*, u.google_refresh_token 
    FROM chunk_registry c 
    JOIN users u ON c.user_id = u.id 
    WHERE c.master_file_unique_id = ?
    ORDER BY c.chunk_sequence_number ASC
");
$stmt->execute([$fileUUID]);
$chunks_metadata = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($chunks_metadata)) { die("Error: Metadata for file not found or corrupted."); }

$masterFileName = $chunks_metadata[0]['master_file_name'];
$assembledContent = '';
$client_template = new Google_Client();
// ... (Client setup) ...

// 2. Loop through metadata, download, decrypt, and assemble
foreach ($chunks_metadata as $chunk) {
    $refreshToken = $chunk['google_refresh_token'];
    
    try {
        // Authenticate as the holder (Requires token refresh logic)
        $client_user = clone $client_template;
        $client_user->fetchAccessTokenWithRefreshToken($refreshToken);
        $service_user = new Google_Service_Drive($client_user);

        // Download the chunk content
        $response = $service_user->files->get($chunk['drive_file_id'], [
            'alt' => 'media',
            'spaces' => 'appDataFolder' // Required to locate files in the hidden directory
        ]);
        $encryptedContent = (string)$response->getBody();

        // Decrypt and assemble
        $decryptedContent = decrypt_data($encryptedContent, $chunk['encryption_key']);
        $assembledContent .= $decryptedContent;

    } catch (Exception $e) {
        die("Critical Error: Failed to retrieve chunk " . $chunk['chunk_sequence_number'] . " from user " . $chunk['user_id'] . ". File corrupted.");
    }
}

// 3. Serve the assembled file
header('Content-Type: application/octet-stream'); // Or the original MIME type
header('Content-Disposition: attachment; filename="' . $masterFileName . '"');
header('Content-Length: ' . strlen($assembledContent));

echo $assembledContent;
exit;
?>