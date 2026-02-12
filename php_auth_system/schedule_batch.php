<?php
/**
 * Coaching Class Batch Scheduler
 * Integrated with login_system_db, User.class.php, and db_config.php
 * Supports Admin Management and Student Schedule Views
 */
require_once 'db_config.php';
require_once 'vendor/autoload.php';
require_once 'User.class.php';

// --- DATABASE INITIALIZATION ---
if (!isset($db)) {
    try {
        $dsn = "mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $db = new PDO($dsn, DB_USERNAME, DB_PASSWORD, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        $pdo = $db; 
    } catch (PDOException $e) {
        die("Database Connection Error: " . $e->getMessage());
    }
}

// --- 1. AUTHENTICATION ---
if (!isset($_SESSION['logged_in']) || !isset($_SESSION['user_id'])) { 
    header('Location: index.php?error=access_denied'); 
    exit; 
}

$user_id = $_SESSION['user_id'];
$user_obj = new User();
$current_user = $user_obj->getUserById($user_id);
$is_admin = !empty($current_user['is_admin']);

// --- 2. THEME CONFIGURATION ---
$theme = $_COOKIE['theme'] ?? 'day'; 

// --- 3. DATABASE OPERATIONS (ADMIN ONLY) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_batch' || $action === 'edit_batch') {
        $name = $_POST['batch_name'];
        $start = $_POST['start_time'];
        $end = $_POST['end_time'];
        $days = isset($_POST['days']) ? implode(',', $_POST['days']) : '';
        $students = $_POST['students'] ?? [];
        $teacher_id = !empty($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : null;
        $batch_fee = !empty($_POST['default_fee']) ? (float)$_POST['default_fee'] : 0.00;
        
        $lat = !empty($_POST['latitude']) ? (float)$_POST['latitude'] : null;
        $lng = !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null;

        try {
            $db->beginTransaction();

            if ($action === 'create_batch') {
                $stmt = $db->prepare("INSERT INTO batches (batch_name, start_time, end_time, days_in_week, latitude, longitude, teacher_id, default_fee) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $start, $end, $days, $lat, $lng, $teacher_id, $batch_fee]);
                $batch_id = $db->lastInsertId();
            } else {
                $batch_id = $_POST['batch_id'];
                $stmt = $db->prepare("UPDATE batches SET batch_name=?, start_time=?, end_time=?, days_in_week=?, latitude=?, longitude=?, teacher_id=?, default_fee=? WHERE id=?");
                $stmt->execute([$name, $start, $end, $days, $lat, $lng, $teacher_id, $batch_fee, $batch_id]);
                $db->prepare("DELETE FROM batch_students WHERE batch_id=?")->execute([$batch_id]);
            }

            foreach ($students as $s_id) {
                $stmt = $db->prepare("INSERT INTO batch_students (batch_id, user_id) VALUES (?, ?)");
                $stmt->execute([$batch_id, $s_id]);

                $fee_check = $db->prepare("SELECT id FROM fees WHERE user_id = ? AND batch_id = ?");
                $fee_check->execute([$s_id, $batch_id]);
                
                if (!$fee_check->fetch()) {
                    $fee_stmt = $db->prepare("INSERT INTO fees (user_id, batch_id, total_fee, status) VALUES (?, ?, ?, 'Pending')");
                    $fee_stmt->execute([$s_id, $batch_id, $batch_fee]);
                }
            }
            
            $db->commit();
            header("Location: schedule_batch.php?status=success");
            exit;
        } catch (PDOException $e) {
            $db->rollBack();
            $error_msg = "Database Error: " . $e->getMessage();
        }
    }

    if ($action === 'delete_batch') {
        $batch_id = $_POST['batch_id'];
        try {
            $db->prepare("DELETE FROM batches WHERE id=?")->execute([$batch_id]);
            header("Location: schedule_batch.php?status=deleted");
            exit;
        } catch (PDOException $e) {
            $error_msg = "Delete Error: " . $e->getMessage();
        }
    }
}

