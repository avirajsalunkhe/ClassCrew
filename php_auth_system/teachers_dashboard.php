<?php
/**
 * Coaching Class Teacher/Faculty Dashboard
 * Professional, high-performance structured design
 * Features: Batch Performance, Homework/Test Management, Attendance, and One-Click Messaging
 */
require_once 'db_config.php';
require_once 'vendor/autoload.php';
require_once 'User.class.php';

// --- CRITICAL: Set Timezone ---
date_default_timezone_set('Asia/Kolkata');

// --- DATABASE INITIALIZATION ---
if (!isset($db)) {
    try {
        $dsn = "mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $db = new PDO($dsn, DB_USERNAME, DB_PASSWORD, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    } catch (PDOException $e) {
        die("Database Connection Error: " . $e->getMessage());
    }
}

// --- AUTHENTICATION & FACULTY CHECK ---
if (!isset($_SESSION['logged_in']) || !isset($_SESSION['user_id'])) { 
    header('Location: index.php?error=access_denied'); 
    exit; 
}

$user_id = $_SESSION['user_id'];
$user_obj = new User();
$current_user = $user_obj->getUserById($user_id);

$is_admin = !empty($current_user['is_admin']);
$is_teacher = !empty($current_user['is_teacher']);

if (!$is_admin && !$is_teacher) {
    header('Location: attendance.php?error=faculty_access_only');
    exit;
}

$theme = $_COOKIE['theme'] ?? 'day';

// --- POST OPERATIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (isset($_POST['post_homework'])) {
        $b_id = $_POST['batch_id'];
        $title = $_POST['title'];
        $desc = $_POST['description'];
        $due = $_POST['due_date'];

        if (!$is_admin) {
            $check = $db->prepare("SELECT id FROM batches WHERE id = ? AND teacher_id = ?");
            $check->execute([$b_id, $user_id]);
            if (!$check->fetch()) die("Unauthorized batch access.");
        }

        $stmt = $db->prepare("INSERT INTO homework (batch_id, title, description, due_date) VALUES (?, ?, ?, ?)");
        $stmt->execute([$b_id, $title, $desc, $due]);
        header("Location: teachers_dashboard.php?batch_id=$b_id&success=hw_posted");
        exit;
    }

    if (isset($_POST['post_test'])) {
        $b_id = $_POST['batch_id'];
        $name = $_POST['test_name'];
        $date = $_POST['test_date'];
        $marks = $_POST['max_marks'];

        if (!$is_admin) {
            $check = $db->prepare("SELECT id FROM batches WHERE id = ? AND teacher_id = ?");
            $check->execute([$b_id, $user_id]);
            if (!$check->fetch()) die("Unauthorized batch access.");
        }

        $stmt = $db->prepare("INSERT INTO tests (batch_id, test_name, test_date, max_marks) VALUES (?, ?, ?, ?)");
        $stmt->execute([$b_id, $name, $date, $marks]);
        header("Location: teachers_dashboard.php?batch_id=$b_id&success=test_posted");
        exit;
    }

    if (isset($_POST['admin_change_status'])) {
        $s_id = $_POST['student_id'];
        $b_id = $_POST['batch_id'];
        $att_date = $_POST['att_date'];
        $new_status = $_POST['new_status'];

        if (!$is_admin) {
            $check = $db->prepare("SELECT id FROM batches WHERE id = ? AND teacher_id = ?");
            $check->execute([$b_id, $user_id]);
            if (!$check->fetch()) die("Unauthorized batch access.");
        }

        $stmt = $db->prepare("SELECT id FROM attendance WHERE user_id = ? AND batch_id = ? AND attendance_date = ?");
        $stmt->execute([$s_id, $b_id, $att_date]);
        $existing = $stmt->fetch();

        if ($existing) {
            $stmt = $db->prepare("UPDATE attendance SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $existing['id']]);
        } else {
            $stmt = $db->prepare("INSERT INTO attendance (batch_id, user_id, attendance_date, status) VALUES (?, ?, ?, ?)");
            $stmt->execute([$b_id, $s_id, $att_date, $new_status]);
        }
        header("Location: teachers_dashboard.php?batch_id=$b_id&date=$att_date&success=status_updated");
        exit;
    }
}

