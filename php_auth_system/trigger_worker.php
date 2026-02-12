<?php
// trigger_worker.php - Executes the batch file and returns instantly (Non-Blocking)

session_start();
require_once 'db_config.php'; 
// NOTE: vendor/autoload.php is not strictly needed here, as we are only using shell_exec.

// --- CRITICAL CONFIGURATION: REPLACE THIS PATH ---
// The path to your batch file (e.g., C:\xampp\htdocs\php_auth_system\start_worker.bat)
const BATCH_FILE_PATH = 'C:\xampp\htdocs\php_auth_system\start_worker.bat'; 

// Ensure security: Only allow access if the user is authenticated 
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    header('Content-Type: application/json');
    die(json_encode(["status" => "error", "message" => "Authentication Required."]));
}

function launch_batch_worker() {
    // Escape the path for safe shell execution
    $batch_script_arg = escapeshellarg(BATCH_FILE_PATH);

    // NON-BLOCKING COMMAND for Windows: 
    // Use 'start /B cmd.exe /C' to execute the file in the background and ensure cmd closes after execution.
    // 'start /B' is crucial for non-blocking execution.
    $command = "start /B cmd.exe /C {$batch_script_arg}";

    // Execute the command
    shell_exec($command); 
    
    // In a stable environment, shell_exec returns NULL immediately upon success
    if (in_array('shell_exec', explode(',', ini_get('disable_functions')))) {
        return ['status' => 'failure', 'message' => 'Shell execution failed. Check PHP\'s `disable_functions` setting.'];
    }
    
    return ['status' => 'success', 'message' => 'Batch worker launched successfully.'];
}

// Execute the launch function
$response = launch_batch_worker();

// Respond instantly so the browser page doesn't hang
header('Content-Type: application/json');
echo json_encode($response);
http_response_code($response['status'] === 'success' ? 202 : 500); 
?>