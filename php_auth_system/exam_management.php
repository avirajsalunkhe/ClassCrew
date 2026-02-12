<?php
// exam_management.php - Advanced Quiz & Coding Engine with Time Windows
require_once 'db_config.php';
require_once 'vendor/autoload.php';
require_once 'User.class.php';

date_default_timezone_set('Asia/Kolkata');

$user_obj = new User();
$pdo = $user_obj->getPdo();
$user_id = $_SESSION['user_id'] ?? null;

if (!isset($_SESSION['logged_in']) || !$user_id) { 
    header('Location: index.php?error=access_denied'); 
    exit; 
}

$current_user = $user_obj->getUserById($user_id);
$is_admin = !empty($current_user['is_admin']);
$theme = $_COOKIE['theme'] ?? 'day';

// Initialize status message
$status_msg = $_GET['status'] ?? "";

/**
 * --- DATABASE SCHEMA REQUIREMENT ---
 * Ensure 'tests' table has: start_time (DATETIME), end_time (DATETIME), is_public (TINYINT), is_active (TINYINT)
 */

// --- HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($is_admin) {
        // ADMIN: Create Test
        if ($action === 'create_test') {
            $batch_id = $_POST['batch_id'];
            $test_name = $_POST['test_name'];
            $max_marks = $_POST['max_marks'];
            $start = $_POST['start_time'];
            $end = $_POST['end_time'];
            
            $stmt = $pdo->prepare("INSERT INTO tests (batch_id, test_name, max_marks, start_time, end_time, is_public, is_active) VALUES (?, ?, ?, ?, ?, 0, 1)");
            $stmt->execute([$batch_id, $test_name, $max_marks, $start, $end]);
            header("Location: exam_management.php?status=TestCreated");
            exit;
        }

        // ADMIN: Update Test
        if ($action === 'update_test') {
            $test_id = $_POST['test_id'];
            $stmt = $pdo->prepare("UPDATE tests SET test_name = ?, max_marks = ?, start_time = ?, end_time = ?, batch_id = ? WHERE id = ?");
            $stmt->execute([$_POST['test_name'], $_POST['max_marks'], $_POST['start_time'], $_POST['end_time'], $_POST['batch_id'], $test_id]);
            header("Location: exam_management.php?status=TestUpdated&test_analytics=$test_id");
            exit;
        }

        // ADMIN: Toggle Status
        if ($action === 'toggle_status') {
            $test_id = $_POST['test_id'];
            $field = $_POST['field']; // 'is_public' or 'is_active'
            $val = $_POST['value'];
            $stmt = $pdo->prepare("UPDATE tests SET $field = ? WHERE id = ?");
            $stmt->execute([$val, $test_id]);
            header("Location: exam_management.php?status=StatusUpdated&test_analytics=$test_id");
            exit;
        }

        // ADMIN: Delete Test
        if ($action === 'delete_test') {
            $test_id = $_POST['test_id'];
            $stmt = $pdo->prepare("DELETE FROM tests WHERE id = ?");
            $stmt->execute([$test_id]);
            header("Location: exam_management.php?status=TestDeleted");
            exit;
        }

        // ADMIN: Create/Update Question
        if ($action === 'save_question') {
            $test_id = $_POST['test_id'];
            $q_id = $_POST['q_id'] ?? null;
            $q_type = $_POST['q_type'];
            $topic = $_POST['topic'];
            $question = $_POST['question'];
            $marks = $_POST['marks'];
            
            $options = ($q_type === 'MCQ') ? json_encode([$_POST['opt0'], $_POST['opt1'], $_POST['opt2'], $_POST['opt3']]) : null;
            $correct = ($q_type === 'MCQ') ? $_POST['correct'] : null;
            $ref_solution = ($q_type === 'CODE') ? $_POST['ref_solution'] : null;

            if ($q_id) {
                $stmt = $pdo->prepare("UPDATE exam_questions SET topic = ?, question_text = ?, options = ?, correct_option = ?, marks = ?, q_type = ?, reference_solution = ? WHERE q_id = ?");
                $stmt->execute([$topic, $question, $options, $correct, $marks, $q_type, $ref_solution, $q_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO exam_questions (test_id, topic, question_text, options, correct_option, marks, q_type, reference_solution) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$test_id, $topic, $question, $options, $correct, $marks, $q_type, $ref_solution]);
            }
            header("Location: exam_management.php?status=QuestionSaved&test_analytics=$test_id");
            exit;
        }

        // ADMIN: Delete Question
        if ($action === 'delete_question') {
            $q_id = $_POST['q_id'];
            $test_id = $_POST['test_id'];
            $stmt = $pdo->prepare("DELETE FROM exam_questions WHERE q_id = ?");
            $stmt->execute([$q_id]);
            header("Location: exam_management.php?status=QuestionDeleted&test_analytics=$test_id");
            exit;
        }
    }

    // STUDENT: Submit Exam
    if (!$is_admin && $action === 'submit_exam') {
        $sub_id = $_POST['submission_id'];
        $test_id = $_POST['test_id'];
        $answers = $_POST['answers'] ?? []; 
        $switches = (int)$_POST['tab_switches'];
        
        $total_score = 0;
        foreach ($answers as $q_id => $val) {
            $q_stmt = $pdo->prepare("SELECT * FROM exam_questions WHERE q_id = ?");
            $q_stmt->execute([$q_id]);
            $q = $q_stmt->fetch();
            
            $is_correct = 0;
            $text_answer = null;
            $chosen_opt = null;

            if ($q['q_type'] === 'MCQ') {
                $chosen_opt = $val;
                // Robust integer comparison
                $is_correct = (isset($val) && (int)$val === (int)$q['correct_option']) ? 1 : 0;
            } else {
                $text_answer = $val;
                // Case-insensitive trimmed logic comparison
                $is_correct = (trim(strtolower($val ?? '')) === trim(strtolower($q['reference_solution'] ?? ''))) ? 1 : 0;
            }

            if ($is_correct) $total_score += (int)$q['marks'];

            $res_stmt = $pdo->prepare("INSERT INTO exam_responses (sub_id, q_id, chosen_option, text_answer, is_correct) VALUES (?, ?, ?, ?, ?)");
            $res_stmt->execute([$sub_id, $q_id, $chosen_opt, $text_answer, $is_correct]);
        }

        $status = ($switches >= 3) ? 'AUTO_SUBMITTED' : 'COMPLETED';
        $upd = $pdo->prepare("UPDATE exam_submissions SET score = ?, tab_switches = ?, status = ?, submitted_at = NOW() WHERE sub_id = ?");
        $upd->execute([$total_score, $switches, $status, $sub_id]);
        
        header("Location: exam_management.php?status=Finished&view_results=$sub_id");
        exit;
    }
}

