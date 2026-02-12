@echo off
ECHO Starting DFS Worker Process Loop...

:START_LOOP
ECHO [%TIME%] Checking for PENDING jobs...

:: CRITICAL: Replace these paths with your exact installation paths
SET PHP_EXE="C:\xampp\php\php.exe"
SET WORKER_PATH="C:\xampp\htdocs\php_auth_system\worker_process.php"

:: Execute the worker script using the PHP CLI
%PHP_EXE% %WORKER_PATH%

:: Check exit code (optional, for debugging)

:: Wait 5 seconds before checking the queue again
TIMEOUT /T 5 /NOBREAK

GOTO START_LOOP

:END
ECHO Worker process stopped.