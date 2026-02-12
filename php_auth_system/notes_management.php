<?php
// notes_management.php - Standalone Batch-specific Distributed Notes System
set_time_limit(0); 
require_once 'db_config.php';
require_once 'vendor/autoload.php';
require_once 'User.class.php';

// --- CRITICAL: Set Timezone ---
date_default_timezone_set('Asia/Kolkata');

/**
 * START INDEPENDENT LOGIC SECTION
 */

// Helper: Decryption for DFS Chunks
function decrypt_chunk($data, $key) { 
    return openssl_decrypt(base64_decode($data), 'AES-256-CBC', $key, 0, '1234567890123456'); 
}

// Helper: Encryption for DFS Chunks
function encrypt_chunk($data, $key) {
    return base64_encode(openssl_encrypt($data, 'AES-256-CBC', $key, 0, '1234567890123456'));
}

// Helper: Calculate Time Remaining
function getTimeRemaining($expiryDate) {
    if (!$expiryDate) return null;
    $expiry = new DateTime($expiryDate);
    $now = new DateTime();
    if ($now > $expiry) return "Expired";
    
    $diff = $now->diff($expiry);
    $parts = [];
    if ($diff->days > 0) $parts[] = $diff->days . 'd';
    if ($diff->h > 0) $parts[] = $diff->h . 'h';
    if ($diff->i > 0 && $diff->days == 0) $parts[] = $diff->i . 'm'; // Show minutes only if less than a day
    
    return count($parts) > 0 ? implode(' ', $parts) . ' left' : 'Expiring soon';
}

$user_obj = new User();
$pdo = $user_obj->getPdo();
$user_id = $_SESSION['user_id'] ?? null;

// Access Control
if (!isset($_SESSION['logged_in']) || !$user_id) { 
    header('Location: index.php?error=access_denied'); 
    exit; 
}

$user_data = $user_obj->getUserById($user_id);
$is_admin = !empty($user_data['is_admin']);
$theme = $_COOKIE['theme'] ?? 'day';

// --- HANDLE ACTIONS ---
$action = $_GET['action'] ?? null;
$status_msg = $_GET['status'] ?? "";
$selected_batch = $_GET['batch_id'] ?? null;