// --- VIEW PREPARATION ---
$active_test_id = $_GET['take_test'] ?? null;
$view_results_id = $_GET['view_results'] ?? null;
$admin_analytics_id = $_GET['test_analytics'] ?? null;
$edit_q_id = $_GET['edit_question'] ?? null;

// Analytics Helpers
function getTopicHeatmap($pdo, $sub_id) {
    $stmt = $pdo->prepare("SELECT q.topic, SUM(r.is_correct) as correct, COUNT(r.res_id) as total FROM exam_responses r JOIN exam_questions q ON r.q_id = q.q_id WHERE r.sub_id = ? GROUP BY q.topic");
    $stmt->execute([$sub_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Countdown Helper
function renderCountdown($targetDate) {
    if (!$targetDate) return "<span class='font-mono'>--:--:--</span>";
    $id = 'timer_' . uniqid();
    return "
        <span id='$id' class='font-mono'>--:--:--</span>
        <script>
            (function() {
                const target = new Date('$targetDate').getTime();
                const el = document.getElementById('$id');
                const iv = setInterval(function() {
                    const now = new Date().getTime();
                    const diff = target - now;
                    if (diff < 0) { el.innerHTML = 'EXPIRED'; clearInterval(iv); return; }
                    const d = Math.floor(diff / (1000 * 60 * 60 * 24));
                    const h = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                    const m = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                    const s = Math.floor((diff % (1000 * 60)) / 1000);
                    el.innerHTML = (d > 0 ? d + 'd ' : '') + h + 'h ' + m + 'm ' + s + 's';
                }, 1000);
            })();
        </script>
    ";
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Hub | Digital Coaching</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap');
        :root { --bg-primary: #f8f8f8; --bg-secondary: #ffffff; --text-color: #34495e; --border-color: #d3dce0; --accent: #4f46e5; }
        html[data-theme='night'] { --bg-primary: #2c3e50; --bg-secondary: #34495e; --text-color: #ecf0f1; --border-color: #556080; --accent: #818cf8; }
        body { background-color: var(--bg-primary); color: var(--text-color); font-family: 'Plus Jakarta Sans', sans-serif; transition: background 0.3s, color 0.3s; }
        .glass { background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 2rem; box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.05); }
        .input-style { background: var(--bg-primary); border: 1px solid var(--border-color); color: var(--text-color); outline: none; }
        .btn-primary { background-color: var(--accent); color: white; }
        .code-input { font-family: 'Consolas', monospace; background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 8px; width: 100%; border: 1px solid #333; }
        .text-main { color: var(--text-color); }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.5; } 100% { opacity: 1; } }
        .proctor-warning { animation: pulse 1s infinite; }
        .custom-scrollbar::-webkit-scrollbar { width: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: var(--accent); border-radius: 10px; }
    </style>
</head>
<body class="min-h-screen p-4 lg:p-10">

<div class="max-w-7xl mx-auto no-print text-main">
    <!-- HEADER -->
    <header class="flex justify-between items-center mb-8 px-4">
        <div>
            <h1 class="text-3xl font-black tracking-tighter uppercase">Exam Portal</h1>
            <p class="text-sm opacity-60 font-bold uppercase tracking-widest text-main">Coaching Analytics Platform</p>
        </div>
        <div class="flex items-center gap-3">
            <a href="<?= $is_admin ? 'admin_dashboard.php' : 'profile.php' ?>" class="glass px-6 py-2 text-xs font-black uppercase hover:bg-black/5 transition flex items-center gap-2 text-main">
                <i class="fas fa-arrow-left"></i> Dashboard
            </a>
            <button id="themeToggle" class="glass w-12 h-12 rounded-2xl flex items-center justify-center text-xl shadow-sm text-main">
                <i class="fas <?= $theme === 'night' ? 'fa-sun text-yellow-400' : 'fa-moon text-indigo-600' ?>"></i>
            </button>
        </div>
    </header>

    <?php if (!empty($status_msg)): ?>
        <div class="mx-4 mb-6 p-4 rounded-2xl text-xs font-bold bg-indigo-500/10 text-indigo-500 border border-indigo-500/20">
            <i class="fas fa-check-circle mr-2"></i> > <?php echo htmlspecialchars($status_msg); ?>
        </div>
    <?php endif; ?>

    <?php if ($active_test_id && !$is_admin): ?>
        <!-- STUDENT EXAM INTERFACE -->
        <?php
            $stmt = $pdo->prepare("SELECT * FROM tests WHERE id = ? AND is_public = 1 AND is_active = 1");
            $stmt->execute([$active_test_id]);
            $test = $stmt->fetch();

            $now = time();
            $start = $test ? strtotime($test['start_time']) : 0;
            $end = $test ? strtotime($test['end_time']) : 0;

            if (!$test || $now > $end) { 
                echo "<div class='glass p-20 text-center font-bold text-main'><i class='fas fa-lock text-5xl mb-4 block opacity-30'></i> This examination is currently restricted, private, or has expired.</div>"; 
            } else {
                if ($now < $start) {
                    echo "<div class='glass p-20 text-center font-bold text-main'>Test scheduled. Opens in: " . renderCountdown($test['start_time']) . "</div>";
                } else {
                    $q_stmt = $pdo->prepare("SELECT * FROM exam_questions WHERE test_id = ? ORDER BY RAND()");
                    $q_stmt->execute([$active_test_id]);
                    $questions = $q_stmt->fetchAll();

                    $ins = $pdo->prepare("INSERT INTO exam_submissions (test_id, user_id, status) VALUES (?, ?, 'STARTED')");
                    $ins->execute([$active_test_id, $user_id]);
                    $submission_id = $pdo->lastInsertId();
        ?>
            <div class="max-w-4xl mx-auto">
                <div class="glass p-6 mb-6 flex justify-between items-center sticky top-4 z-50 shadow-2xl">
                    <div>
                        <h2 class="font-black text-indigo-500 uppercase"><?= htmlspecialchars($test['test_name']) ?></h2>
                        <p class="text-[10px] font-bold text-red-500 proctor-warning"><i class="fas fa-shield-alt"></i> PROCTORING ACTIVE | Time Left: <?= renderCountdown($test['end_time']) ?></p>
                    </div>
                    <div class="text-right text-main"><p class="text-[10px] font-black opacity-50 uppercase">Switches</p><p class="text-xl font-black" id="v-count">0 / 3</p></div>
                </div>

                <form id="quizForm" action="exam_management.php" method="POST">
                    <input type="hidden" name="action" value="submit_exam">
                    <input type="hidden" name="submission_id" value="<?= $submission_id ?>">
                    <input type="hidden" name="test_id" value="<?= $active_test_id ?>">
                    <input type="hidden" name="tab_switches" id="tab_switches" value="0">

                    <?php foreach ($questions as $idx => $q): ?>
                        <div class="glass p-8 mb-6 shadow-lg">
                            <div class="flex justify-between items-start mb-4">
                                <span class="text-[10px] font-black uppercase bg-indigo-500/10 px-3 py-1 rounded-full text-indigo-500">Q<?= $idx+1 ?> (<?= $q['marks'] ?>m)</span>
                            </div>
                            <h3 class="text-xl font-bold mb-6 text-main"><?= htmlspecialchars($q['question_text']) ?></h3>

                            <?php if ($q['q_type'] === 'MCQ'): $opts = json_decode($q['options'], true); ?>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <?php foreach ($opts as $o_idx => $opt): ?>
                                        <label class="flex items-center p-4 glass hover:bg-indigo-500/5 cursor-pointer border border-transparent transition has-[:checked]:border-indigo-500 text-main">
                                            <input type="radio" name="answers[<?= $q['q_id'] ?>]" value="<?= $o_idx ?>" class="w-4 h-4 text-indigo-600">
                                            <span class="ml-4 font-bold text-sm"><?= htmlspecialchars($opt) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <textarea name="answers[<?= $q['q_id'] ?>]" class="code-input h-48" placeholder="// Write logic here..."></textarea>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <button type="submit" class="w-full btn-primary py-6 rounded-2xl shadow-xl font-black uppercase tracking-widest transition active:scale-95">Complete Exam Attempt</button>
                </form>
            </div>
            <script>
                let v = 0; window.addEventListener('blur', () => { v++; const c = document.getElementById('v-count'); if(c) c.innerText = v + ' / 3'; document.getElementById('tab_switches').value = v; if(v >= 3) document.getElementById('quizForm').submit(); });
            </script>
        <?php } } ?>

    <?php elseif ($view_results_id): ?>
        <!-- RESULT REVIEW (STUDENT/ADMIN) -->
        <?php
            $stmt = $pdo->prepare("SELECT s.*, t.test_name, t.max_marks, t.end_time FROM exam_submissions s JOIN tests t ON s.test_id = t.id WHERE s.sub_id = ?");
            $stmt->execute([$view_results_id]);
            $sub = $stmt->fetch();
            
            if ($sub):
                $expired = (strtotime($sub['end_time']) < time());
                $heatmap = getTopicHeatmap($pdo, $view_results_id);
                $accuracy = ($sub['max_marks'] > 0) ? round(($sub['score'] / $sub['max_marks']) * 100) : 0;
        ?>
        <div class="max-w-4xl mx-auto space-y-8 text-main">
            <div class="flex justify-between items-center px-4">
                <h2 class="text-2xl font-black uppercase text-main"><?= htmlspecialchars($sub['test_name']) ?> Analytics</h2>
                <a href="exam_management.php" class="glass px-4 py-2 text-[10px] font-black uppercase tracking-widest">Back to Dashboard</a>
            </div>

            <?php if (!$expired && !$is_admin): ?>
                <div class="glass p-20 text-center shadow-xl border-dashed border-2 border-indigo-500/20">
                    <i class="fas fa-check-circle text-5xl text-green-500 mb-6 block"></i>
                    <h3 class="text-2xl font-black mb-2 uppercase">Attempt Submitted Successfully</h3>
                    <p class="opacity-60 mb-8">Performance metrics and correct answers are hidden for integrity until the exam window expires.</p>
                    <div class="bg-indigo-500/10 inline-block px-8 py-4 rounded-2xl">
                        <p class="text-[9px] font-black uppercase mb-1">Unlocks In</p>
                        <p class="text-2xl font-black text-indigo-500"><?= renderCountdown($sub['end_time']) ?></p>
                    </div>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="glass p-8 text-center border-b-4 border-indigo-500 shadow-xl">
                        <p class="text-[10px] opacity-50 font-black uppercase">Marks Scored</p>
                        <p class="text-4xl font-black"><?= $sub['score'] ?> / <?= $sub['max_marks'] ?></p>
                    </div>
                    <div class="glass p-8 text-center border-b-4 border-green-500 shadow-xl">
                        <p class="text-[10px] opacity-50 font-black uppercase">Accuracy</p>
                        <p class="text-4xl font-black text-indigo-500"><?= $accuracy ?>%</p>
                    </div>
                    <div class="glass p-8 text-center border-b-4 border-red-500 shadow-xl">
                        <p class="text-[10px] opacity-50 font-black uppercase">Status</p>
                        <p class="text-xl font-black uppercase"><?= $sub['status'] ?></p>
                    </div>
                </div>

                <div class="glass p-8 shadow-2xl">
                    <h2 class="text-xl font-black mb-8 uppercase tracking-tighter">Performance Map</h2>
                    <?php foreach($heatmap as $h): $p = ($h['total'] > 0) ? ($h['correct']/$h['total'])*100 : 0; ?>
                        <div class="mb-4">
                            <div class="flex justify-between text-[10px] font-black uppercase mb-1">
                                <span><?= htmlspecialchars($h['topic']) ?></span>
                                <span><?= round($p) ?>%</span>
                            </div>
                            <div class="h-2 bg-black/10 rounded-full overflow-hidden">
                                <div class="h-full bg-indigo-500 transition-all duration-1000" style="width: <?= $p ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <h2 class="text-xl font-black mb-4 uppercase px-4">Responses Review</h2>
                <?php
                    $stmt = $pdo->prepare("SELECT r.*, q.question_text, q.options, q.correct_option, q.reference_solution, q.q_type FROM exam_responses r JOIN exam_questions q ON r.q_id = q.q_id WHERE r.sub_id = ?");
                    $stmt->execute([$view_results_id]);
                    foreach ($stmt->fetchAll() as $r):
                ?>
                    <div class="glass p-8 mb-6 shadow-xl <?= $r['is_correct'] ? 'border-l-8 border-green-500' : 'border-l-8 border-red-500' ?>">
                        <p class="text-lg font-bold mb-4"><?= htmlspecialchars($r['question_text']) ?></p>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-4">
                            <div class="p-4 bg-black/5 rounded-2xl">
                                <p class="font-black text-indigo-500 uppercase text-[9px] mb-2">Submitted Answer:</p>
                                <p class="text-sm"><?= $r['q_type'] == 'MCQ' ? (json_decode($r['options'], true)[$r['chosen_option']] ?? 'Skipped') : nl2br(htmlspecialchars($r['text_answer'] ?? '')) ?></p>
                            </div>
                            <div class="p-4 bg-green-500/5 rounded-2xl">
                                <p class="font-black text-green-500 uppercase text-[9px] mb-2">Reference Key:</p>
                                <p class="text-sm font-bold"><?= $r['q_type'] == 'MCQ' ? (json_decode($r['options'], true)[$r['correct_option']] ?? 'N/A') : nl2br(htmlspecialchars($r['reference_solution'] ?? '')) ?></p>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php else: ?>
            <div class='glass p-20 text-center font-bold'>Session data not found.</div>
        <?php endif; ?>

    <?php elseif ($is_admin && $admin_analytics_id): ?>
        <!-- ADMIN DETAILED TEST ANALYTICS -->
        <?php
            $stmt = $pdo->prepare("SELECT t.*, b.batch_name FROM tests t JOIN batches b ON t.batch_id = b.id WHERE t.id = ?");
            $stmt->execute([$admin_analytics_id]);
            $test = $stmt->fetch();
            
            if (!$test) {
                echo "<div class='glass p-20 text-center font-bold'>Entry missing. <a href='exam_management.php' class='text-indigo-500 underline'>Return</a></div>";
            } else {
                $qs = $pdo->prepare("SELECT * FROM exam_questions WHERE test_id = ?");
                $qs->execute([$admin_analytics_id]);
                $questions = $qs->fetchAll();
        ?>
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 px-4">
            <div class="lg:col-span-full mb-4 flex justify-between items-center">
                <h2 class="text-3xl font-black uppercase tracking-tighter"><?= htmlspecialchars($test['test_name']) ?> <span class="text-sm opacity-40 font-bold tracking-normal ml-4 text-indigo-500">Target: <?= htmlspecialchars($test['batch_name']) ?></span></h2>
                <div class="flex gap-2">
                    <form action="exam_management.php" method="POST" onsubmit="return confirm('Erase everything?')">
                        <input type="hidden" name="action" value="delete_test"><input type="hidden" name="test_id" value="<?= $test['id'] ?>">
                        <button class="glass px-4 py-2 text-[10px] font-black text-red-500 uppercase hover:bg-red-500 hover:text-white transition">Delete</button>
                    </form>
                    <a href="exam_management.php" class="btn-primary px-6 py-2 text-[10px] font-black uppercase rounded-xl">Back to Hub</a>
                </div>
            </div>

            <!-- SETTINGS PANEL -->
            <div class="lg:col-span-4 space-y-8">
                <div class="glass p-8 shadow-xl">
                    <h2 class="text-xl font-black mb-6 uppercase flex justify-between items-center">Control 
                        <span class="text-[9px] font-black px-3 py-1 rounded-full <?= $test['is_public'] ? 'bg-green-500/10 text-green-500' : 'bg-orange-500/10 text-orange-500' ?>"><?= $test['is_public'] ? 'Public' : 'Private' ?></span>
                    </h2>
                    <form action="exam_management.php" method="POST" class="space-y-4 mb-8">
                        <input type="hidden" name="action" value="update_test"><input type="hidden" name="test_id" value="<?= $test['id'] ?>">
                        <select name="batch_id" class="input-style w-full p-3 rounded-xl text-xs font-bold text-main mt-1">
                            <?php foreach($pdo->query("SELECT id, batch_name FROM batches") as $b): ?>
                                <option value="<?= $b['id'] ?>" <?= $b['id'] == $test['batch_id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['batch_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="test_name" value="<?= htmlspecialchars($test['test_name']) ?>" required class="input-style w-full p-3 rounded-xl text-xs mt-1 shadow-sm">
                        <input type="number" name="max_marks" value="<?= $test['max_marks'] ?>" required class="input-style w-full p-3 rounded-xl text-xs mt-1 shadow-sm">
                        <div class="grid grid-cols-2 gap-4">
                            <div><label class="text-[9px] font-black uppercase opacity-50 ml-1">Starts</label><input type="datetime-local" name="start_time" value="<?= !empty($test['start_time']) ? date('Y-m-d\TH:i', strtotime($test['start_time'])) : '' ?>" required class="input-style w-full p-3 rounded-xl text-xs mt-1"></div>
                            <div><label class="text-[9px] font-black uppercase opacity-50 ml-1">Ends</label><input type="datetime-local" name="end_time" value="<?= !empty($test['end_time']) ? date('Y-m-d\TH:i', strtotime($test['end_time'])) : '' ?>" required class="input-style w-full p-3 rounded-xl text-xs mt-1"></div>
                        </div>
                        <button type="submit" class="btn-primary w-full py-4 rounded-xl font-bold uppercase text-[10px] tracking-widest shadow-lg">Save Settings</button>
                    </form>
                    
                    <div class="flex gap-2">
                        <form action="exam_management.php" method="POST" class="flex-1">
                            <input type="hidden" name="action" value="toggle_status"><input type="hidden" name="test_id" value="<?= $test['id'] ?>">
                            <input type="hidden" name="field" value="is_public"><input type="hidden" name="value" value="<?= $test['is_public'] ? 0 : 1 ?>">
                            <button class="w-full glass py-3 rounded-xl text-[9px] font-black uppercase text-main hover:bg-indigo-500/5 transition"><?= $test['is_public'] ? 'Withdraw' : 'Publish' ?></button>
                        </form>
                        <form action="exam_management.php" method="POST" class="flex-1">
                            <input type="hidden" name="action" value="toggle_status"><input type="hidden" name="test_id" value="<?= $test['id'] ?>">
                            <input type="hidden" name="field" value="is_active"><input type="hidden" name="value" value="<?= $test['is_active'] ? 0 : 1 ?>">
                            <button class="w-full glass py-3 rounded-xl text-[9px] font-black uppercase <?= $test['is_active'] ? 'text-green-500' : 'text-red-500' ?> hover:bg-black/5 transition"><?= $test['is_active'] ? 'Enabled' : 'Disabled' ?></button>
                        </form>
                    </div>
                    <div class="mt-4 p-4 border border-theme rounded-2xl text-center">
                        <p class="text-[9px] font-black uppercase opacity-50">Exam Valid For</p>
                        <div class="text-xl font-black text-indigo-500"><?= renderCountdown($test['end_time']) ?></div>
                    </div>
                </div>

                <div class="glass p-8 shadow-xl">
                    <h2 class="text-xl font-black mb-6 uppercase text-main"><?= $edit_q_id ? 'Edit' : 'Add' ?> Question</h2>
                    <form action="exam_management.php" method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="save_question"><input type="hidden" name="test_id" value="<?= $test['id'] ?>">
                        <?php 
                            $eq = null; if($edit_q_id) { $s = $pdo->prepare("SELECT * FROM exam_questions WHERE q_id = ?"); $s->execute([$edit_q_id]); $eq = $s->fetch(); } 
                            if($eq): ?><input type="hidden" name="q_id" value="<?= $eq['q_id'] ?>"><?php endif; 
                        ?>
                        <select name="q_type" id="admin_q_type" class="input-style w-full p-3 rounded-xl text-xs font-bold text-main shadow-sm">
                            <option value="MCQ" <?= ($eq['q_type'] ?? '') == 'MCQ' ? 'selected' : '' ?>>Multiple Choice</option>
                            <option value="CODE" <?= ($eq['q_type'] ?? '') == 'CODE' ? 'selected' : '' ?>>Coding Logic</option>
                        </select>
                        <input type="text" name="topic" value="<?= htmlspecialchars($eq['topic'] ?? '') ?>" placeholder="Topic Tag" required class="input-style w-full p-3 rounded-xl text-xs shadow-sm">
                        <textarea name="question" required class="input-style w-full p-3 rounded-xl text-xs h-24 shadow-sm" placeholder="Enter logic..."><?= htmlspecialchars($eq['question_text'] ?? '') ?></textarea>
                        <input type="number" name="marks" value="<?= $eq['marks'] ?? '' ?>" required class="input-style w-full p-3 rounded-xl text-xs shadow-sm" placeholder="Marks">
                        <div id="m_f" class="<?= ($eq['q_type'] ?? 'MCQ') == 'CODE' ? 'hidden' : '' ?>">
                            <?php $o = json_decode($eq['options'] ?? '[]', true); ?>
                            <div class="grid grid-cols-2 gap-2"><input type="text" name="opt0" value="<?= htmlspecialchars($o[0] ?? '') ?>" class="input-style p-2 rounded-lg text-[10px] shadow-sm"><input type="text" name="opt1" value="<?= htmlspecialchars($o[1] ?? '') ?>" class="input-style p-2 rounded-lg text-[10px] shadow-sm"><input type="text" name="opt2" value="<?= htmlspecialchars($o[2] ?? '') ?>" class="input-style p-2 rounded-lg text-[10px] shadow-sm"><input type="text" name="opt3" value="<?= htmlspecialchars($o[3] ?? '') ?>" class="input-style p-2 rounded-lg text-[10px] shadow-sm"></div>
                            <input type="number" name="correct" value="<?= $eq['correct_option'] ?? '' ?>" placeholder="Index 0-3" class="input-style w-full mt-2 p-2 rounded-lg text-[10px] text-center shadow-sm">
                        </div>
                        <div id="c_f" class="<?= ($eq['q_type'] ?? 'MCQ') == 'MCQ' ? 'hidden' : '' ?>">
                            <textarea name="ref_solution" class="input-style w-full p-3 rounded-xl text-xs h-32 font-mono shadow-sm" placeholder="Reference Logic"><?= htmlspecialchars($eq['reference_solution'] ?? '') ?></textarea>
                        </div>
                        <button type="submit" class="btn-primary w-full py-4 rounded-xl font-bold uppercase text-[10px] tracking-widest shadow-lg transition active:scale-95"><?= $edit_q_id ? 'Update' : 'Register' ?> Question</button>
                    </form>
                </div>
            </div>

            <!-- ANALYTICS PANEL -->
            <div class="lg:col-span-8 space-y-8">
                <!-- REDESIGNED QUESTION LIST -->
                <div class="glass p-8 shadow-xl">
                    <h2 class="text-xl font-black mb-6 uppercase flex justify-between items-center">Question Questionnaire <span class="text-[10px] opacity-40 font-black"><?= count($questions) ?> Active</span></h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 max-h-[550px] overflow-y-auto pr-2 custom-scrollbar">
                        <?php foreach($questions as $q): ?>
                            <div class="p-5 border-2 border-theme rounded-3xl hover:border-indigo-500/50 transition bg-black/5">
                                <div class="flex justify-between items-start mb-4">
                                    <span class="text-[9px] font-black uppercase px-3 py-1 rounded-full bg-indigo-500 text-white"><?= htmlspecialchars($q['topic']) ?></span>
                                    <span class="text-[9px] font-black text-indigo-500 uppercase"><?= $q['marks'] ?>m</span>
                                </div>
                                <p class="text-sm font-bold text-main mb-6 line-clamp-2"><?= htmlspecialchars($q['question_text']) ?></p>
                                <div class="flex gap-2">
                                    <a href="exam_management.php?test_analytics=<?= $test['id'] ?>&edit_question=<?= $q['q_id'] ?>" class="flex-1 text-center bg-indigo-500/10 text-indigo-500 py-2 rounded-xl text-[9px] font-black uppercase hover:bg-indigo-500 hover:text-white transition">Edit</a>
                                    <form action="exam_management.php" method="POST" class="flex-1" onsubmit="return confirm('Delete question?')"><input type="hidden" name="action" value="delete_question"><input type="hidden" name="q_id" value="<?= $q['q_id'] ?>"><input type="hidden" name="test_id" value="<?= $test['id'] ?>"><button class="w-full text-center bg-red-500/10 text-red-500 py-2 rounded-xl text-[9px] font-black uppercase hover:bg-red-500 hover:text-white transition">Delete</button></form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="glass p-8 shadow-2xl">
                    <h2 class="text-xl font-black mb-6 uppercase tracking-tighter">Enrollment Tracking</h2>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <tr class="border-b border-theme text-[10px] font-black uppercase opacity-40"><th class="py-4">Student</th><th class="py-4 text-center">Attempt</th><th class="py-4 text-center">Marks</th><th class="py-4 text-right">Review</th></tr>
                            <?php 
                                $st_stmt = $pdo->prepare("SELECT u.id, u.first_name, u.last_name FROM batch_students bs JOIN users u ON bs.user_id = u.id WHERE bs.batch_id = ?");
                                $st_stmt->execute([$test['batch_id']]);
                                foreach($st_stmt->fetchAll() as $s): 
                                    $chk = $pdo->prepare("SELECT * FROM exam_submissions WHERE test_id = ? AND user_id = ? AND status != 'STARTED'");
                                    $chk->execute([$test['id'], $s['id']]); $res = $chk->fetch();
                            ?>
                                <tr><td class="py-4 font-bold text-main"><?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?></td><td class="py-4 text-center"><span class="text-[9px] font-black uppercase <?= $res ? 'text-green-500' : 'text-orange-500 animate-pulse' ?>"><?= $res ? 'Completed' : 'Pending' ?></span></td><td class="py-4 text-center font-black text-main"><?= $res ? $res['score'] : '—' ?></td><td class="py-4 text-right"><?php if($res): ?><a href="exam_management.php?view_results=<?= $res['sub_id'] ?>" class="text-[9px] font-black uppercase bg-indigo-500/10 px-4 py-2 rounded-xl text-indigo-500 hover:bg-indigo-500 hover:text-white transition shadow-sm">Report</a><?php endif; ?></td></tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php } ?>

    <?php elseif ($is_admin): ?>
        <!-- ADMIN MAIN DASHBOARD -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 px-4">
            <div class="lg:col-span-4 glass p-8 shadow-xl">
                <h2 class="text-xl font-black mb-6 uppercase">Create New Exam</h2>
                <form action="exam_management.php" method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="create_test">
                    <select name="batch_id" required class="input-style w-full p-3 rounded-xl text-xs font-bold mt-1 text-main shadow-sm">
                        <?php foreach($pdo->query("SELECT id, batch_name FROM batches") as $b): ?>
                            <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['batch_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="test_name" placeholder="Test Name" required class="input-style w-full p-3 rounded-xl text-xs shadow-sm">
                    <input type="number" name="max_marks" placeholder="Max Score" required class="input-style w-full p-3 rounded-xl text-xs shadow-sm">
                    <div class="grid grid-cols-2 gap-4">
                        <div><label class="text-[9px] font-black uppercase opacity-50 ml-1">Test Start</label><input type="datetime-local" name="start_time" required class="input-style w-full p-3 rounded-xl text-xs shadow-sm"></div>
                        <div><label class="text-[9px] font-black uppercase opacity-50 ml-1">Test End</label><input type="datetime-local" name="end_time" required class="input-style w-full p-3 rounded-xl text-xs shadow-sm"></div>
                    </div>
                    <button type="submit" class="btn-primary w-full py-4 rounded-xl font-black uppercase text-[10px] tracking-widest shadow-lg transition active:scale-95">Save Draft</button>
                </form>
            </div>
            <div class="lg:col-span-8 glass p-8 shadow-xl">
                <h2 class="text-xl font-black mb-6 uppercase tracking-tighter text-main">Exams Catalog</h2>
                <div class="overflow-x-auto"><table class="w-full text-left text-sm">
                    <tr class="border-b border-theme text-[10px] font-black uppercase opacity-40"><th class="py-4">Name</th><th class="py-4">Batch</th><th class="py-4 text-center">Status</th><th class="py-4 text-right">Details</th></tr>
                    <?php foreach($pdo->query("SELECT t.*, b.batch_name FROM tests t JOIN batches b ON t.batch_id = b.id ORDER BY t.created_at DESC") as $t): ?>
                        <tr class="group hover:bg-black/5 transition"><td class="py-4 font-bold text-main"><?= htmlspecialchars($t['test_name']) ?></td><td class="py-4 opacity-70"><?= htmlspecialchars($t['batch_name']) ?></td><td class="py-4 text-center"><span class="px-3 py-1 rounded-full text-[8px] font-black uppercase <?= $t['is_public'] ? 'bg-green-500/10 text-green-500' : 'bg-orange-500/10 text-orange-500' ?>"><?= $t['is_public'] ? 'Public' : 'Private' ?></span></td><td class="py-4 text-right"><a href="exam_management.php?test_analytics=<?= $t['id'] ?>" class="text-[10px] font-black uppercase text-indigo-500 bg-indigo-500/10 px-4 py-2 rounded-xl hover:bg-indigo-500 hover:text-white transition">Details & Edit</a></td></tr>
                    <?php endforeach; ?>
                </table></div>
            </div>
        </div>

    <?php else: ?>
        <!-- STUDENT DASHBOARD -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 px-4">
            <?php
                $stmt = $pdo->prepare("
                    SELECT t.*, b.batch_name 
                    FROM tests t 
                    JOIN batches b ON t.batch_id = b.id 
                    JOIN batch_students bs ON t.batch_id = bs.batch_id 
                    WHERE bs.user_id = ? 
                    AND t.is_active = 1 
                    ORDER BY t.start_time DESC
                ");
                $stmt->execute([$user_id]);
                $all_tests = $stmt->fetchAll();
                
                if (empty($all_tests)) { echo "<div class='glass p-20 col-span-full text-center font-bold text-main opacity-50 italic'>No tests currently assigned to your batch.</div>"; }

                foreach($all_tests as $t):
                    $subm_stmt = $pdo->prepare("SELECT sub_id, score, status FROM exam_submissions WHERE test_id = ? AND user_id = ? AND status != 'STARTED'");
                    $subm_stmt->execute([$t['id'], $user_id]); $res = $subm_stmt->fetch();
                    $now = time(); $st = strtotime($t['start_time']); $en = strtotime($t['end_time']);
                    
                    $is_expired = ($now > $en);
                    $is_private = ($t['is_public'] == 0 || $is_expired);
                    
                    $status_label = $is_expired ? 'Ended' : (($now < $st) ? 'Scheduled' : 'Live');
                    $status_color = $is_expired ? 'text-red-500' : (($now < $st) ? 'text-orange-500' : 'text-green-500');
            ?>
                <div class="glass p-8 shadow-xl transition hover:scale-105 border-b-8 <?= $res ? 'border-green-500' : ($is_expired ? 'border-red-500' : 'border-indigo-500') ?>">
                    <div class="flex justify-between items-start mb-4"><span class="text-[10px] opacity-40 font-black uppercase text-main"><?= htmlspecialchars($t['batch_name']) ?></span><span class="text-[10px] font-black uppercase text-indigo-500"><?= date('d M', $st) ?></span></div>
                    <h3 class="text-2xl font-black mb-2 tracking-tighter text-main"><?= htmlspecialchars($t['test_name']) ?></h3>
                    <div class="mb-6 flex items-center gap-2">
                        <span class="text-[9px] font-black uppercase <?= $status_color ?>"><?= $status_label ?></span>
                        <span class="text-[9px] opacity-20">•</span>
                        <span class="text-[9px] font-bold opacity-60 text-main"><?= $t['max_marks'] ?> Marks</span>
                    </div>
                    <?php if($res): ?>
                        <div class="flex justify-between items-center p-4 bg-green-500/5 rounded-2xl border border-green-500/10"><div><p class="text-[9px] font-black uppercase text-green-500">Submission</p><p class="text-xl font-black text-main"><?= $is_expired ? $res['score'].'m' : 'Recorded' ?></p></div><a href="exam_management.php?view_results=<?= $res['sub_id'] ?>" class="btn-primary px-5 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest shadow-sm">Report</a></div>
                    <?php elseif ($is_private): ?>
                        <div class="text-center py-4 bg-red-500/10 text-red-500 rounded-2xl font-black uppercase text-xs opacity-50 tracking-widest"><i class="fas fa-lock mr-2"></i> Access Restricted</div>
                    <?php elseif ($now < $st): ?>
                        <div class="text-center py-4 bg-indigo-500/10 text-indigo-500 rounded-2xl font-black uppercase text-xs tracking-tighter">Opens In: <?= renderCountdown($t['start_time']) ?></div>
                    <?php else: ?>
                        <div class="mb-4 text-center text-[10px] font-black uppercase opacity-60">Session Ends: <?= renderCountdown($t['end_time']) ?></div>
                        <a href="exam_management.php?take_test=<?= $t['id'] ?>" class="block w-full text-center btn-primary py-4 rounded-2xl font-black uppercase text-xs shadow-xl transition active:scale-95">Begin Examination</a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
    document.getElementById('themeToggle')?.addEventListener('click', () => {
        const html = document.documentElement;
        const newTheme = html.getAttribute('data-theme') === 'night' ? 'day' : 'night';
        html.setAttribute('data-theme', newTheme);
        document.cookie = `theme=${newTheme}; path=/; max-age=2592000`;
        location.reload();
    });
</script>

</body>
</html>