<?php
/**
 * Coaching Class GPS Attendance System
 * Dynamically verifies Student location against Admin's Batch location
 * Student View: includes Monthly/Next Month Schedule Calendar
 * Teacher Mode: Restricted management dashboard for assigned batches
 */
require_once 'db_config.php';
require_once 'vendor/autoload.php';
require_once 'User.class.php';

// --- CRITICAL: Set Timezone to ensure "today" is accurate ---
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

// --- AUTHENTICATION ---
if (!isset($_SESSION['logged_in']) || !isset($_SESSION['user_id'])) { 
    header('Location: index.php?error=access_denied'); 
    exit; 
}

$user_id = $_SESSION['user_id'];
$user_obj = new User();
$current_user = $user_obj->getUserById($user_id);

$is_admin = !empty($current_user['is_admin']);
$is_teacher = !empty($current_user['is_teacher']);
// Management role includes Admins and Teachers
$is_management = ($is_admin || $is_teacher);

// REFINED RANGE: 50 meters for stricter exact location matching
$allowed_radius_meters = 50;
$theme = $_COOKIE['theme'] ?? 'day';

// Helper function to calculate distance in PHP
function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 6371000; // meters
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $earth_radius * $c;
}

// --- EXPORT LOGIC (Admin/Teacher Only) ---
if ($is_management && isset($_GET['export_monthly'])) {
    $e_uid = $_GET['student_id'];
    $e_bid = $_GET['batch_id'];
    $e_month = $_GET['month']; // Y-m format

    // Security: Ensure a teacher can only export for their own batch
    if ($is_teacher && !$is_admin) {
        $check = $db->prepare("SELECT id FROM batches WHERE id = ? AND teacher_id = ?");
        $check->execute([$e_bid, $user_id]);
        if (!$check->fetch()) die("Unauthorized export.");
    }

    $stmt = $db->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
    $stmt->execute([$e_uid]);
    $stu_name = $stmt->fetch();
    $filename = "Attendance_" . ($stu_name['first_name'] ?? 'Student') . "_" . $e_month . ".csv";

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Batch Attendance Report', ($stu_name['first_name'] ?? '') . ' ' . ($stu_name['last_name'] ?? ''), 'Month: ' . $e_month]);
    fputcsv($output, ['Date', 'Status', 'Time', 'Latitude', 'Longitude']);

    $stmt = $db->prepare("SELECT attendance_date, status, created_at, latitude, longitude 
                          FROM attendance 
                          WHERE user_id = ? AND batch_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ? 
                          ORDER BY attendance_date ASC");
    $stmt->execute([$e_uid, $e_bid, $e_month]);
    while ($row = $stmt->fetch()) {
        fputcsv($output, [
            $row['attendance_date'], 
            $row['status'], 
            date('h:i A', strtotime($row['created_at'])), 
            $row['latitude'], 
            $row['longitude']
        ]);
    }
    fclose($output);
    exit;
}

// --- POST OPERATIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Admin/Teacher: Manually change status
    if ($is_management && isset($_POST['admin_change_status'])) {
        $s_id = $_POST['student_id'];
        $b_id = $_POST['batch_id'];
        $att_date = $_POST['att_date'];
        $new_status = $_POST['new_status'];

        // Security: Ensure a teacher can only modify their own batch
        if ($is_teacher && !$is_admin) {
            $check = $db->prepare("SELECT id FROM batches WHERE id = ? AND teacher_id = ?");
            $check->execute([$b_id, $user_id]);
            if (!$check->fetch()) die("Unauthorized action.");
        }

        // Check if record exists
        $stmt = $db->prepare("SELECT id FROM attendance WHERE user_id = ? AND batch_id = ? AND attendance_date = ?");
        $stmt->execute([$s_id, $b_id, $att_date]);
        $existing = $stmt->fetch();

        if ($existing) {
            $stmt = $db->prepare("UPDATE attendance SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $existing['id']]);
        } else {
            // Insert manually by management (GPS data will be null)
            $stmt = $db->prepare("INSERT INTO attendance (batch_id, user_id, attendance_date, status) VALUES (?, ?, ?, ?)");
            $stmt->execute([$b_id, $s_id, $att_date, $new_status]);
        }
        header("Location: attendance.php?batch_id=$b_id&date=$att_date&status_updated=1");
        exit;
    }

    // Student: Mark attendance via GPS
    if (isset($_POST['mark_attendance'])) {
        $batch_id = $_POST['batch_id'];
        $stu_lat = (float)$_POST['latitude'];
        $stu_lng = (float)$_POST['longitude'];
        $date = date('Y-m-d');
        $currentDayShort = date('D');

        $stmt = $db->prepare("SELECT latitude, longitude, days_in_week FROM batches WHERE id = ?");
        $stmt->execute([$batch_id]);
        $batch = $stmt->fetch();

        if ($batch && $batch['latitude'] !== null) {
            $scheduledDays = explode(',', $batch['days_in_week']);
            if (!in_array($currentDayShort, $scheduledDays)) {
                $error_msg = "Error: Today is not a scheduled day for this batch.";
            } else {
                $distance = calculateDistance($stu_lat, $stu_lng, (float)$batch['latitude'], (float)$batch['longitude']);
                $status = ($distance <= $allowed_radius_meters) ? 'Present' : 'Proxy';

                try {
                    $stmt = $db->prepare("INSERT INTO attendance (batch_id, user_id, attendance_date, latitude, longitude, status) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$batch_id, $user_id, $date, $stu_lat, $stu_lng, $status]);
                    header("Location: attendance.php?status=" . strtolower($status));
                    exit;
                } catch (PDOException $e) {
                    $error_msg = "Attendance already recorded for this batch today.";
                }
            }
        } else {
            $error_msg = "This batch does not have a set location. Marking attendance is disabled.";
        }
    }
}

// --- DATA FETCHING ---
if ($is_management) {
    // Admins see all batches, Teachers see only assigned ones
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
    $is_scheduled_today = true;

    if ($selected_batch) {
        $selected_batch_data = null;
        foreach($batches as $b) { if($b['id'] == $selected_batch) $selected_batch_data = $b; }
        
        if($selected_batch_data) {
            $daysArr = explode(',', $selected_batch_data['days_in_week']);
            $is_scheduled_today = in_array(date('D', strtotime($selected_date)), $daysArr);
        }

        $stmt = $db->prepare("
            SELECT u.id, u.first_name, u.last_name, u.email, a.status, a.created_at, a.latitude, a.longitude
            FROM batch_students bs
            JOIN users u ON bs.user_id = u.id
            LEFT JOIN attendance a ON a.user_id = u.id AND a.batch_id = bs.batch_id AND a.attendance_date = ?
            WHERE bs.batch_id = ?
            ORDER BY u.first_name ASC
        ");
        $stmt->execute([$selected_date, $selected_batch]);
        $attendance_records = $stmt->fetchAll();
    }
} else {
    $currentDate = date('Y-m-d');
    // Student: Fetch batches, assigned teacher, and status for TODAY
    $stmt = $db->prepare("
        SELECT b.*, u.first_name as t_first, u.last_name as t_last,
        (SELECT count(*) FROM attendance a WHERE a.batch_id = b.id AND a.user_id = ? AND a.attendance_date = ?) as is_marked,
        (SELECT status FROM attendance a WHERE a.batch_id = b.id AND a.user_id = ? AND a.attendance_date = ? LIMIT 1) as marked_status
        FROM batch_students bs
        JOIN batches b ON bs.batch_id = b.id
        LEFT JOIN users u ON b.teacher_id = u.id
        WHERE bs.user_id = ?
    ");
    $stmt->execute([$user_id, $currentDate, $user_id, $currentDate, $user_id]);
    $student_batches = $stmt->fetchAll();

    // Student: Fetch Attendance History for Calendar
    $stmt = $db->prepare("SELECT attendance_date, status, batch_id FROM attendance WHERE user_id = ? AND attendance_date >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)");
    $stmt->execute([$user_id]);
    $attendance_raw = $stmt->fetchAll();
    $attendance_history = [];
    foreach($attendance_raw as $ar) {
        $attendance_history[$ar['attendance_date']][$ar['batch_id']] = $ar['status'];
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GPS Attendance System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap');
        :root { --bg-primary: #f8f8f8; --bg-secondary: #ffffff; --text-color: #34495e; --border-color: #d3dce0; --header-bg: #e3e9ed; --accent: #4f46e5; }
        html[data-theme='night'] { --bg-primary: #2c3e50; --bg-secondary: #34495e; --text-color: #ecf0f1; --border-color: #556080; --header-bg: #455070; --accent: #818cf8; }
        body { background-color: var(--bg-primary); color: var(--text-color); font-family: 'Plus Jakarta Sans', sans-serif; transition: all 0.3s ease; }
        .glass { background: var(--bg-secondary); border: 1px solid var(--border-color); box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.05); }
        .input-style { background: var(--bg-primary); border: 1px solid var(--border-color); color: var(--text-color); }
        .btn-primary { background-color: var(--accent); color: white; }
        
        /* Calendar Styles */
        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
        .calendar-day { min-height: 80px; transition: all 0.2s; }
        .day-batch-dot { width: 6px; height: 6px; border-radius: 50%; }
    </style>
</head>
<body class="min-h-screen p-4 lg:p-10">

    <div class="max-w-7xl mx-auto">
        <!-- Header -->
        <header class="flex justify-between items-center mb-8 px-4">
            <div>
                <h1 class="text-3xl font-black tracking-tight">GPS Attendance</h1>
                <p class="text-sm opacity-60">
                    <?php 
                        if($is_admin) echo 'Admin Dashboard'; 
                        elseif($is_teacher) echo 'Faculty Portal'; 
                        else echo 'Student Portal'; 
                    ?>
                </p>
            </div>
            <div class="flex items-center gap-3">
                <?php if ($is_admin): ?>
                <a href="fees_management.php" class="glass px-5 py-2 rounded-xl text-xs font-bold hover:bg-black/5 transition flex items-center gap-2">
                    <i class="fas fa-file-invoice-dollar"></i> Fees
                </a>
                <a href="schedule_batch.php" class="glass px-5 py-2 rounded-xl text-xs font-bold hover:bg-black/5 transition flex items-center gap-2">
                    <i class="fas fa-tasks"></i> Schedule Batches
                </a>
                <?php endif; ?>
                <a href="admin_dashboard.php" class="glass px-5 py-2 rounded-xl text-xs font-bold hover:bg-black/5 transition flex items-center gap-2">
                    <i class="fas fa-arrow-left"></i> Dashboard
                </a>
                <button id="themeToggle" class="glass w-12 h-12 rounded-2xl flex items-center justify-center text-xl shadow-sm">
                    <i class="fas <?= $theme === 'night' ? 'fa-sun text-yellow-400' : 'fa-moon text-indigo-600' ?>"></i>
                </button>
            </div>
        </header>

        <!-- Status Alerts -->
        <?php if (isset($_GET['status_updated'])): ?>
            <div class="mx-4 mb-6 p-4 rounded-2xl text-sm font-bold bg-green-500/10 text-green-500 border border-green-500/20">
                <i class="fas fa-check-circle mr-2"></i> Attendance status updated successfully.
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['status'])): ?>
            <div class="mx-4 mb-6 p-4 rounded-2xl text-sm font-bold <?= $_GET['status'] == 'present' ? 'bg-green-500/10 text-green-500 border border-green-500/20' : 'bg-orange-500/10 text-orange-500 border border-orange-500/20' ?>">
                <i class="fas <?= $_GET['status'] == 'present' ? 'fa-check-circle' : 'fa-exclamation-triangle' ?> mr-2"></i> 
                Attendance marked as <?= ucfirst($_GET['status']) ?>.
            </div>
        <?php endif; ?>

        <?php if (isset($error_msg)): ?>
            <div class="mx-4 mb-6 p-4 bg-red-500/10 border border-red-500/20 text-red-500 rounded-2xl text-sm font-bold">
                <i class="fas fa-times-circle mr-2"></i> <?= $error_msg ?>
            </div>
        <?php endif; ?>

        <?php if ($is_management): ?>
            <!-- MANAGEMENT VIEW (Admin/Teacher) -->
            <div class="glass p-8 rounded-[2rem] shadow-xl">
                <div class="flex flex-col md:flex-row justify-between items-center mb-8 gap-4">
                    <div class="flex flex-col">
                        <h2 class="text-2xl font-bold">Attendance Records</h2>
                        <?php if(!$is_scheduled_today && $selected_batch): ?>
                            <span class="text-[10px] font-bold text-orange-500 mt-1 uppercase italic"><i class="fas fa-info-circle"></i> This batch is not scheduled for this date</span>
                        <?php endif; ?>
                    </div>
                    <form class="flex flex-wrap gap-4 items-end">
                        <div>
                            <label class="text-[10px] uppercase opacity-50 ml-1">Batch</label>
                            <select name="batch_id" class="input-style px-4 py-2 rounded-xl outline-none w-full mt-1">
                                <?php foreach($batches as $b): ?>
                                    <option value="<?= $b['id'] ?>" <?= $selected_batch == $b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['batch_name']) ?></option>
                                <?php endforeach; ?>
                                <?php if(empty($batches)): ?><option disabled>No batches assigned</option><?php endif; ?>
                            </select>
                        </div>
                        <div>
                            <label class="text-[10px] uppercase opacity-50 ml-1">Date</label>
                            <input type="date" name="date" value="<?= $selected_date ?>" class="input-style px-4 py-2 rounded-xl outline-none w-full mt-1">
                        </div>
                        <button type="submit" class="btn-primary px-6 py-2 rounded-xl font-bold text-sm transition active:scale-95">Filter</button>
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="border-b border-gray-500/10">
                                <th class="py-4 px-2 text-[10px] font-black uppercase opacity-40">Student Name</th>
                                <th class="py-4 px-2 text-[10px] font-black uppercase opacity-40">Status (Click to Change)</th>
                                <th class="py-4 px-2 text-[10px] font-black uppercase opacity-40">Marked At</th>
                                <th class="py-4 px-2 text-[10px] font-black uppercase opacity-40">GPS Info</th>
                                <th class="py-4 px-2 text-[10px] font-black uppercase opacity-40">Report</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-500/5">
                            <?php foreach($attendance_records as $rec): ?>
                            <tr>
                                <td class="py-4 px-2 font-bold text-sm"><?= htmlspecialchars($rec['first_name'].' '.$rec['last_name']) ?></td>
                                <td class="py-4 px-2">
                                    <form action="attendance.php" method="POST" class="flex items-center">
                                        <input type="hidden" name="admin_change_status" value="1">
                                        <input type="hidden" name="student_id" value="<?= $rec['id'] ?>">
                                        <input type="hidden" name="batch_id" value="<?= $selected_batch ?>">
                                        <input type="hidden" name="att_date" value="<?= $selected_date ?>">
                                        <select name="new_status" onchange="this.form.submit()" class="text-[10px] font-bold uppercase rounded-full px-3 py-1 cursor-pointer outline-none <?= ($rec['status'] == 'Present' ? 'bg-green-500/10 text-green-500' : ($rec['status'] == 'Proxy' ? 'bg-orange-500/10 text-orange-500' : 'bg-red-500/10 text-red-500')) ?>">
                                            <option value="Present" <?= $rec['status'] == 'Present' ? 'selected' : '' ?>>Present</option>
                                            <option value="Absent" <?= (!$rec['status'] || $rec['status'] == 'Absent') ? 'selected' : '' ?>>Absent</option>
                                            <option value="Proxy" <?= $rec['status'] == 'Proxy' ? 'selected' : '' ?>>Proxy</option>
                                        </select>
                                    </form>
                                </td>
                                <td class="py-4 px-2 text-xs font-medium"><?= $rec['created_at'] ? date('h:i A', strtotime($rec['created_at'])) : '-' ?></td>
                                <td class="py-4 px-2">
                                    <?php if($rec['latitude']): ?>
                                        <a href="https://www.google.com/maps?q=<?= $rec['latitude'].','.$rec['longitude'] ?>" target="_blank" class="text-blue-500 hover:underline text-[10px] font-bold uppercase">
                                            <i class="fas fa-map-marker-alt"></i> View Map
                                        </a>
                                    <?php else: ?>
                                        <span class="text-[10px] opacity-30 italic">Manual/None</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-4 px-2">
                                    <a href="attendance.php?export_monthly=1&student_id=<?= $rec['id'] ?>&batch_id=<?= $selected_batch ?>&month=<?= date('Y-m', strtotime($selected_date)) ?>" 
                                       class="text-indigo-500 hover:scale-105 transition flex items-center gap-1 text-[10px] font-bold uppercase">
                                        <i class="fas fa-file-csv"></i> Monthly Sheet
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($attendance_records)): ?>
                                <tr><td colspan="5" class="py-8 text-center opacity-30 italic">No students enrolled in this batch.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php else: ?>
            <!-- STUDENT VIEW -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-12">
                <?php 
                $currentDay = date('D');
                foreach($student_batches as $sb): 
                    $scheduledDaysArr = explode(',', $sb['days_in_week']);
                    $isScheduledToday = in_array($currentDay, $scheduledDaysArr);
                ?>
                <div class="glass p-8 rounded-[2.5rem] relative flex flex-col justify-between">
                    <div>
                        <div class="flex justify-between items-start mb-4">
                            <h3 class="text-2xl font-black leading-tight"><?= htmlspecialchars($sb['batch_name']) ?></h3>
                            <?php if($sb['is_marked']): ?>
                                <span class="bg-green-500 text-white p-2 rounded-xl text-xs"><i class="fas fa-check"></i></span>
                            <?php endif; ?>
                        </div>
                        <p class="text-[10px] font-bold text-blue-500 uppercase mb-2">Teacher: <?= htmlspecialchars(($sb['t_first'] ?? 'Unassigned') . ' ' . ($sb['t_last'] ?? '')) ?></p>
                        <p class="text-[10px] font-black text-indigo-500 uppercase tracking-widest mb-2">
                            <?= date('h:i A', strtotime($sb['start_time'])) ?> - <?= date('h:i A', strtotime($sb['end_time'])) ?>
                        </p>
                        <p class="text-[9px] opacity-40 uppercase mb-6"><?= $sb['days_in_week'] ?></p>
                    </div>

                    <?php if($sb['is_marked']): ?>
                        <div class="p-4 rounded-2xl text-center text-xs font-bold <?= $sb['marked_status'] == 'Present' ? 'bg-green-500/10 text-green-500' : 'bg-orange-500/10 text-orange-500' ?>">
                            <i class="fas fa-check-circle mr-1"></i> Marked as <?= $sb['marked_status'] ?>
                        </div>
                    <?php elseif(!$isScheduledToday): ?>
                        <div class="bg-slate-500/10 text-slate-500 p-4 rounded-2xl text-center text-[10px] font-bold italic">
                            Today your class is not scheduled
                        </div>
                    <?php else: ?>
                        <button onclick="requestLocation(<?= $sb['id'] ?>)" id="btn-<?= $sb['id'] ?>" class="btn-primary w-full py-4 rounded-2xl font-bold shadow-lg transform active:scale-95 transition">
                            <i class="fas fa-map-marker-alt mr-2"></i> Mark Attendance
                        </button>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- STUDENT SCHEDULE CALENDAR VIEW -->
            <div class="glass p-8 rounded-[3rem] shadow-xl">
                <h2 class="text-2xl font-black mb-8 px-4 flex items-center gap-3">
                    <i class="fas fa-calendar-alt text-indigo-500"></i> Attendance & Schedule Calendar
                </h2>
                
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-12">
                    <?php 
                    $months_to_show = [
                        ['month' => date('m'), 'year' => date('Y'), 'label' => date('F Y')],
                        ['month' => date('m', strtotime('+1 month')), 'year' => date('Y', strtotime('+1 month')), 'label' => date('F Y', strtotime('+1 month'))]
                    ];

                    $colors = ['bg-blue-500', 'bg-indigo-500', 'bg-rose-500', 'bg-amber-500', 'bg-emerald-500'];
                    $batch_colors = [];
                    foreach($student_batches as $idx => $sb) { $batch_colors[$sb['id']] = $colors[$idx % count($colors)]; }

                    foreach($months_to_show as $m_data):
                        $first_day = date('w', strtotime($m_data['year'].'-'.$m_data['month'].'-01'));
                        $days_in_month = date('t', strtotime($m_data['year'].'-'.$m_data['month'].'-01'));
                    ?>
                    <div>
                        <h3 class="text-lg font-bold mb-4 px-2 opacity-60"><?= $m_data['label'] ?></h3>
                        <div class="calendar-grid">
                            <?php foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $day_name): ?>
                                <div class="text-[10px] font-black uppercase text-center py-2 opacity-30"><?= $day_name ?></div>
                            <?php endforeach; ?>

                            <?php for($i=0; $i<$first_day; $i++): ?><div></div><?php endfor; ?>

                            <?php for($day=1; $day<=$days_in_month; $day++): 
                                $date_str = sprintf('%04d-%02d-%02d', $m_data['year'], $m_data['month'], $day);
                                $is_today = ($date_str == date('Y-m-d'));
                                $day_of_week = date('D', strtotime($date_str));
                                
                                $scheduled_here = [];
                                foreach($student_batches as $sb) {
                                    if(in_array($day_of_week, explode(',', $sb['days_in_week']))) {
                                        $scheduled_here[] = $sb['id'];
                                    }
                                }

                                $attendance_status = $attendance_history[$date_str] ?? null;
                            ?>
                                <div class="calendar-day glass p-2 rounded-xl relative border <?= $is_today ? 'border-indigo-500 ring-2 ring-indigo-500/20 shadow-lg scale-105 z-10' : 'border-gray-500/5' ?> <?= !empty($scheduled_here) ? 'bg-indigo-500/5' : '' ?>">
                                    <span class="text-xs font-black <?= $is_today ? 'text-indigo-500' : 'opacity-40' ?>"><?= $day ?></span>
                                    
                                    <div class="flex flex-wrap gap-1 mt-1">
                                        <?php foreach($scheduled_here as $bid): ?>
                                            <div class="day-batch-dot <?= $batch_colors[$bid] ?>" title="Batch Scheduled"></div>
                                        <?php endforeach; ?>
                                    </div>

                                    <div class="absolute bottom-1 right-2">
                                        <?php if($attendance_status): ?>
                                            <?php 
                                            $is_any_present = in_array('Present', array_values($attendance_status));
                                            $is_any_proxy = in_array('Proxy', array_values($attendance_status));
                                            ?>
                                            <?php if($is_any_present): ?>
                                                <i class="fas fa-check-circle text-green-500 text-[10px]"></i>
                                            <?php elseif($is_any_proxy): ?>
                                                <i class="fas fa-exclamation-circle text-orange-500 text-[10px]"></i>
                                            <?php endif; ?>
                                        <?php elseif(!empty($scheduled_here) && strtotime($date_str) < strtotime(date('Y-m-d'))): ?>
                                            <div class="w-2 h-2 rounded-full bg-red-500/40"></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Legend -->
                <div class="mt-10 pt-8 border-t border-gray-500/10 flex flex-wrap gap-6 px-4">
                    <div class="flex items-center gap-2 text-[10px] font-bold uppercase opacity-60">
                        <div class="w-3 h-3 rounded-full bg-indigo-500/20 border border-indigo-500"></div> Scheduled Class
                    </div>
                    <div class="flex items-center gap-2 text-[10px] font-bold uppercase opacity-60">
                        <i class="fas fa-check-circle text-green-500"></i> Present
                    </div>
                    <div class="flex items-center gap-2 text-[10px] font-bold uppercase opacity-60">
                        <i class="fas fa-exclamation-circle text-orange-500"></i> Proxy
                    </div>
                    <div class="flex items-center gap-2 text-[10px] font-bold uppercase opacity-60">
                        <div class="w-2 h-2 rounded-full bg-red-500/40"></div> Missed
                    </div>
                    <?php foreach($student_batches as $sb): ?>
                        <div class="flex items-center gap-2 text-[10px] font-bold uppercase opacity-60">
                            <div class="day-batch-dot <?= $batch_colors[$sb['id']] ?>"></div> <?= htmlspecialchars($sb['batch_name']) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Hidden Form for submission -->
            <form id="attendanceForm" action="attendance.php" method="POST" style="display:none;">
                <input type="hidden" name="mark_attendance" value="1">
                <input type="hidden" name="batch_id" id="formBatchId">
                <input type="hidden" name="latitude" id="formLat">
                <input type="hidden" name="longitude" id="formLng">
            </form>
        <?php endif; ?>
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

        function requestLocation(batchId) {
            const btn = document.getElementById('btn-' + batchId);
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Verifying...';

            if (!navigator.geolocation) {
                alert("Geolocation is not supported by your browser.");
                btn.disabled = false;
                return;
            }

            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    document.getElementById('formBatchId').value = batchId;
                    document.getElementById('formLat').value = pos.coords.latitude;
                    document.getElementById('formLng').value = pos.coords.longitude;
                    document.getElementById('attendanceForm').submit();
                },
                (err) => {
                    alert("Unable to get location. Please enable GPS.");
                    btn.disabled = false;
                    btn.innerHTML = 'Mark Attendance';
                },
                { 
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 0
                }
            );
        }
    </script>
</body>
</html>