if ($is_admin) {
    // 1. Toggle Visibility (Disable/Enable for students)
    if ($action === 'toggle_visibility') {
        $note_id = $_GET['note_id'] ?? null;
        $new_status = $_GET['status'] ?? 1;
        $stmt = $pdo->prepare("UPDATE notes_registry SET is_visible = ? WHERE note_id = ?");
        $stmt->execute([$new_status, $note_id]);
        header("Location: notes_management.php?status=Updated&batch_id=$selected_batch");
        exit;
    }

    // 2. Delete Note Metadata
    if ($action === 'delete_note') {
        $note_id = $_GET['note_id'] ?? null;
        $stmt = $pdo->prepare("DELETE FROM notes_registry WHERE note_id = ?");
        $stmt->execute([$note_id]);
        header("Location: notes_management.php?status=Deleted&batch_id=$selected_batch");
        exit;
    }

    // 3. Batch Actions (Bulk Update)
    if ($action === 'batch_bulk_action' && $selected_batch) {
        $bulk_type = $_GET['type'] ?? '';
        if ($bulk_type === 'enable_all') {
            $stmt = $pdo->prepare("UPDATE notes_registry SET is_visible = 1 WHERE batch_id = ?");
            $stmt->execute([$selected_batch]);
        } elseif ($bulk_type === 'disable_all') {
            $stmt = $pdo->prepare("UPDATE notes_registry SET is_visible = 0 WHERE batch_id = ?");
            $stmt->execute([$selected_batch]);
        } elseif ($bulk_type === 'delete_all') {
            $stmt = $pdo->prepare("DELETE FROM notes_registry WHERE batch_id = ?");
            $stmt->execute([$selected_batch]);
        }
        header("Location: notes_management.php?status=BatchActionComplete&batch_id=$selected_batch");
        exit;
    }

    // 4. Handle Note Upload & Distribution
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['noteFile'])) {
        $batch_id = $_POST['target_batch'];
        $title = $_POST['noteTitle'];
        $desc = $_POST['noteDesc'];
        $validity = !empty($_POST['validityDate']) ? $_POST['validityDate'] : null;
        $file = $_FILES['noteFile'];

        if ($file['error'] === UPLOAD_ERR_OK) {
            $fileUUID = md5(uniqid(rand(), true));
            $fileContent = file_get_contents($file['tmp_name']);
            $chunkSize = 1024 * 1024 * 2; // 2MB Chunks
            $chunks = str_split($fileContent, $chunkSize);
            
            // Get students in this batch who have linked Drive
            $stmt = $pdo->prepare("
                SELECT u.id, u.google_refresh_token 
                FROM users u 
                JOIN batch_students bs ON u.id = bs.user_id 
                WHERE bs.batch_id = ? AND u.google_refresh_token IS NOT NULL
            ");
            $stmt->execute([$batch_id]);
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($students)) {
                $status_msg = "Error: No students in this batch have linked Google Drive for hosting.";
            } else {
                $client = new Google_Client();
                $client->setClientId(GOOGLE_CLIENT_ID);
                $client->setClientSecret(GOOGLE_CLIENT_SECRET);

                $success_count = 0;
                foreach ($chunks as $index => $data) {
                    $target_student = $students[$index % count($students)];
                    $key = bin2hex(random_bytes(16));
                    $encrypted = encrypt_chunk($data, $key);

                    try {
                        $client->fetchAccessTokenWithRefreshToken($target_student['google_refresh_token']);
                        $drive = new \Google\Service\Drive($client);
                        
                        $fileMetadata = new \Google\Service\Drive\DriveFile([
                            'name' => "note_{$fileUUID}_{$index}.enc",
                            'parents' => ['appDataFolder']
                        ]);

                        $createdFile = $drive->files->create($fileMetadata, [
                            'data' => $encrypted,
                            'mimeType' => 'application/octet-stream',
                            'uploadType' => 'multipart',
                            'fields' => 'id'
                        ]);

                        // Register chunk
                        $stmt = $pdo->prepare("INSERT INTO chunk_registry (master_file_name, master_file_unique_id, chunk_sequence_number, user_id, drive_file_id, chunk_size, encryption_key) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$title, $fileUUID, $index, $target_student['id'], $createdFile->id, strlen($data), $key]);
                        $success_count++;

                    } catch (Exception $e) { continue; }
                }

                if ($success_count > 0) {
                    // Register note in notes_registry with description and validity
                    $stmt = $pdo->prepare("INSERT INTO notes_registry (master_file_unique_id, batch_id, uploaded_by, description, expiry_date, is_visible) VALUES (?, ?, ?, ?, ?, 1)");
                    $stmt->execute([$fileUUID, $batch_id, $user_id, $desc, $validity]);
                    header("Location: notes_management.php?status=UploadSuccess&batch_id=$batch_id");
                    exit;
                }
            }
        }
    }
}

// 5. DFS Assembly & Download Logic
if ($action === 'download_note') {
    $fileUUID = $_GET['uuid'] ?? null;
    $stmt = $pdo->prepare("SELECT cr.*, u.google_refresh_token FROM chunk_registry cr JOIN users u ON cr.user_id = u.id WHERE cr.master_file_unique_id = ? ORDER BY cr.chunk_sequence_number ASC");
    $stmt->execute([$fileUUID]);
    $chunks_metadata = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($chunks_metadata)) {
        $masterFileName = $chunks_metadata[0]['master_file_name'];
        $assembled_chunks = [];
        $client_template = new Google_Client();
        $client_template->setClientId(GOOGLE_CLIENT_ID);
        $client_template->setClientSecret(GOOGLE_CLIENT_SECRET);

        foreach ($chunks_metadata as $chunk) {
            try {
                $client_user = clone $client_template;
                $client_user->fetchAccessTokenWithRefreshToken($chunk['google_refresh_token']);
                $service_user = new \Google\Service\Drive($client_user);
                $response = $service_user->files->get($chunk['drive_file_id'], ['alt' => 'media']);
                $decrypted = decrypt_chunk((string)$response->getBody(), $chunk['encryption_key']);
                $assembled_chunks[$chunk['chunk_sequence_number']] = $decrypted;
            } catch (Exception $e) { continue; }
        }
        ksort($assembled_chunks);
        $assembledContent = implode('', $assembled_chunks);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="Note_' . $masterFileName . '"');
        echo $assembledContent;
        exit;
    }
}

