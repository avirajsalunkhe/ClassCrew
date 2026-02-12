<?php
// get_job_status.php - Provides real-time status updates for the background job queue.

require_once 'db_config.php';
require_once 'User.class.php';

// Ensure the request is POST and contains the job ID
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['job_id'])) {
    http_response_code(400);
    header('Content-Type: application/json');
    die(json_encode(["status" => "error", "message" => "Invalid request parameters."]));
}

$job_id = $_POST['job_id'];
$user_obj = new User();
$pdo = $user_obj->getPdo();

try {
    // Fetch job details by ID
    $stmt = $pdo->prepare("
        SELECT status, started_at, created_at, error_message
        FROM distribution_queue
        WHERE job_id = ?
    ");
    $stmt->execute([$job_id]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        http_response_code(404);
        header('Content-Type: application/json');
        die(json_encode(["status" => "error", "message" => "Job not found."]));
    }
    
    // Calculate progress based on time/status (This assumes the worker updates the status frequently)
    // NOTE: For true granular progress (e.g., 50% chunked), the worker would need to update a 'progress_percent' column.
    // Since we only track STARTED/COMPLETE, we rely on general status checks.
    
    $response = [
        'status' => $job['status'],
        'error' => $job['error_message'],
        'time_elapsed' => time() - strtotime($job['created_at']),
        'progress_percent' => 0 // Default progress
    ];

    if ($job['status'] === 'PROCESSING') {
        // Simple progress simulation based on time elapsed in PROCESSING state
        $response['progress_percent'] = 50; 
    } elseif ($job['status'] === 'COMPLETE') {
        $response['progress_percent'] = 100;
    } elseif ($job['status'] === 'FAILED') {
        $response['progress_percent'] = 100;
    }


    // Send JSON response
    header('Content-Type: application/json');
    echo json_encode($response);
    http_response_code(200);

} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    die(json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]));
}
?>