// --- DATA FETCHING ---
if ($is_admin) {
    $batches = $db->query("SELECT * FROM batches ORDER BY batch_name ASC")->fetchAll();
} else {
    $stmt = $db->prepare("SELECT * FROM batches WHERE teacher_id = ? ORDER BY batch_name ASC");
    $stmt->execute([$user_id]);
    $batches = $stmt->fetchAll();
}

$selected_batch = $_GET['batch_id'] ?? ($batches[0]['id'] ?? null);
$selected_date = $_GET['date'] ?? date('Y-m-d');

$attendance_records = [];
$active_batch_data = null;
$stats = ['total' => 0, 'present' => 0, 'proxy' => 0, 'absent' => 0, 'avg_att' => 0];

if ($selected_batch) {
    foreach($batches as $b) { if($b['id'] == $selected_batch) $active_batch_data = $b; }

    $query_fields = "u.id, u.first_name, u.last_name, u.email, a.status, a.created_at";
    try {
        $check_phone = $db->query("SHOW COLUMNS FROM users LIKE 'phone'")->fetch();
        if ($check_phone) $query_fields .= ", u.phone";
    } catch (Exception $e) {}

    $stmt = $db->prepare("
        SELECT $query_fields
        FROM batch_students bs
        JOIN users u ON bs.user_id = u.id
        LEFT JOIN attendance a ON a.user_id = u.id AND a.batch_id = bs.batch_id AND a.attendance_date = ?
        WHERE bs.batch_id = ?
        ORDER BY u.first_name ASC
    ");
    $stmt->execute([$selected_date, $selected_batch]);
    $attendance_records = $stmt->fetchAll();

    $stats['total'] = count($attendance_records);
    foreach($attendance_records as $ar) {
        if ($ar['status'] == 'Present') $stats['present']++;
        elseif ($ar['status'] == 'Proxy') $stats['proxy']++;
        elseif ($ar['status'] == 'Absent') $stats['absent']++;
    }
    
    // Average Attendance Calculation (simplified)
    if($stats['total'] > 0) {
        $stats['avg_att'] = round((($stats['present'] + ($stats['proxy'] * 0.5)) / $stats['total']) * 100);
    }

    try {
        $hw_stmt = $db->prepare("SELECT * FROM homework WHERE batch_id = ? ORDER BY created_at DESC LIMIT 5");
        $hw_stmt->execute([$selected_batch]);
        $recent_hw = $hw_stmt->fetchAll();

        $test_stmt = $db->prepare("SELECT * FROM tests WHERE batch_id = ? ORDER BY test_date DESC LIMIT 5");
        $test_stmt->execute([$selected_batch]);
        $recent_tests = $test_stmt->fetchAll();
    } catch (Exception $e) { $recent_hw = []; $recent_tests = []; }
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Command Center | Coaching Hub</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap');
        
        :root {
            --bg-primary: #f1f5f9;
            --bg-secondary: #ffffff;
            --text-main: #0f172a;
            --text-dim: #64748b;
            --border: #e2e8f0;
            --accent: #4f46e5;
            --accent-soft: #eef2ff;
        }

        html[data-theme='night'] {
            --bg-primary: #020617;
            --bg-secondary: #111827;
            --text-main: #f1f5f9;
            --text-dim: #94a3b8;
            --border: #1f2937;
            --accent: #818cf8;
            --accent-soft: #1e1b4b;
        }

        body { 
            background-color: var(--bg-primary); 
            color: var(--text-main); 
            font-family: 'Plus Jakarta Sans', sans-serif;
            -webkit-font-smoothing: antialiased;
            transition: background-color 0.2s, color 0.2s;
        }

        .dashboard-card { 
            background: var(--bg-secondary); 
            border: 1px solid var(--border); 
            border-radius: 1.25rem;
            transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s;
        }

        .input-style { 
            background: var(--bg-primary); 
            border: 1px solid var(--border); 
            color: var(--text-main); 
            border-radius: 0.75rem;
        }

        .input-style:focus {
            border-color: var(--accent);
            outline: none;
            box-shadow: 0 0 0 3px var(--accent-soft);
        }

        .btn-indigo { 
            background-color: var(--accent); 
            color: white; 
            border-radius: 0.75rem; 
            font-weight: 700;
            transition: all 0.15s ease-in-out;
        }
        
        .btn-indigo:hover { opacity: 0.9; transform: translateY(-1px); }
        .btn-indigo:active { transform: translateY(0) scale(0.98); }

        .status-pill { 
            padding: 0.4rem 0.8rem; 
            border-radius: 10px; 
            font-size: 0.7rem; 
            font-weight: 800; 
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .custom-scrollbar::-webkit-scrollbar { width: 5px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: var(--border); border-radius: 10px; }

        .performance-ring {
            background: conic-gradient(var(--accent) var(--p), #e2e8f0 0);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
    </style>
</head>
<body class="min-h-screen pb-12">

    <!-- Header Navigation -->
    <nav class="sticky top-0 z-50 bg-white/80 dark:bg-gray-900/80 backdrop-blur-xl border-b border-gray-200 dark:border-gray-800 px-6 py-4">
        <div class="max-w-7xl mx-auto flex justify-between items-center">
            <div class="flex items-center gap-4">
                <div class="w-11 h-11 bg-indigo-600 rounded-2xl flex items-center justify-center text-white shadow-lg shadow-indigo-500/20">
                    <i class="fas fa-layer-group text-lg"></i>
                </div>
                <div>
                    <h1 class="text-xl font-black tracking-tight uppercase leading-none">Faculty Hub</h1>
                    <p class="text-[10px] font-bold text-indigo-500 uppercase tracking-widest mt-1.5">Control Panel v2.0</p>
                </div>
            </div>
            <div class="flex items-center gap-4">
                <div class="hidden lg:flex items-center gap-6 mr-4 border-r border-gray-200 dark:border-gray-800 pr-6">
                    <a href="attendance.php" class="text-xs font-bold opacity-60 hover:opacity-100 flex items-center gap-2"><i class="fas fa-desktop"></i> Portal</a>
                    <a href="admin_dashboard.php" class="text-xs font-bold opacity-60 hover:opacity-100 flex items-center gap-2"><i class="fas fa-chart-line"></i> Analytics</a>
                </div>
                <button id="themeToggle" class="w-10 h-10 rounded-xl flex items-center justify-center text-lg hover:bg-black/5 dark:hover:bg-white/5 transition">
                    <i class="fas <?= $theme === 'night' ? 'fa-sun text-yellow-400' : 'fa-moon text-indigo-600' ?>"></i>
                </button>
                <div class="flex items-center gap-3 bg-gray-100 dark:bg-gray-800 p-1.5 rounded-2xl">
                    <div class="w-8 h-8 rounded-xl bg-indigo-600 flex items-center justify-center text-white font-bold text-sm">
                        <?= strtoupper(substr($current_user['first_name'], 0, 1)) ?>
                    </div>
                    <div class="pr-3 hidden sm:block">
                        <p class="text-xs font-black leading-none"><?= htmlspecialchars($current_user['first_name']) ?></p>
                        <p class="text-[8px] font-bold opacity-40 uppercase">Faculty</p>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 lg:px-8 mt-10">
        
        <?php if (empty($batches)): ?>
            <div class="dashboard-card p-20 text-center flex flex-col items-center">
                <div class="w-20 h-20 bg-gray-100 dark:bg-gray-800 rounded-3xl flex items-center justify-center mb-6">
                    <i class="fas fa-search-location text-3xl opacity-20"></i>
                </div>
                <h2 class="text-2xl font-black">No Assignment Detected</h2>
                <p class="text-sm text-dim mt-2 max-w-sm">You currently have no active batches assigned to your profile. Please contact the Admin to get set up.</p>
                <a href="attendance.php" class="btn-indigo px-10 py-4 mt-8 shadow-xl shadow-indigo-500/20">Go to Main Portal</a>
            </div>
        <?php else: ?>

        <!-- Integrated Batch Analytics Header -->
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6 mb-10">
            <!-- Main Statistics Card -->
            <div class="lg:col-span-3 dashboard-card p-6 grid grid-cols-2 md:grid-cols-4 gap-6">
                <div class="border-r border-gray-100 dark:border-gray-800 pr-4">
                    <p class="text-[10px] font-bold uppercase text-dim tracking-widest mb-1">Class Strength</p>
                    <h4 class="text-3xl font-black"><?= $stats['total'] ?></h4>
                    <p class="text-[9px] font-bold text-indigo-500 mt-1 uppercase">Students Enrolled</p>
                </div>
                <div class="border-r border-gray-100 dark:border-gray-800 pr-4">
                    <p class="text-[10px] font-bold uppercase text-dim tracking-widest mb-1">Attended Today</p>
                    <h4 class="text-3xl font-black text-green-500"><?= $stats['present'] ?></h4>
                    <p class="text-[9px] font-bold text-green-600 mt-1 uppercase">Physical Presence</p>
                </div>
                <div class="border-r border-gray-100 dark:border-gray-800 pr-4">
                    <p class="text-[10px] font-bold uppercase text-dim tracking-widest mb-1">Proxy Alerts</p>
                    <h4 class="text-3xl font-black text-amber-500"><?= $stats['proxy'] ?></h4>
                    <p class="text-[9px] font-bold text-amber-600 mt-1 uppercase">Awaiting Verify</p>
                </div>
                <div>
                    <p class="text-[10px] font-bold uppercase text-dim tracking-widest mb-1">Missing</p>
                    <h4 class="text-3xl font-black text-rose-500"><?= $stats['absent'] ?></h4>
                    <p class="text-[9px] font-bold text-rose-600 mt-1 uppercase">Unmarked</p>
                </div>
            </div>
            <!-- Performance Circle -->
            <div class="dashboard-card p-6 flex flex-col items-center justify-center relative overflow-hidden">
                <div class="performance-ring w-24 h-24 mb-3" style="--p: <?= $stats['avg_att'] ?>%">
                    <div class="w-20 h-20 bg-white dark:bg-gray-900 rounded-full flex items-center justify-center">
                        <span class="text-xl font-black"><?= $stats['avg_att'] ?>%</span>
                    </div>
                </div>
                <p class="text-[10px] font-black uppercase text-dim tracking-widest">Avg. Attendance</p>
                <div class="absolute -right-4 -bottom-4 opacity-5 pointer-events-none">
                    <i class="fas fa-chart-line text-7xl"></i>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
            <!-- TOOLS PANEL -->
            <div class="lg:col-span-4 space-y-8">
                
                <!-- 1. Selection & Batch Meta -->
                <div class="dashboard-card p-6 bg-slate-900 text-white dark:bg-gray-800 border-none shadow-2xl relative overflow-hidden">
                    <div class="relative z-10">
                        <label class="text-[10px] uppercase font-black opacity-50 tracking-widest">Currently Managing</label>
                        <form method="GET" id="batchForm">
                            <select name="batch_id" onchange="this.form.submit()" class="w-full bg-white/10 border border-white/20 rounded-xl px-4 py-3.5 mt-2 font-black text-lg outline-none focus:ring-2 focus:ring-indigo-500 transition">
                                <?php foreach($batches as $b): ?>
                                    <option value="<?= $b['id'] ?>" class="text-slate-900" <?= $selected_batch == $b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['batch_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <div class="mt-6 flex flex-wrap gap-4 text-[10px] font-bold opacity-80 uppercase tracking-tighter">
                            <span class="flex items-center gap-2"><i class="fas fa-calendar-day text-indigo-400"></i> <?= $active_batch_data['days_in_week'] ?></span>
                            <span class="flex items-center gap-2"><i class="fas fa-clock text-indigo-400"></i> <?= date('h:i A', strtotime($active_batch_data['start_time'])) ?></span>
                        </div>
                    </div>
                </div>

                <!-- 2. Dual Action Forms -->
                <div class="dashboard-card overflow-hidden">
                    <div class="flex border-b border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/30">
                        <button onclick="switchTab('hw')" id="btn-hw" class="flex-1 py-4 text-[11px] font-black uppercase tracking-widest border-b-2 border-indigo-600 text-indigo-600 transition">Assignment</button>
                        <button onclick="switchTab('test')" id="btn-test" class="flex-1 py-4 text-[11px] font-black uppercase tracking-widest border-b-2 border-transparent opacity-40 hover:opacity-100 transition">Schedule Test</button>
                    </div>

                    <div class="p-6">
                        <div id="pane-hw">
                            <form action="teachers_dashboard.php" method="POST" class="space-y-4">
                                <input type="hidden" name="post_homework" value="1">
                                <input type="hidden" name="batch_id" value="<?= $selected_batch ?>">
                                <input type="text" name="title" required placeholder="Lesson Topic (e.g. Algebra Intro)" class="input-style w-full px-4 py-3 text-sm font-semibold">
                                <textarea name="description" placeholder="Task descriptions for students..." class="input-style w-full px-4 py-3 text-sm h-32"></textarea>
                                <div>
                                    <label class="text-[10px] font-black uppercase text-dim ml-1 mb-1.5 block">Deadline Date</label>
                                    <input type="date" name="due_date" class="input-style w-full px-4 py-3 text-sm font-bold">
                                </div>
                                <button type="submit" class="btn-indigo w-full py-4 text-xs uppercase tracking-widest shadow-lg shadow-indigo-500/10">Publish to Batch</button>
                            </form>
                        </div>

                        <div id="pane-test" class="hidden">
                            <form action="teachers_dashboard.php" method="POST" class="space-y-4">
                                <input type="hidden" name="post_test" value="1">
                                <input type="hidden" name="batch_id" value="<?= $selected_batch ?>">
                                <input type="text" name="test_name" required placeholder="Assessment Name" class="input-style w-full px-4 py-3 text-sm font-semibold">
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="text-[10px] font-black uppercase text-dim ml-1 mb-1.5 block">Exam Date</label>
                                        <input type="date" name="test_date" required class="input-style w-full px-4 py-3 text-sm font-bold">
                                    </div>
                                    <div>
                                        <label class="text-[10px] font-black uppercase text-dim ml-1 mb-1.5 block">Weightage</label>
                                        <input type="number" name="max_marks" placeholder="100" class="input-style w-full px-4 py-3 text-sm font-bold">
                                    </div>
                                </div>
                                <button type="submit" class="btn-indigo w-full py-4 text-xs uppercase tracking-widest shadow-lg shadow-indigo-500/10">Announce Test</button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- 3. Timeline Feed -->
                <div class="dashboard-card p-6">
                    <h3 class="text-xs font-black uppercase text-dim mb-6 tracking-widest border-b border-gray-50 pb-4 dark:border-gray-800">Recent Stream</h3>
                    <div class="space-y-5 max-h-[400px] overflow-y-auto custom-scrollbar pr-2">
                        <?php 
                        $stream = array_merge($recent_hw, $recent_tests);
                        usort($stream, function($a, $b) { return strtotime($b['created_at']) - strtotime($a['created_at']); });
                        
                        foreach($stream as $item): 
                            $isHw = isset($item['due_date']);
                        ?>
                            <div class="flex gap-4 group">
                                <div class="relative flex flex-col items-center">
                                    <div class="w-8 h-8 rounded-xl <?= $isHw ? 'bg-indigo-500/10 text-indigo-500' : 'bg-rose-500/10 text-rose-500' ?> flex items-center justify-center text-xs">
                                        <i class="fas <?= $isHw ? 'fa-book-bookmark' : 'fa-stopwatch' ?>"></i>
                                    </div>
                                    <div class="w-0.5 h-full bg-gray-100 dark:bg-gray-800 mt-2"></div>
                                </div>
                                <div class="flex-1 pb-6">
                                    <div class="flex justify-between items-center mb-1">
                                        <span class="text-[9px] font-black uppercase opacity-40"><?= date('d M, h:i A', strtotime($item['created_at'])) ?></span>
                                        <span class="status-pill <?= $isHw ? 'bg-indigo-500/10 text-indigo-600' : 'bg-rose-500/10 text-rose-600' ?>">
                                            <?= $isHw ? 'H.W.' : 'Exam' ?>
                                        </span>
                                    </div>
                                    <p class="text-sm font-black tracking-tight"><?= htmlspecialchars($item['title'] ?? $item['test_name']) ?></p>
                                    <p class="text-[10px] font-bold text-dim mt-1 italic"><?= $isHw ? 'Target: '.date('d M', strtotime($item['due_date'])) : 'Date: '.date('d M', strtotime($item['test_date'])) ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if(empty($stream)): ?>
                            <div class="text-center py-10">
                                <p class="text-[10px] font-bold opacity-20 italic uppercase">Activity log empty</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- MAIN CONTENT PANEL -->
            <div class="lg:col-span-8">
                <div class="dashboard-card p-8 min-h-full">
                    <!-- Table Header & Filters -->
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-10 gap-6">
                        <div>
                            <h2 class="text-2xl font-black flex items-center gap-3">
                                Attendance Log
                                <span class="bg-indigo-500/10 text-indigo-500 text-[10px] font-black px-3 py-1 rounded-lg uppercase"><?= date('D, d M') ?></span>
                            </h2>
                            <p class="text-[10px] font-bold text-dim uppercase mt-1 tracking-widest">Verified data for active enrollment</p>
                        </div>
                        <div class="flex items-center gap-4 w-full sm:w-auto">
                            <!-- Student Quick Search -->
                            <div class="relative flex-1 sm:flex-none">
                                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-xs opacity-30"></i>
                                <input type="text" id="stuSearch" placeholder="Find student..." class="input-style pl-9 pr-4 py-2 text-[11px] font-bold w-full sm:w-48 outline-none">
                            </div>
                            <form method="GET">
                                <input type="hidden" name="batch_id" value="<?= $selected_batch ?>">
                                <input type="date" name="date" value="<?= $selected_date ?>" onchange="this.form.submit()" class="input-style px-4 py-2 text-[11px] font-black outline-none border-none shadow-sm cursor-pointer">
                            </form>
                        </div>
                    </div>

                    <!-- Attendance Table -->
                    <div class="overflow-x-auto">
                        <table class="w-full text-left" id="attendanceTable">
                            <thead>
                                <tr class="border-b border-gray-100 dark:border-gray-800 text-[10px] uppercase font-black text-dim tracking-widest">
                                    <th class="py-4 px-4">Student Profile</th>
                                    <th class="py-4 px-2 text-center">Marked Status</th>
                                    <th class="py-4 px-2 text-center">Sync Time</th>
                                    <th class="py-4 px-4 text-center">Reach Out</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50 dark:divide-gray-800/40">
                                <?php foreach($attendance_records as $rec): ?>
                                <tr class="stu-row group hover:bg-indigo-50/30 dark:hover:bg-indigo-900/5 transition-all duration-200">
                                    <td class="py-5 px-4">
                                        <div class="font-black text-sm name-searchable"><?= htmlspecialchars($rec['first_name'].' '.$rec['last_name']) ?></div>
                                        <div class="text-[10px] font-bold opacity-40"><?= $rec['email'] ?></div>
                                    </td>
                                    <td class="py-5 px-2 text-center">
                                        <form action="teachers_dashboard.php" method="POST">
                                            <input type="hidden" name="admin_change_status" value="1">
                                            <input type="hidden" name="student_id" value="<?= $rec['id'] ?>">
                                            <input type="hidden" name="batch_id" value="<?= $selected_batch ?>">
                                            <input type="hidden" name="att_date" value="<?= $selected_date ?>">
                                            <select name="new_status" onchange="this.form.submit()" class="status-pill cursor-pointer border-none bg-opacity-10 appearance-none text-center <?= ($rec['status'] == 'Present' ? 'bg-green-500 text-green-600' : ($rec['status'] == 'Proxy' ? 'bg-amber-500 text-amber-600' : 'bg-rose-500 text-rose-600')) ?>">
                                                <option value="Present" <?= $rec['status'] == 'Present' ? 'selected' : '' ?>>Present</option>
                                                <option value="Absent" <?= (!$rec['status'] || $rec['status'] == 'Absent') ? 'selected' : '' ?>>Absent</option>
                                                <option value="Proxy" <?= $rec['status'] == 'Proxy' ? 'selected' : '' ?>>Proxy</option>
                                            </select>
                                        </form>
                                    </td>
                                    <td class="py-5 px-2 text-center">
                                        <span class="text-[10px] font-bold opacity-50 bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded-lg">
                                            <i class="far fa-clock mr-1"></i> <?= $rec['created_at'] ? date('h:i A', strtotime($rec['created_at'])) : '--:--' ?>
                                        </span>
                                    </td>
                                    <td class="py-5 px-4 text-center">
                                        <?php if(isset($rec['phone']) && !empty($rec['phone'])): ?>
                                            <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $rec['phone']) ?>?text=Dear <?= $rec['first_name'] ?>, updates from faculty regarding <?= urlencode($active_batch_data['batch_name']) ?>. Status: <?= $rec['status'] ?? 'Unmarked' ?>." target="_blank" 
                                               class="w-10 h-10 rounded-2xl bg-green-500 text-white inline-flex items-center justify-center hover:scale-110 shadow-lg shadow-green-500/20 active:scale-95 transition-all">
                                                <i class="fab fa-whatsapp text-lg"></i>
                                            </a>
                                        <?php else: ?>
                                            <div class="w-10 h-10 rounded-2xl bg-gray-100 dark:bg-gray-800 inline-flex items-center justify-center text-gray-300 dark:text-gray-600 cursor-not-allowed">
                                                <i class="fas fa-phone-slash text-sm"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if(empty($attendance_records)): ?>
                        <div class="py-24 text-center opacity-20 flex flex-col items-center">
                            <i class="fas fa-user-clock text-5xl mb-4"></i>
                            <p class="font-black text-lg">No student data available for this batch.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // Efficient Theme Handling
        const themeBtn = document.getElementById('themeToggle');
        themeBtn.addEventListener('click', () => {
            const html = document.documentElement;
            const newTheme = html.getAttribute('data-theme') === 'night' ? 'day' : 'night';
            html.setAttribute('data-theme', newTheme);
            document.cookie = `theme=${newTheme}; path=/; max-age=2592000`;
            themeBtn.innerHTML = newTheme === 'night' ? '<i class="fas fa-sun text-yellow-400"></i>' : '<i class="fas fa-moon text-indigo-600"></i>';
        });

        // Tab Management
        function switchTab(type) {
            const hwPane = document.getElementById('pane-hw');
            const testPane = document.getElementById('pane-test');
            const hwBtn = document.getElementById('btn-hw');
            const testBtn = document.getElementById('btn-test');

            const activeClass = "flex-1 py-4 text-[11px] font-black uppercase tracking-widest border-b-2 border-indigo-600 text-indigo-600";
            const inactiveClass = "flex-1 py-4 text-[11px] font-black uppercase tracking-widest border-b-2 border-transparent opacity-40 hover:opacity-100 transition";

            if (type === 'hw') {
                hwPane.classList.remove('hidden');
                testPane.classList.add('hidden');
                hwBtn.className = activeClass;
                testBtn.className = inactiveClass;
            } else {
                testPane.classList.remove('hidden');
                hwPane.classList.add('hidden');
                testBtn.className = activeClass;
                hwBtn.className = inactiveClass;
            }
        }

        // Real-time Search Filter
        const stuSearch = document.getElementById('stuSearch');
        if (stuSearch) {
            stuSearch.addEventListener('keyup', (e) => {
                const term = e.target.value.toLowerCase();
                const rows = document.querySelectorAll('.stu-row');
                rows.forEach(row => {
                    const name = row.querySelector('.name-searchable').innerText.toLowerCase();
                    row.style.display = name.includes(term) ? '' : 'none';
                });
            });
        }
    </script>
</body>
</html>