// --- VIEW DATA PREPARATION ---
$batches = [];
$notes = [];
if ($is_admin) {
    // Admins see all batches in dropdown
    $batches = $pdo->query("SELECT id, batch_name FROM batches ORDER BY batch_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    
    // Base SQL for Admin Notes (All records by default)
    $sql = "SELECT n.*, cr.master_file_name, u.first_name as uploader_name, b.batch_name 
            FROM notes_registry n 
            JOIN (SELECT DISTINCT master_file_unique_id, master_file_name FROM chunk_registry) cr ON n.master_file_unique_id = cr.master_file_unique_id 
            JOIN users u ON n.uploaded_by = u.id
            JOIN batches b ON n.batch_id = b.id";
    
    if ($selected_batch) {
        $stmt = $pdo->prepare($sql . " WHERE n.batch_id = ? ORDER BY n.upload_date DESC");
        $stmt->execute([$selected_batch]);
    } else {
        // Default: Show all available notes
        $stmt = $pdo->query($sql . " ORDER BY n.upload_date DESC");
    }
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

} else {
    // Students only see their enrolled batches in dropdown
    $stmt = $pdo->prepare("SELECT b.id, b.batch_name FROM batches b JOIN batch_students bs ON b.id = bs.batch_id WHERE bs.user_id = ?");
    $stmt->execute([$user_id]);
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Base SQL for Student Notes (Always restricted to enrolled batches)
    $sql = "SELECT n.*, cr.master_file_name, u.first_name as uploader_name, b.batch_name 
            FROM notes_registry n 
            JOIN (SELECT DISTINCT master_file_unique_id, master_file_name FROM chunk_registry) cr ON n.master_file_unique_id = cr.master_file_unique_id 
            JOIN users u ON n.uploaded_by = u.id
            JOIN batches b ON n.batch_id = b.id
            JOIN batch_students bs ON n.batch_id = bs.batch_id
            WHERE bs.user_id = :user_id 
            AND n.is_visible = 1 
            AND (n.expiry_date IS NULL OR n.expiry_date > NOW())";
            
    if ($selected_batch) {
        $stmt = $pdo->prepare($sql . " AND n.batch_id = :batch_id ORDER BY n.upload_date DESC");
        $stmt->execute(['user_id' => $user_id, 'batch_id' => $selected_batch]);
    } else {
        // Default: Show all notes from all batches the student is enrolled in
        $stmt = $pdo->prepare($sql . " ORDER BY n.upload_date DESC");
        $stmt->execute(['user_id' => $user_id]);
    }
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notes Management | Coaching App</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap');
        :root { --bg-primary: #f8f8f8; --bg-secondary: #ffffff; --text-color: #34495e; --border-color: #d3dce0; --accent: #4f46e5; }
        html[data-theme='night'] { --bg-primary: #2c3e50; --bg-secondary: #34495e; --text-color: #ecf0f1; --border-color: #556080; --accent: #818cf8; }
        body { background-color: var(--bg-primary); color: var(--text-color); font-family: 'Plus Jakarta Sans', sans-serif; transition: all 0.3s ease; }
        .glass { background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 2rem; box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.05); }
        .input-style { background: var(--bg-primary); border: 1px solid var(--border-color); color: var(--text-color); }
        .btn-primary { background-color: var(--accent); color: white; }
    </style>
</head>
<body class="min-h-screen p-4 lg:p-10">

    <div class="max-w-7xl mx-auto no-print">
        <header class="flex justify-between items-center mb-8 px-4">
            <div>
                <h1 class="text-3xl font-black tracking-tight">Notes Library</h1>
                <p class="text-sm opacity-60">Distributed Resource Management</p>
            </div>
            <div class="flex items-center gap-3">
                <a href="<?= $is_admin ? 'admin_dashboard.php' : 'profile.php' ?>" class="glass px-5 py-2 rounded-xl text-xs font-bold hover:bg-black/5 flex items-center gap-2 transition">
                    <i class="fas fa-arrow-left"></i> <?= $is_admin ? 'Admin Dashboard' : 'Dashboard' ?>
                </a>
                <button id="themeToggle" class="glass w-12 h-12 rounded-2xl flex items-center justify-center text-xl shadow-sm">
                    <i class="fas <?= $theme === 'night' ? 'fa-sun text-yellow-400' : 'fa-moon text-indigo-600' ?>"></i>
                </button>
            </div>
        </header>

        <?php if ($status_msg): ?>
            <div class="mx-4 mb-6 p-4 rounded-2xl text-sm font-bold bg-indigo-500/10 text-indigo-500 border border-indigo-500/20">
                <i class="fas fa-info-circle mr-2"></i> > <?php echo htmlspecialchars($status_msg); ?>
            </div>
        <?php endif; ?>

        <?php if ($is_admin): ?>
            <!-- ADMIN UPLOAD SECTION -->
            <section class="mb-12">
                <div class="glass p-8 shadow-xl mx-4">
                    <h2 class="text-xl font-black mb-6 flex items-center gap-2"><i class="fas fa-cloud-upload-alt text-indigo-500"></i> Upload & Distribute Notes</h2>
                    <form action="notes_management.php" method="POST" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="space-y-4">
                            <div>
                                <label class="text-[10px] uppercase font-black opacity-40 ml-1">Target Batch</label>
                                <select name="target_batch" required class="input-style w-full px-4 py-3 rounded-xl mt-1 outline-none text-sm">
                                    <?php foreach ($batches as $b): ?>
                                        <option value="<?php echo $b['id']; ?>" <?php echo $selected_batch == $b['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($b['batch_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] uppercase font-black opacity-40 ml-1">Note Title</label>
                                <input type="text" name="noteTitle" placeholder="e.g. Advanced Java Patterns" required class="input-style w-full px-4 py-3 rounded-xl mt-1 outline-none text-sm">
                            </div>
                            <div>
                                <label class="text-[10px] uppercase font-black opacity-40 ml-1">Description</label>
                                <textarea name="noteDesc" placeholder="Brief overview of contents..." rows="2" class="input-style w-full px-4 py-3 rounded-xl mt-1 outline-none text-sm"></textarea>
                            </div>
                        </div>
                        <div class="space-y-4">
                            <div>
                                <label class="text-[10px] uppercase font-black opacity-40 ml-1">Validity (Expiry)</label>
                                <input type="datetime-local" name="validityDate" class="input-style w-full px-4 py-3 rounded-xl mt-1 outline-none text-sm">
                            </div>
                            <div>
                                <label class="text-[10px] uppercase font-black opacity-40 ml-1">File Source</label>
                                <input type="file" name="noteFile" required class="input-style w-full px-4 py-3 rounded-xl mt-1 outline-none text-sm file:mr-4 file:py-1 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-black file:bg-indigo-500 file:text-white">
                            </div>
                            <div class="pt-2">
                                <button type="submit" class="btn-primary w-full py-4 rounded-2xl shadow-xl font-bold uppercase tracking-widest text-xs transition active:scale-95">
                                    Deploy Distributed Note
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </section>
        <?php endif; ?>

        <!-- MANAGEMENT SECTION -->
        <section class="space-y-6">
            <div class="flex flex-col md:flex-row justify-between items-center px-4 gap-4">
                <h2 class="text-xl font-black uppercase tracking-tighter flex items-center gap-2">
                    <i class="fas fa-folder-open text-indigo-500"></i> <?= $selected_batch ? 'Batch Library' : 'Full Library' ?>
                </h2>
                <form method="GET" class="flex gap-2">
                    <select name="batch_id" onchange="this.form.submit()" class="input-style px-6 py-2 rounded-xl outline-none min-w-[200px] text-sm font-bold">
                        <option value="">All Available Batches</option>
                        <?php foreach ($batches as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo $selected_batch == $b['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($b['batch_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <?php if ($is_admin && $selected_batch): ?>
                <div class="flex gap-3 px-4 mb-4">
                    <a href="notes_management.php?action=batch_bulk_action&type=enable_all&batch_id=<?php echo $selected_batch; ?>" class="glass px-4 py-2 rounded-xl text-[10px] font-black uppercase text-green-500 hover:bg-green-500 hover:text-white transition">Enable All</a>
                    <a href="notes_management.php?action=batch_bulk_action&type=disable_all&batch_id=<?php echo $selected_batch; ?>" class="glass px-4 py-2 rounded-xl text-[10px] font-black uppercase text-orange-500 hover:bg-orange-500 hover:text-white transition">Disable All</a>
                    <a href="javascript:void(0)" onclick="if(confirm('Delete ALL metadata for this batch?')) window.location.href='notes_management.php?action=batch_bulk_action&type=delete_all&batch_id=<?php echo $selected_batch; ?>'" class="glass px-4 py-2 rounded-xl text-[10px] font-black uppercase text-red-500 hover:bg-red-500 hover:text-white transition">Wipe Batch</a>
                </div>
            <?php endif; ?>

            <div class="glass overflow-x-auto shadow-xl mx-4">
                <table class="w-full text-left">
                    <thead>
                        <tr class="border-b border-gray-500/10 text-[10px] uppercase opacity-40 font-black">
                            <th class="py-4 px-6">Document Info</th>
                            <th class="py-4 text-center">Validity & Remaining</th>
                            <?php if ($is_admin): ?><th class="py-4 text-center">Visibility</th><?php endif; ?>
                            <th class="py-4 px-6 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-500/5">
                        <?php if (empty($notes)): ?>
                            <tr><td colspan="4" class="py-20 text-center opacity-30 italic">No notes found for current selection.</td></tr>
                        <?php else: ?>
                            <?php foreach ($notes as $note): 
                                $timeRemaining = getTimeRemaining($note['expiry_date']);
                                $is_expired = ($note['expiry_date'] && strtotime($note['expiry_date']) < time());
                            ?>
                            <tr class="group hover:bg-gray-500/5 transition">
                                <td class="py-4 px-6">
                                    <div class="font-bold text-sm"><?= htmlspecialchars($note['master_file_name']) ?></div>
                                    <div class="text-[10px] font-black text-indigo-500 uppercase"><?= htmlspecialchars($note['batch_name']) ?></div>
                                    <div class="text-[10px] opacity-60 max-w-xs truncate"><?= htmlspecialchars($note['description'] ?? 'No description provided') ?></div>
                                    <div class="text-[9px] font-black opacity-30 uppercase mt-1">Uploaded: <?= date('d M Y', strtotime($note['upload_date'])) ?></div>
                                </td>
                                <td class="py-4 text-center">
                                    <?php if ($note['expiry_date']): ?>
                                        <div class="flex flex-col items-center gap-1">
                                            <span class="px-3 py-1 rounded-full text-[9px] font-black uppercase <?= $is_expired ? 'bg-red-500/10 text-red-500' : 'bg-indigo-500/10 text-indigo-500' ?>">
                                                <?= date('d M Y, h:i A', strtotime($note['expiry_date'])) ?>
                                            </span>
                                            <?php if ($timeRemaining): ?>
                                                <span class="text-[9px] font-bold <?= $is_expired ? 'text-red-400' : 'text-green-500 animate-pulse' ?>">
                                                    <i class="fas fa-clock mr-1"></i> <?= $timeRemaining ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="px-3 py-1 rounded-full text-[9px] font-black uppercase bg-green-500/10 text-green-500">Permanent</span>
                                    <?php endif; ?>
                                </td>
                                <?php if ($is_admin): ?>
                                    <td class="py-4 text-center">
                                        <span class="px-2 py-1 rounded-full text-[9px] font-black uppercase <?= $note['is_visible'] ? 'bg-green-500/10 text-green-500' : 'bg-gray-500/10 text-gray-500' ?>">
                                            <?= $note['is_visible'] ? 'Visible' : 'Hidden' ?>
                                        </span>
                                    </td>
                                <?php endif; ?>
                                <td class="py-4 px-6">
                                    <div class="flex justify-center gap-4">
                                        <a href="notes_management.php?action=download_note&uuid=<?= $note['master_file_unique_id'] ?>&batch_id=<?= $selected_batch ?>" class="text-green-500 text-[10px] font-black uppercase hover:underline transition">
                                            <i class="fas fa-download mr-1"></i> Get
                                        </a>
                                        <?php if ($is_admin): ?>
                                            <a href="notes_management.php?action=toggle_visibility&note_id=<?= $note['note_id'] ?>&status=<?= $note['is_visible'] ? 0 : 1 ?>&batch_id=<?= $selected_batch ?>" class="text-indigo-500 text-[10px] font-black uppercase hover:underline transition">
                                                <i class="fas <?= $note['is_visible'] ? 'fa-eye-slash' : 'fa-eye' ?> mr-1"></i> <?= $note['is_visible'] ? 'Hide' : 'Show' ?>
                                            </a>
                                            <a href="javascript:void(0)" onclick="if(confirm('Delete metadata?')) window.location.href='notes_management.php?action=delete_note&note_id=<?= $note['note_id'] ?>&batch_id=<?= $selected_batch ?>'" class="text-red-500 text-[10px] font-black uppercase hover:underline transition">
                                                <i class="fas fa-trash mr-1"></i> Del
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <script>
        const themeBtn = document.getElementById('themeToggle');
        themeBtn.addEventListener('click', () => {
            const html = document.documentElement;
            const newTheme = html.getAttribute('data-theme') === 'night' ? 'day' : 'night';
            html.setAttribute('data-theme', newTheme);
            document.cookie = `theme=${newTheme}; path=/; max-age=2592000`;
            themeBtn.querySelector('i').className = newTheme === 'night' ? 'fas fa-sun text-yellow-400' : 'fas fa-moon text-indigo-600';
        });
    </script>
</body>
</html>