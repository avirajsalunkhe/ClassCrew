<?php
// drive_actions.php - Consolidated logic for all Drive actions
require_once 'db_config.php';
require_once 'vendor/autoload.php';

// Ensure user is logged in
if (!isset($_SESSION['logged_in']) || !isset($_SESSION['access_token'])) {
    header('Location: index.php?error=relogin_required');
    exit;
}

// Check for Download Action first (since it uses GET and sends headers)
if (($_GET['action'] ?? null) === 'download' && isset($_GET['fileId'])) {
    // --- DOWNLOAD LOGIC EXECUTES FIRST ---
    
    // We cannot use the main try/catch block for the final redirect, 
    // so we wrap the download specifically.
    try {
        $fileId = $_GET['fileId'];

        $client = new Google_Client();
        $client->setClientId(GOOGLE_CLIENT_ID);
        $client->setClientSecret(GOOGLE_CLIENT_SECRET);
        $client->setAccessToken($_SESSION['access_token']);

        if ($client->isAccessTokenExpired()) {
            // NOTE: In production, you'd try to refresh the token here.
            header('Location: logout.php?error=token_expired');
            exit;
        }

        $service = new Google_Service_Drive($client);

        // 1. Get file metadata (name and MIME type)
        $file_metadata = $service->files->get($fileId, ['fields' => 'name, mimeType']);
        $file_name = $file_metadata->getName();
        $mime_type = $file_metadata->getMimeType();

        // 2. Fetch the file content using alt=media
        $response = $service->files->get($fileId, ['alt' => 'media']);

        // 3. Set headers for file download
        header('Content-Type: ' . $mime_type);
        header('Content-Disposition: attachment; filename="' . $file_name . '"');
        header('Content-Length: ' . $response->getBody()->getSize());
        
        // 4. Output the file content and stop script execution
        echo $response->getBody();
        exit; 

    } catch (Exception $e) {
        // Redirect back to manager page with download error
        header("Location: drive_manager.php?status=Download_Error:" . urlencode($e->getMessage()));
        exit;
    }
}


// --- POST ACTIONS (Upload, Delete, Rename, Move) ---
$action = $_POST['action'] ?? null;
$message = '';

try {
    // Client setup is repeated here for POST actions
    $client = new Google_Client();
    $client->setClientId(GOOGLE_CLIENT_ID);
    $client->setClientSecret(GOOGLE_CLIENT_SECRET);
    $client->setAccessToken($_SESSION['access_token']);

    // Check expiration only needed if not handled by refresh logic
    if ($client->isAccessTokenExpired()) {
        // NOTE: In production, token refresh logic goes here
        header('Location: logout.php?error=token_expired');
        exit;
    }

    $service = new Google_Service_Drive($client);

    // ===================================================================
    // UPLOAD LOGIC
    // ===================================================================
    if ($action === 'upload' && isset($_FILES['uploadFile'])) {
        $file = $_FILES['uploadFile'];
        if ($file['error'] === UPLOAD_ERR_OK) {
            
            $fileMetadata = new Google_Service_Drive_DriveFile([
                'name' => $file['name']
            ]);
            
            $content = file_get_contents($file['tmp_name']);
            
            $service->files->create($fileMetadata, [
                'data' => $content,
                'mimeType' => $file['type'],
                'uploadType' => 'multipart',
                'fields' => 'id'
            ]);

            $message = urlencode("Successfully uploaded: " . $file['name']);
            
        } else {
            $message = urlencode("Upload failed with error code: " . $file['error']);
        }
    } 
    
    // ===================================================================
    // DELETE LOGIC
    // ===================================================================
    elseif ($action === 'delete' && isset($_POST['fileId'])) {
        $fileId = $_POST['fileId'];
        $service->files->delete($fileId);

        $message = urlencode("Successfully deleted file ID: " . $fileId);
    } 
    
    // ===================================================================
    // RENAME LOGIC
    // ===================================================================
    elseif ($action === 'rename' && isset($_POST['fileId'], $_POST['newName'])) {
        $fileId = $_POST['fileId'];
        $newName = $_POST['newName'];

        $fileMetadata = new Google_Service_Drive_DriveFile(['name' => $newName]);
        
        $service->files->update($fileId, $fileMetadata, [
            'fields' => 'id, name'
        ]);

        $message = urlencode("Successfully renamed file to: " . $newName);
    }

    // ===================================================================
    // MOVE LOGIC
    // ===================================================================
    elseif ($action === 'move' && isset($_POST['fileId'], $_POST['newFolderId'])) {
        $fileId = $_POST['fileId'];
        $newFolderId = $_POST['newFolderId'];
        
        $file = $service->files->get($fileId, ['fields' => 'parents']);
        $previousParents = implode(',', $file->parents);
        
        $service->files->update($fileId, new Google_Service_Drive_DriveFile(), [
            'addParents' => $newFolderId,
            'removeParents' => $previousParents
        ]);

        $message = urlencode("File successfully moved.");
    }
    
} catch (Exception $e) {
    $message = urlencode("Drive Error: " . $e->getMessage());
}

// Redirect back to the manager page with status message
// NOTE: We don't need a folderId in the redirect because the manager should default to root.
header("Location: drive_manager.php?status=$message");
exit;
?>