// --- 4. DATA FETCHING ---
if ($is_admin) {
    $allStudents = $user_obj->getAllUsers(); 
    $allAdmins = $db->query("SELECT id, first_name, last_name FROM users WHERE is_admin = 1 ORDER BY first_name ASC")->fetchAll();
    $batches = $db->query("SELECT b.*, u.first_name as t_first, u.last_name as t_last FROM batches b LEFT JOIN users u ON b.teacher_id = u.id ORDER BY b.created_at DESC")->fetchAll();
} else {
    // Fetch only batches for the logged-in student
    $stmt = $db->prepare("
        SELECT b.*, u.first_name as t_first, u.last_name as t_last 
        FROM batches b 
        JOIN batch_students bs ON b.id = bs.batch_id 
        LEFT JOIN users u ON b.teacher_id = u.id 
        WHERE bs.user_id = ?
        ORDER BY b.batch_name ASC
    ");
    $stmt->execute([$user_id]);
    $my_batches = $stmt->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $is_admin ? 'Admin Batch Manager' : 'My Schedule' ?> | Coaching App</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap');
        :root { --bg-primary: #f8f8f8; --bg-secondary: #ffffff; --text-color: #34495e; --border-color: #d3dce0; --accent: #4f46e5; }
        html[data-theme='night'] { --bg-primary: #2c3e50; --bg-secondary: #34495e; --text-color: #ecf0f1; --border-color: #556080; --accent: #818cf8; }
        body { background-color: var(--bg-primary); color: var(--text-color); font-family: 'Plus Jakarta Sans', sans-serif; transition: all 0.3s ease; }
        .glass { background: var(--bg-secondary); border: 1px solid var(--border-color); box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.05); }
        .input-style { background: var(--bg-primary); border: 1px solid var(--border-color); color: var(--text-color); }
        .btn-primary { background-color: var(--accent); color: white; }
        .student-enroll-list::-webkit-scrollbar { width: 6px; }
        .student-enroll-list::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 10px; }
        .card-animate { transition: transform 0.3s ease, box-shadow 0.3s ease; }
        .card-animate:hover { transform: translateY(-5px); box-shadow: 0 12px 40px rgba(0,0,0,0.1); }
        .nav-tool-btn { font-size: 10px; font-weight: 800; text-transform: uppercase; padding: 10px 5px; border-radius: 12px; transition: all 0.2s; text-align: center; border: 1px solid var(--border-color); background: var(--bg-primary); color: var(--text-color); opacity: 0.8; }
        .nav-tool-btn:hover { background: var(--accent); color: white; border-color: transparent; opacity: 1; transform: scale(1.05); }
    </style>
</head>
<body class="min-h-screen p-4 lg:p-10">

    <div class="max-w-7xl mx-auto">
        <header class="flex justify-between items-center mb-8 px-4">
            <div>
                <h1 class="text-3xl font-black tracking-tight uppercase"><?= $is_admin ? 'Batch Scheduler' : 'My Batches' ?></h1>
                <p class="text-sm opacity-60"><?= $is_admin ? 'Management Console' : 'Your Enrolled Courses' ?></p>
            </div>
            <div class="flex items-center gap-3">
                <a href="<?= $is_admin ? 'admin_dashboard.php' : 'profile.php' ?>" class="glass px-5 py-2 rounded-xl text-xs font-bold hover:bg-black/5 transition flex items-center gap-2">
                    <i class="fas fa-arrow-left"></i> Dashboard
                </a>
                <button id="themeToggle" class="glass w-12 h-12 rounded-2xl flex items-center justify-center text-xl hover:scale-105 transition shadow-sm">
                    <i class="fas <?= $theme === 'night' ? 'fa-sun text-yellow-400' : 'fa-moon text-indigo-600' ?>"></i>
                </button>
            </div>
        </header>

        <?php if ($is_admin): ?>
            <!-- ADMIN MANAGEMENT VIEW -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
                <div class="lg:col-span-4">
                    <div class="glass p-8 rounded-[2rem] shadow-xl sticky top-10">
                        <h2 id="formTitle" class="text-2xl font-bold mb-6 text-main">Create New Batch</h2>
                        <form action="schedule_batch.php" method="POST" id="batchForm" class="space-y-5">
                            <input type="hidden" name="action" id="formAction" value="create_batch">
                            <input type="hidden" name="batch_id" id="editBatchId" value="">
                            <input type="hidden" name="latitude" id="batch_lat" value="">
                            <input type="hidden" name="longitude" id="batch_lng" value="">

                            <div>
                                <label class="text-[10px] font-bold uppercase tracking-widest opacity-50 ml-1">Batch Name</label>
                                <input type="text" name="batch_name" id="batch_name" required class="input-style w-full px-4 py-3 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none transition mt-1">
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="text-[10px] font-bold uppercase tracking-widest opacity-50 ml-1">Assigned Teacher</label>
                                    <select name="teacher_id" id="teacher_id" class="input-style w-full px-4 py-3 rounded-xl outline-none mt-1">
                                        <option value="">Select Admin</option>
                                        <?php foreach($allAdmins as $adm): ?>
                                            <option value="<?= $adm['id'] ?>"><?= htmlspecialchars($adm['first_name'].' '.$adm['last_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-[10px] font-bold uppercase tracking-widest opacity-50 ml-1">Batch Fee (₹)</label>
                                    <input type="number" name="default_fee" id="default_fee" step="0.01" placeholder="0.00" class="input-style w-full px-4 py-3 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none transition mt-1 text-main">
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div><label class="text-[10px] font-bold uppercase tracking-widest opacity-50 ml-1">Start Time</label><input type="time" name="start_time" id="start_time" required class="input-style w-full px-4 py-3 rounded-xl mt-1"></div>
                                <div><label class="text-[10px] font-bold uppercase tracking-widest opacity-50 ml-1">End Time</label><input type="time" name="end_time" id="end_time" required class="input-style w-full px-4 py-3 rounded-xl mt-1"></div>
                            </div>

                            <div>
                                <label class="text-[10px] font-bold uppercase tracking-widest opacity-50 ml-1 block mb-2 text-main">Schedule Days</label>
                                <div class="flex flex-wrap gap-2">
                                    <?php foreach(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $day): ?>
                                    <label class="flex-1 min-w-[50px] cursor-pointer group">
                                        <input type="checkbox" name="days[]" value="<?= $day ?>" class="hidden peer">
                                        <div class="text-center py-2 rounded-xl border border-dashed border-gray-400 peer-checked:bg-blue-600 peer-checked:border-transparent peer-checked:text-white transition-all text-xs font-bold"><?= $day ?></div>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div>
                                <div class="flex justify-between items-end mb-2 ml-1"><label class="text-[10px] font-bold uppercase tracking-widest opacity-50">Enroll Students</label><span class="text-[10px] font-bold opacity-40 text-main" id="enrollCount">0 Selected</span></div>
                                <input type="text" id="studentSearch" placeholder="Search students..." class="input-style w-full px-4 py-2 rounded-lg text-sm mb-3 outline-none">
                                <div class="student-enroll-list max-h-[250px] overflow-y-auto space-y-1 p-1 bg-black/5 rounded-xl border border-gray-200/10">
                                    <?php foreach($allStudents as $student): 
                                        $fullName = $student['first_name'] . ' ' . $student['last_name'];
                                    ?>
                                    <label class="student-label flex items-center gap-3 p-2 rounded-lg hover:bg-white/10 cursor-pointer transition border border-transparent text-main" data-search="<?= strtolower(htmlspecialchars($fullName . ' ' . $student['email'])) ?>">
                                        <input type="checkbox" name="students[]" value="<?= $student['id'] ?>" class="student-checkbox w-4 h-4 rounded border-gray-300 text-blue-600">
                                        <div class="leading-tight"><div class="text-sm font-semibold"><?= htmlspecialchars($fullName) ?></div><div class="text-[9px] opacity-60 lowercase"><?= htmlspecialchars($student['email']) ?></div></div>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <button type="button" onclick="handleSubmitWithLocation()" id="submitBtn" class="btn-primary w-full font-bold py-4 rounded-2xl shadow-lg transform active:scale-95 transition uppercase tracking-widest text-xs">Create Batch</button>
                            <button type="button" onclick="resetForm()" id="cancelBtn" class="hidden w-full text-center text-xs font-bold text-red-500 mt-2 hover:underline">Cancel Changes</button>
                        </form>
                    </div>
                </div>

                <div class="lg:col-span-8">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <?php if(empty($batches)): ?>
                            <div class="col-span-full py-24 text-center glass rounded-[3rem] opacity-30 text-main"><i class="fas fa-layer-group text-6xl mb-4"></i><p class="text-xl font-bold">No batches currently scheduled</p></div>
                        <?php endif; ?>
                        <?php foreach($batches as $b): 
                            $s_stmt = $db->prepare("SELECT user_id FROM batch_students WHERE batch_id = ?");
                            $s_stmt->execute([$b['id']]);
                            $s_ids = $s_stmt->fetchAll(PDO::FETCH_COLUMN);
                        ?>
                        <div class="glass p-6 rounded-[2.5rem] card-animate relative overflow-hidden group">
                            <div class="flex justify-between items-start mb-4">
                                <div>
                                    <h3 class="text-xl font-black text-main uppercase tracking-tighter"><?= htmlspecialchars($b['batch_name']) ?></h3>
                                    <p class="text-[10px] font-bold text-blue-500 uppercase mt-1">Teacher: <?= htmlspecialchars(($b['t_first'] ?? 'Unassigned') . ' ' . ($b['t_last'] ?? '')) ?></p>
                                    <p class="text-[10px] font-bold text-indigo-500 uppercase">Fee: ₹<?= number_format($b['default_fee'], 2) ?></p>
                                    <div class="inline-flex items-center px-3 py-1 rounded-full bg-blue-500/10 text-blue-500 text-[10px] font-black tracking-widest mt-2 uppercase">
                                        <i class="far fa-clock mr-1"></i> <?= date("h:i A", strtotime($b['start_time'])) ?> - <?= date("h:i A", strtotime($b['end_time'])) ?>
                                    </div>
                                </div>
                                <div class="flex gap-1">
                                    <button onclick="editBatch(<?= htmlspecialchars(json_encode($b)) ?>, <?= htmlspecialchars(json_encode($s_ids)) ?>)" class="w-9 h-9 flex items-center justify-center rounded-xl bg-blue-500/10 text-blue-500 hover:bg-blue-600 hover:text-white transition"><i class="fas fa-edit"></i></button>
                                    <form action="schedule_batch.php" method="POST" onsubmit="return confirm('Delete this batch?')">
                                        <input type="hidden" name="action" value="delete_batch"><input type="hidden" name="batch_id" value="<?= $b['id'] ?>"><button type="submit" class="w-9 h-9 flex items-center justify-center rounded-xl bg-red-500/10 text-red-500 hover:bg-red-600 hover:text-white transition"><i class="fas fa-trash"></i></button>
                                    </form>
                                </div>
                            </div>
                            
                            <!-- QUICK NAVIGATION TOOLS -->
                            <div class="grid grid-cols-4 gap-2 mb-6">
                                <a href="fees_management.php?filter_batch=<?= $b['id'] ?>" class="nav-tool-btn">Fees</a>
                                <a href="attendance.php?batch_id=<?= $b['id'] ?>" class="nav-tool-btn">Attd.</a>
                                <a href="exam_management.php?test_analytics_batch=<?= $b['id'] ?>" class="nav-tool-btn">Test</a>
                                <a href="notes_management.php?batch_id=<?= $b['id'] ?>" class="nav-tool-btn">Notes</a>
                            </div>

                            <div class="flex flex-wrap gap-1 mb-6">
                                <?php foreach(explode(',', $b['days_in_week']) as $day): ?>
                                    <span class="text-[9px] font-bold px-2 py-1 rounded-lg border border-gray-500/20 opacity-60 uppercase tracking-tighter text-main"><?= trim($day) ?></span>
                                <?php endforeach; ?>
                            </div>
                            <div class="flex items-center justify-between pt-5 border-t border-gray-500/10">
                                <div class="flex -space-x-2">
                                    <?php for($i=0; $i<min(count($s_ids), 4); $i++): ?><div class="w-8 h-8 rounded-full bg-indigo-500 border-2 border-white dark:border-slate-800 flex items-center justify-center text-[8px] font-bold text-white shadow-sm">$</div><?php endfor; ?>
                                    <?php if(count($s_ids) > 4): ?><div class="w-8 h-8 rounded-full bg-slate-200 dark:bg-slate-700 border-2 border-white flex items-center justify-center text-[10px] font-bold text-main">+<?= count($s_ids)-4 ?></div><?php endif; ?>
                                </div>
                                <div class="text-right"><div class="text-xs font-black text-main"><?= count($s_ids) ?> Enrolled</div><span class="text-[9px] text-blue-500 font-bold uppercase tracking-wider">Students</span></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- STUDENT SCHEDULE VIEW -->
            <div class="px-4">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                    <?php if(empty($my_batches)): ?>
                        <div class="col-span-full py-32 text-center glass rounded-[3rem] opacity-30 text-main">
                            <i class="fas fa-calendar-times text-7xl mb-6"></i>
                            <p class="text-2xl font-bold tracking-tight">You aren't enrolled in any active batches.</p>
                            <p class="text-sm mt-2 opacity-60">Please contact the administrator for course allocation.</p>
                        </div>
                    <?php endif; ?>

                    <?php foreach($my_batches as $mb): ?>
                        <div class="glass p-8 rounded-[3rem] card-animate border-b-8 border-indigo-500">
                            <div class="flex justify-between items-start mb-6">
                                <div class="w-14 h-14 rounded-2xl bg-indigo-500/10 text-indigo-500 flex items-center justify-center text-2xl shadow-sm">
                                    <i class="fas fa-graduation-cap"></i>
                                </div>
                                <span class="text-[9px] font-black uppercase bg-green-500/10 text-green-500 px-3 py-1 rounded-full border border-green-500/20">Enrolled</span>
                            </div>

                            <h3 class="text-2xl font-black text-main tracking-tight mb-2 uppercase"><?= htmlspecialchars($mb['batch_name']) ?></h3>
                            <div class="space-y-3 mb-8">
                                <p class="flex items-center gap-3 text-sm font-medium opacity-70 text-main">
                                    <i class="fas fa-user-tie text-indigo-500 w-5"></i> 
                                    Teacher: <?= htmlspecialchars(($mb['t_first'] ?? 'Assigning...') . ' ' . ($mb['t_last'] ?? '')) ?>
                                </p>
                                <p class="flex items-center gap-3 text-sm font-medium opacity-70 text-main">
                                    <i class="fas fa-clock text-indigo-500 w-5"></i> 
                                    Time: <?= date("h:i A", strtotime($mb['start_time'])) ?> - <?= date("h:i A", strtotime($mb['end_time'])) ?>
                                </p>
                            </div>

                            <!-- QUICK NAVIGATION TOOLS FOR STUDENTS -->
                            <div class="grid grid-cols-4 gap-2 mb-8">
                                <a href="fees_management.php" class="nav-tool-btn">Fees</a>
                                <a href="attendance.php" class="nav-tool-btn">Attd.</a>
                                <a href="exam_management.php" class="nav-tool-btn">Test</a>
                                <a href="notes_management.php?batch_id=<?= $mb['id'] ?>" class="nav-tool-btn">Notes</a>
                            </div>

                            <div class="flex flex-wrap gap-2 mb-8">
                                <?php foreach(explode(',', $mb['days_in_week']) as $day): ?>
                                    <span class="px-3 py-1 bg-black/5 rounded-xl text-[10px] font-black uppercase text-main tracking-widest border border-gray-500/10">
                                        <?= trim($day) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>

                            <div class="grid grid-cols-2 gap-3 pt-6 border-t border-gray-500/10">
                                <button onclick="alert('Chat system initializing... Feature coming soon!')" class="glass py-3 rounded-2xl text-[9px] font-black uppercase flex items-center justify-center gap-2 hover:bg-indigo-500 hover:text-white transition text-main">
                                    <i class="fas fa-comment-alt text-xs"></i> Teacher
                                </button>
                                <button onclick="alert('Batchmate group chat initializing... Feature coming soon!')" class="glass py-3 rounded-2xl text-[9px] font-black uppercase flex items-center justify-center gap-2 hover:bg-blue-500 hover:text-white transition text-main">
                                    <i class="fas fa-users text-xs"></i> Group
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- ADMIN SCRIPTS (Preserved and Updated) -->
    <?php if ($is_admin): ?>
    <script>
        const searchInput = document.getElementById('studentSearch');
        const studentLabels = document.querySelectorAll('.student-label');
        if(searchInput) {
            searchInput.addEventListener('input', (e) => {
                const term = e.target.value.toLowerCase().trim();
                studentLabels.forEach(label => {
                    const text = label.getAttribute('data-search');
                    label.style.display = text.includes(term) ? 'flex' : 'none';
                });
            });
        }

        function updateEnrollCount() { 
            const countEl = document.getElementById('enrollCount');
            if(countEl) countEl.innerText = `${document.querySelectorAll('.student-checkbox:checked').length} Selected`; 
        }
        document.querySelectorAll('.student-checkbox').forEach(cb => cb.addEventListener('change', updateEnrollCount));

        function handleSubmitWithLocation() {
            const action = document.getElementById('formAction').value;
            const submitBtn = document.getElementById('submitBtn');
            const batchName = document.getElementById('batch_name').value.trim();
            if (!batchName) { alert("Please enter a batch name."); return; }
            if (navigator.geolocation) {
                submitBtn.disabled = true; submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Locating...';
                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        document.getElementById('batch_lat').value = position.coords.latitude;
                        document.getElementById('batch_lng').value = position.coords.longitude;
                        submitBtn.innerHTML = '<i class="fas fa-check mr-2"></i> Ready...';
                        setTimeout(() => { document.getElementById('batchForm').submit(); }, 500);
                    },
                    (error) => { submitBtn.disabled = false; submitBtn.innerText = 'Create Batch'; alert("GPS required for batch location."); },
                    { enableHighAccuracy: true, timeout: 8000, maximumAge: 0 }
                );
            } else { alert("Geolocation not supported."); }
        }

        function editBatch(batch, studentIds) {
            document.getElementById('formTitle').innerText = 'Update ' + batch.batch_name;
            document.getElementById('formAction').value = 'edit_batch';
            document.getElementById('editBatchId').value = batch.id;
            document.getElementById('batch_name').value = batch.batch_name;
            document.getElementById('teacher_id').value = batch.teacher_id || '';
            document.getElementById('default_fee').value = batch.default_fee || '';
            document.getElementById('start_time').value = batch.start_time;
            document.getElementById('end_time').value = batch.end_time;
            document.getElementById('submitBtn').innerText = 'Save Changes';
            document.getElementById('cancelBtn').classList.remove('hidden');
            document.getElementById('batch_lat').value = batch.latitude || '';
            document.getElementById('batch_lng').value = batch.longitude || '';
            const activeDays = batch.days_in_week.split(',').map(d => d.trim());
            document.querySelectorAll('input[name="days[]"]').forEach(cb => { cb.checked = activeDays.includes(cb.value); });
            document.querySelectorAll('input[name="students[]"]').forEach(cb => { cb.checked = studentIds.some(id => id == cb.value); });
            updateEnrollCount();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function resetForm() {
            document.getElementById('batchForm').reset();
            document.getElementById('formTitle').innerText = 'Create New Batch';
            document.getElementById('formAction').value = 'create_batch';
            document.getElementById('submitBtn').innerText = 'Create Batch';
            document.getElementById('cancelBtn').classList.add('hidden');
            document.getElementById('batch_lat').value = '';
            document.getElementById('batch_lng').value = '';
            updateEnrollCount();
        }
    </script>
    <?php endif; ?>

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