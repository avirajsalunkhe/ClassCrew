<?php
// job_control.php - Handles manual admin intervention (Retry/Cancel/Delete)
require_once 'db_config.php';
require_once 'User.class.php';

// Check if user is logged in and is the Admin (User ID 1)
if (!isset($_SESSION['logged_in']) || $_SESSION['user_id'] != 1) {
    header('Location: index.php?error=access_denied');
    exit;
}

$user_obj = new User();
$pdo = $user_obj->getPdo();

$job_id = $_GET['job_id'] ?? null;
$action = $_GET['action'] ?? null;
$message = '';

if ($job_id && $action) {
    try {
        $pdo->beginTransaction();
        
        if ($action === 'retry') {
            // RETRY: Move status back to PENDING and increment retry_count
            $stmt = $pdo->prepare("
                UPDATE distribution_queue 
                SET status = 'PENDING', error_message = NULL, started_at = NULL, finished_at = NULL, retry_count = retry_count + 1
                WHERE job_id = ? AND status IN ('FAILED')
            ");
            $stmt->execute([$job_id]);
            $message = $stmt->rowCount() ? "SUCCESS: Job {$job_id} marked PENDING for retry." : "FAILURE: Job {$job_id} not found or not in FAILED state.";

        } elseif ($action === 'cancel') {
            // CANCEL: Mark status as FAILED/CANCELLED
            $stmt = $pdo->prepare("
                UPDATE distribution_queue 
                SET status = 'FAILED', finished_at = NOW(), error_message = 'Cancelled by Admin.', retry_count = retry_count 
                WHERE job_id = ? AND status IN ('PENDING', 'PROCESSING')
            ");
            $stmt->execute([$job_id]);
            $message = $stmt->rowCount() ? "SUCCESS: Job {$job_id} cancelled." : "FAILURE: Job {$job_id} not active or already finished.";
        
        } elseif ($action === 'delete_history') {
            // NEW: DELETE HISTORY: Permanently remove the job entry
            $stmt = $pdo->prepare("
                DELETE FROM distribution_queue 
                WHERE job_id = ? AND status IN ('COMPLETE', 'FAILED','FILE_DELETED')
            ");
            $stmt->execute([$job_id]);
            $message = $stmt->rowCount() ? "SUCCESS: Job {$job_id} history deleted." : "FAILURE: Job {$job_id} not found or is still PENDING/PROCESSING.";
        }
        
        $pdo->commit();

    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "CRITICAL DB ERROR: " . $e->getMessage();
    }
} else {
    $message = "Invalid job ID or action.";
}

// Redirect back to the distribution console with the status
header("Location: distribute_file.php?status=" . urlencode($message));
exit;
?>