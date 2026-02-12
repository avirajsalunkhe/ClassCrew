<?php
/**
 * Coaching Class Fees Management System
 * Grouped by Batch with Batch-specific filtering and independent accounts
 * Integrated with Razorpay Payment Gateway for real-time UPI/Online payments
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

// --- AUTHENTICATION ---
if (!isset($_SESSION['logged_in']) || !isset($_SESSION['user_id'])) { 
    header('Location: index.php?error=access_denied'); 
    exit; 
}

$user_id = $_SESSION['user_id'];
$user_obj = new User();
$current_user = $user_obj->getUserById($user_id);
$is_admin = !empty($current_user['is_admin']);

$theme = $_COOKIE['theme'] ?? 'day';

// --- POST OPERATIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ADMIN ONLY ACTIONS
    if ($is_admin) {
        // Update Student Specific Fee (Override for a specific batch account)
        if ($action === 'update_student_fee') {
            $fee_id = $_POST['fee_id'];
            $new_total = (float)$_POST['total_fee'];
            $stmt = $db->prepare("UPDATE fees SET total_fee = ? WHERE id = ?");
            $stmt->execute([$new_total, $fee_id]);
            
            // Recalculate status strictly for this batch-student account
            $db->prepare("UPDATE fees SET status = IF(paid_amount >= total_fee, 'Fully Paid', IF(paid_amount > 0, 'Partially Paid', 'Pending')) WHERE id = ?")->execute([$fee_id]);
            
            header("Location: fees_management.php?status=fee_updated");
            exit;
        }

        // Record a manual Payment for a specific batch account (Admin Only)
        if ($action === 'record_payment') {
            $fee_id = $_POST['fee_id'];
            $amount = (float)$_POST['amount'];
            $method = $_POST['method'];
            $note = $_POST['note'];

            $db->beginTransaction();
            try {
                $stmt = $db->prepare("INSERT INTO payments (fee_id, amount, payment_method, note) VALUES (?, ?, ?, ?)");
                $stmt->execute([$fee_id, $amount, $method, $note]);
                $stmt = $db->prepare("UPDATE fees SET paid_amount = paid_amount + ? WHERE id = ?");
                $stmt->execute([$amount, $fee_id]);
                $stmt = $db->prepare("UPDATE fees SET status = IF(paid_amount >= total_fee, 'Fully Paid', 'Partially Paid') WHERE id = ?");
                $stmt->execute([$fee_id]);
                $db->commit();
                header("Location: fees_management.php?status=paid");
                exit;
            } catch (Exception $e) {
                $db->rollBack();
                $error_msg = "Error processing payment.";
            }
        }

        // Delete a specific payment entry
        if ($action === 'delete_payment') {
            $pay_id = $_POST['pay_id'];
            $fee_id = $_POST['fee_id'];
            $amount = (float)$_POST['amount'];

            $db->beginTransaction();
            try {
                $db->prepare("DELETE FROM payments WHERE id = ?")->execute([$pay_id]);
                $db->prepare("UPDATE fees SET paid_amount = paid_amount - ? WHERE id = ?")->execute([$amount, $fee_id]);
                $db->prepare("UPDATE fees SET status = IF(paid_amount >= total_fee, 'Fully Paid', IF(paid_amount > 0, 'Partially Paid', 'Pending')) WHERE id = ?")->execute([$fee_id]);
                $db->commit();
                header("Location: fees_management.php?status=payment_deleted");
                exit;
            } catch (Exception $e) {
                $db->rollBack();
                $error_msg = "Error deleting payment.";
            }
        }
    }

    // STUDENT ONLY ACTIONS: Handle Successful Online Payment callback
    if (!$is_admin && $action === 'student_online_payment_success') {
        $fee_id = $_POST['fee_id'];
        $amount = (float)$_POST['amount'];
        $razorpay_id = $_POST['razorpay_payment_id'];
        
        $db->beginTransaction();
        try {
            // Record payment with real Gateway ID
            $stmt = $db->prepare("INSERT INTO payments (fee_id, amount, payment_method, note) VALUES (?, ?, 'Online UPI/Gateway', ?)");
            $stmt->execute([$fee_id, $amount, "Gateway Trans ID: " . $razorpay_id]);
            
            // Update Account
            $stmt = $db->prepare("UPDATE fees SET paid_amount = paid_amount + ? WHERE id = ?");
            $stmt->execute([$amount, $fee_id]);
            
            $stmt = $db->prepare("UPDATE fees SET status = IF(paid_amount >= total_fee, 'Fully Paid', 'Partially Paid') WHERE id = ?");
            $stmt->execute([$fee_id]);
            
            $db->commit();
            header("Location: fees_management.php?status=online_paid");
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            $error_msg = "Failed to record payment in database.";
        }
    }
}

// --- EXPORT LOGIC (Admin Only) ---
if ($is_admin && isset($_GET['export_monthly'])) {
    $e_uid = $_GET['student_id'];
    $e_bid = $_GET['batch_id']; 
    $e_month = $_GET['month'];

    $stmt = $db->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
    $stmt->execute([$e_uid]);
    $stu_name = $stmt->fetch();
    
    $stmt = $db->prepare("SELECT batch_name FROM batches WHERE id = ?");
    $stmt->execute([$e_bid]);
    $batch_name = $stmt->fetch()['batch_name'] ?? 'Batch';
    
    $filename = "Fees_" . str_replace(' ', '_', $batch_name) . "_" . $e_month . ".csv";

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Payment Report', $stu_name['first_name'] . ' ' . $stu_name['last_name'], 'Batch: ' . $batch_name]);
    fputcsv($output, ['Date', 'Amount', 'Method', 'Notes']);

    $stmt = $db->prepare("SELECT p.payment_date, p.amount, p.payment_method, p.note 
                          FROM payments p
                          JOIN fees f ON p.fee_id = f.id
                          WHERE f.user_id = ? AND f.batch_id = ? AND DATE_FORMAT(p.payment_date, '%Y-%m') = ?");
    $stmt->execute([$e_uid, $e_bid, $e_month]);
    while ($row = $stmt->fetch()) {
        fputcsv($output, [date('d M Y', strtotime($row['payment_date'])), $row['amount'], $row['payment_method'], $row['note']]);
    }
    fclose($output);
    exit;
}

// --- DATA FETCHING ---
if ($is_admin) {
    $stats = $db->query("SELECT SUM(total_fee) as total_expected, SUM(paid_amount) as total_collected FROM fees")->fetch();
    $total_due = ($stats['total_expected'] ?? 0) - ($stats['total_collected'] ?? 0);

    $filter_batch_id = $_GET['filter_batch'] ?? '';
    $all_batches_list = $db->query("SELECT id, batch_name FROM batches ORDER BY batch_name ASC")->fetchAll();

    $sql = "SELECT f.*, u.first_name, u.last_name, u.email, b.batch_name, b.id as batch_id 
            FROM fees f 
            JOIN users u ON f.user_id = u.id 
            JOIN batches b ON f.batch_id = b.id";
    
    if ($filter_batch_id) {
        $stmt = $db->prepare($sql . " WHERE f.batch_id = ? ORDER BY u.first_name ASC");
        $stmt->execute([$filter_batch_id]);
    } else {
        $stmt = $db->query($sql . " ORDER BY b.batch_name ASC, u.first_name ASC");
    }
    $all_records = $stmt->fetchAll();
    
    $grouped_records = [];
    foreach($all_records as $r) { $grouped_records[$r['batch_name']][] = $r; }

    $all_payments_raw = $db->query("SELECT * FROM payments ORDER BY payment_date DESC")->fetchAll();
    $payments_by_fee = [];
    foreach($all_payments_raw as $p) { $payments_by_fee[$p['fee_id']][] = $p; }
} else {
    // Student View: Separate balance for each batch they belong to
    $stmt = $db->prepare("SELECT f.*, b.batch_name, u.email, u.first_name, u.last_name 
                          FROM fees f 
                          JOIN batches b ON f.batch_id = b.id 
                          JOIN users u ON f.user_id = u.id 
                          WHERE f.user_id = ?");
    $stmt->execute([$user_id]);
    $my_fees = $stmt->fetchAll();

    // Calculate overall combined totals for the student
    $overall_total = 0; $overall_paid = 0;
    foreach($my_fees as $f) { $overall_total += $f['total_fee']; $overall_paid += $f['paid_amount']; }
    $overall_balance = $overall_total - $overall_paid;
    
    $my_payments_by_fee = [];
    if (!empty($my_fees)) {
        $f_ids = array_column($my_fees, 'id');
        $placeholders = str_repeat('?,', count($f_ids) - 1) . '?';
        $p_stmt = $db->prepare("SELECT * FROM payments WHERE fee_id IN ($placeholders) ORDER BY payment_date DESC");
        $p_stmt->execute($f_ids);
        while($p = $p_stmt->fetch()) { $my_payments_by_fee[$p['fee_id']][] = $p; }
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fees Dashboard | Coaching App</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Razorpay Checkout SDK -->
    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap');
        :root { --bg-primary: #f8f8f8; --bg-secondary: #ffffff; --text-color: #34495e; --border-color: #d3dce0; --accent: #4f46e5; }
        html[data-theme='night'] { --bg-primary: #2c3e50; --bg-secondary: #34495e; --text-color: #ecf0f1; --border-color: #556080; --accent: #818cf8; }
        body { background-color: var(--bg-primary); color: var(--text-color); font-family: 'Plus Jakarta Sans', sans-serif; transition: all 0.3s ease; }
        .glass { background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 2rem; box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.05); }
        .input-style { background: var(--bg-primary); border: 1px solid var(--border-color); color: var(--text-color); }
        .btn-primary { background-color: var(--accent); color: white; }
        @media print { .no-print { display: none !important; } .print-only { display: block !important; } }
        .print-only { display: none; }
    </style>
</head>
<body class="min-h-screen p-4 lg:p-10">

    <div class="max-w-7xl mx-auto no-print">
        <header class="flex justify-between items-center mb-8 px-4">
            <div><h1 class="text-3xl font-black tracking-tight">Fees Dashboard</h1><p class="text-sm opacity-60">Secure Student Portal</p></div>
            <div class="flex items-center gap-3">
                <?php if($is_admin): ?>
                    <a href="schedule_batch.php" class="glass px-5 py-2 rounded-xl text-xs font-bold hover:bg-black/5 flex items-center gap-2 transition no-print"><i class="fas fa-tasks"></i> Batches</a>
                <?php endif; ?>
                <a href="admin_dashboard.php" class="glass px-5 py-2 rounded-xl text-xs font-bold hover:bg-black/5 flex items-center gap-2 transition no-print"><i class="fas fa-arrow-left"></i> Dashboard</a>
                <button id="themeToggle" class="glass w-12 h-12 rounded-2xl flex items-center justify-center text-xl shadow-sm no-print"><i class="fas <?= $theme === 'night' ? 'fa-sun text-yellow-400' : 'fa-moon text-indigo-600' ?>"></i></button>
            </div>
        </header>

        <!-- Status Alerts -->
        <?php if (isset($_GET['status']) && $_GET['status'] == 'online_paid'): ?>
            <div class="mx-4 mb-6 p-4 rounded-2xl text-sm font-bold bg-green-500/10 text-green-500 border border-green-500/20 animate-bounce">
                <i class="fas fa-check-circle mr-2"></i> Online Payment Successful! Your batch account has been updated.
            </div>
        <?php endif; ?>

        <?php if ($is_admin): ?>
            <!-- ADMIN DASHBOARD -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8 px-4">
                <div class="glass p-6 border-l-4 border-l-indigo-500 shadow-lg"><p class="text-[10px] uppercase font-black opacity-50 mb-1">Total Expected</p><p class="text-3xl font-black">₹<?= number_format($stats['total_expected'] ?? 0, 2) ?></p></div>
                <div class="glass p-6 border-l-4 border-l-green-500 shadow-lg"><p class="text-[10px] uppercase font-black opacity-50 mb-1">Total Collected</p><p class="text-3xl font-black text-green-500">₹<?= number_format($stats['total_collected'] ?? 0, 2) ?></p></div>
                <div class="glass p-6 border-l-4 border-l-red-500 shadow-lg"><p class="text-[10px] uppercase font-black opacity-50 mb-1">Total Outstanding</p><p class="text-3xl font-black text-red-500">₹<?= number_format($total_due, 2) ?></p></div>
            </div>

            <!-- ADMIN BATCH FILTER -->
            <div class="glass p-6 mb-10 flex flex-wrap items-center justify-between gap-4 mx-4">
                <h3 class="font-bold flex items-center gap-2"><i class="fas fa-filter text-indigo-500"></i> Specific Batch Filter</h3>
                <form action="fees_management.php" method="GET" class="flex gap-2">
                    <select name="filter_batch" class="input-style px-6 py-2 rounded-xl outline-none min-w-[200px] text-sm">
                        <option value="">All Active Batches</option>
                        <?php foreach($all_batches_list as $ab): ?><option value="<?= $ab['id'] ?>" <?= $filter_batch_id == $ab['id'] ? 'selected' : '' ?>><?= htmlspecialchars($ab['batch_name']) ?></option><?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn-primary px-6 py-2 rounded-xl text-xs font-bold uppercase tracking-widest transition active:scale-95">Filter</button>
                </form>
            </div>

            <!-- ADMIN RECORDS LIST -->
            <div class="space-y-12">
                <?php foreach($grouped_records as $batch_name => $records): ?>
                    <section>
                        <h2 class="text-xl font-black mb-4 px-4 flex items-center gap-2 uppercase tracking-tighter"><i class="fas fa-layer-group text-indigo-500"></i> <?= htmlspecialchars($batch_name) ?></h2>
                        <div class="glass overflow-x-auto shadow-xl mx-4">
                            <table class="w-full text-left">
                                <thead>
                                    <tr class="border-b border-gray-500/10 text-[10px] uppercase opacity-40 font-black">
                                        <th class="py-4 px-6">Student</th>
                                        <th class="py-4 text-center">Batch Fee</th>
                                        <th class="py-4 text-center">Collected</th>
                                        <th class="py-4 text-center">Remaining</th>
                                        <th class="py-4 text-center">Status</th>
                                        <th class="py-4 px-6 text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-500/5">
                                    <?php foreach($records as $rec): $bal = $rec['total_fee'] - $rec['paid_amount']; ?>
                                    <tr>
                                        <td class="py-4 px-6 font-bold text-sm"><?= htmlspecialchars($rec['first_name'].' '.$rec['last_name']) ?><div class="text-[9px] font-normal opacity-50 italic lowercase"><?= htmlspecialchars($rec['email']) ?></div></td>
                                        <td class="py-4 text-sm text-center">₹<?= number_format($rec['total_fee'], 2) ?></td>
                                        <td class="py-4 text-sm text-green-500 font-bold text-center">₹<?= number_format($rec['paid_amount'], 2) ?></td>
                                        <td class="py-4 text-sm text-red-500 font-bold text-center">₹<?= number_format($bal, 2) ?></td>
                                        <td class="py-4 text-center"><span class="px-2 py-1 rounded-full text-[9px] font-black uppercase <?= $rec['status'] == 'Fully Paid' ? 'bg-green-500/10 text-green-500' : 'bg-orange-500/10 text-orange-500' ?>"><?= $rec['status'] ?></span></td>
                                        <td class="py-4 px-6 text-center">
                                            <div class="flex justify-center gap-4">
                                                <button onclick='openEditFeeModal(<?= json_encode($rec) ?>)' class="text-indigo-500 text-[10px] font-bold uppercase hover:underline transition"><i class="fas fa-edit mr-1"></i>Override</button>
                                                <button onclick='openPayModal(<?= json_encode($rec) ?>)' class="text-blue-500 text-[10px] font-bold uppercase hover:underline transition"><i class="fas fa-plus-circle mr-1"></i>Pay</button>
                                                <button onclick='openHistoryModal(<?= json_encode($rec) ?>, <?= json_encode($payments_by_fee[$rec['id']] ?? []) ?>)' class="text-gray-500 text-[10px] font-bold uppercase hover:underline transition"><i class="fas fa-history mr-1"></i>Logs</button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>

        <?php else: ?>
            <!-- STUDENT VIEW: Combined Summary + Batch Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-12 px-4">
                <div class="glass p-6 border-b-4 border-b-indigo-500 shadow-xl bg-indigo-500/5 transition hover:scale-105"><p class="text-[10px] uppercase font-black opacity-50 mb-1">Total Fee Committed</p><p class="text-3xl font-black">₹<?= number_format($overall_total, 2) ?></p></div>
                <div class="glass p-6 border-b-4 border-b-green-500 shadow-xl bg-green-500/5 transition hover:scale-105"><p class="text-[10px] uppercase font-black opacity-50 mb-1">Total I Have Paid</p><p class="text-3xl font-black text-green-500">₹<?= number_format($overall_paid, 2) ?></p></div>
                <div class="glass p-6 border-b-4 border-b-red-500 shadow-xl bg-red-500/5 transition hover:scale-105"><p class="text-[10px] uppercase font-black opacity-50 mb-1">Overall Balance Due</p><p class="text-3xl font-black text-red-500">₹<?= number_format($overall_balance, 2) ?></p></div>
            </div>

            <div class="space-y-8 px-4">
                <?php if(empty($my_fees)): ?><div class="glass p-20 text-center opacity-30"><i class="fas fa-receipt text-6xl mb-4"></i><p class="text-xl font-bold">No fee accounts assigned.</p></div><?php endif; ?>
                <?php foreach($my_fees as $f): 
                    $f_pays = $my_payments_by_fee[$f['id']] ?? [];
                    $f_bal = $f['total_fee'] - $f['paid_amount'];
                ?>
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
                    <div class="lg:col-span-4">
                        <div class="glass p-8 bg-gradient-to-br from-indigo-600 to-blue-700 text-white border-none shadow-xl flex flex-col justify-between">
                            <div><p class="text-[10px] uppercase opacity-60 mb-2 font-bold tracking-widest">Account: <?= htmlspecialchars($f['batch_name']) ?></p><h2 class="text-xl font-bold mb-4 uppercase">Remaining Due</h2><p class="text-4xl font-black">₹<?= number_format($f_bal, 2) ?></p></div>
                            <div class="mt-6 border-t border-white/20 pt-4">
                                <?php if($f_bal > 0): ?>
                                    <button onclick="startRazorpayPayment(<?= htmlspecialchars(json_encode($f)) ?>)" class="w-full bg-white text-indigo-700 font-bold py-3 rounded-xl shadow-lg hover:bg-opacity-90 transition active:scale-95 flex items-center justify-center gap-2"><i class="fas fa-bolt"></i> Pay UPI / Online</button>
                                <?php else: ?>
                                    <div class="bg-green-500/20 p-3 rounded-xl text-center text-xs font-bold"><i class="fas fa-check-circle mr-1"></i> FULLY PAID</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="lg:col-span-8">
                        <div class="glass p-8 shadow-xl">
                            <h2 class="text-2xl font-bold mb-6">Payment History (<?= htmlspecialchars($f['batch_name']) ?>)</h2>
                            <div class="space-y-3">
                                <?php if(empty($f_pays)): ?><p class="opacity-40 italic">No transactions recorded.</p><?php endif; ?>
                                <?php foreach($f_pays as $pay): ?>
                                    <div class="flex items-center justify-between p-4 rounded-2xl border border-gray-500/10 bg-gray-500/5 group transition hover:bg-indigo-500/5">
                                        <div class="flex items-center gap-4"><div class="w-10 h-10 rounded-xl bg-green-500/10 text-green-500 flex items-center justify-center text-sm font-bold">₹</div><div><p class="font-bold text-sm">₹<?= number_format($pay['amount'], 2) ?></p><p class="text-[9px] opacity-60 font-bold uppercase"><?= date('d M Y, h:i A', strtotime($pay['payment_date'])) ?> | <?= $pay['payment_method'] ?></p></div></div>
                                        <button onclick="printReceipt(<?= htmlspecialchars(json_encode($pay)) ?>, <?= htmlspecialchars(json_encode($f)) ?>)" class="glass px-4 py-2 text-[9px] font-black uppercase opacity-0 group-hover:opacity-100 transition"><i class="fas fa-print mr-1"></i> Receipt</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Real-time Online Payment Result Form (Hidden) -->
            <form id="onlinePayForm" action="fees_management.php" method="POST" style="display:none;">
                <input type="hidden" name="action" value="student_online_payment_success">
                <input type="hidden" name="fee_id" id="onlineFeeId">
                <input type="hidden" name="amount" id="onlineAmount">
                <input type="hidden" name="razorpay_payment_id" id="onlinePayId">
            </form>
        <?php endif; ?>
    </div>

    <!-- Admin Specific Modals -->
    <div id="editFeeModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden no-print">
        <div class="glass w-full max-w-md p-8 animate-in zoom-in duration-200">
            <div class="flex justify-between items-start mb-6"><h2 class="text-2xl font-bold">Override Fee</h2><button onclick="closeEditFeeModal()" class="opacity-40 hover:opacity-100"><i class="fas fa-times"></i></button></div>
            <form action="fees_management.php" method="POST" class="space-y-4">
                <input type="hidden" name="action" value="update_student_fee"><input type="hidden" name="fee_id" id="editFeeId">
                <div id="editFeeStuName" class="font-bold text-indigo-500 text-sm mb-2"></div>
                <div><label class="text-[10px] uppercase font-black opacity-40 ml-1">New Batch Fee Target (₹)</label><input type="number" name="total_fee" id="editFeeAmount" step="0.01" required class="input-style w-full px-4 py-3 rounded-xl mt-1 outline-none text-xl font-bold"></div>
                <button type="submit" class="btn-primary w-full font-bold py-4 rounded-2xl shadow-xl">Apply Override</button>
            </form>
        </div>
    </div>

    <div id="payModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden no-print">
        <div class="glass w-full max-w-md p-8 animate-in zoom-in duration-200">
            <div class="flex justify-between items-start mb-6"><h2 class="text-2xl font-bold">Record Manual Payment</h2><button onclick="closePayModal()" class="opacity-40 hover:opacity-100"><i class="fas fa-times"></i></button></div>
            <form action="fees_management.php" method="POST" class="space-y-4">
                <input type="hidden" name="action" value="record_payment"><input type="hidden" name="fee_id" id="payFeeId">
                <div id="payStuName" class="font-bold text-blue-500 text-sm mb-2"></div>
                <div><label class="text-[10px] uppercase font-black opacity-40 ml-1">Amount (₹)</label><input type="number" name="amount" id="payAmountInput" step="0.01" required class="input-style w-full px-4 py-3 rounded-xl mt-1 outline-none text-xl font-black"></div>
                <div><label class="text-[10px] uppercase font-black opacity-40 ml-1">Mode</label><select name="method" class="input-style w-full px-4 py-3 rounded-xl mt-1 outline-none"><option>Cash</option><option>UPI / Online</option><option>Bank Transfer</option><option>Cheque</option></select></div>
                <div><label class="text-[10px] uppercase font-black opacity-40 ml-1">Notes</label><textarea name="note" class="input-style w-full px-4 py-3 rounded-xl mt-1 h-20"></textarea></div>
                <button type="submit" class="btn-primary w-full font-bold py-4 rounded-2xl shadow-xl">Submit Record</button>
            </form>
        </div>
    </div>

    <div id="historyModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden no-print">
        <div class="glass w-full max-w-2xl p-8 shadow-2xl animate-in slide-in-from-bottom-4 duration-300">
            <div class="flex justify-between items-start mb-6"><div><h2 class="text-2xl font-bold">Account Payment Logs</h2><p id="historyStuName" class="text-xs opacity-60"></p></div>
                <div class="flex items-center gap-2">
                    <button id="exportMonthlyBtn" class="text-[10px] font-black uppercase text-indigo-500 bg-indigo-500/10 px-3 py-1 rounded-full hover:bg-indigo-500 hover:text-white transition">Monthly Report</button>
                    <button onclick="closeHistoryModal()" class="opacity-40 hover:opacity-100"><i class="fas fa-times"></i></button>
                </div>
            </div>
            <div id="historyContent" class="max-h-[50vh] overflow-y-auto space-y-3"></div>
        </div>
    </div>

    <!-- Print Receipt Template -->
    <div class="print-only p-10">
        <div class="text-center border-b pb-10 mb-10"><h1 class="text-4xl font-black text-indigo-600 uppercase">Coaching Receipt</h1><p id="printBatch" class="text-lg font-bold mt-2"></p></div>
        <div class="grid grid-cols-2 gap-10">
            <div><p class="text-[10px] uppercase opacity-60">Billed To</p><p id="printName" class="text-xl font-bold"></p><p id="printEmail" class="text-sm opacity-60"></p></div>
            <div class="text-right"><p class="text-[10px] uppercase opacity-60">Transaction Date</p><p id="printDate" class="text-xl font-bold"></p></div>
        </div>
        <div class="my-10 border p-10 rounded-3xl text-3xl font-black flex justify-between"><span>Amount Received</span><span id="printAmount"></span></div>
        <p class="text-xs opacity-40 italic text-center mt-20">Generated via Secure Digital Coaching Portal.</p>
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

        // RAZORPAY INTEGRATION FOR STUDENTS
        function startRazorpayPayment(feeData) {
            const amountDue = (feeData.total_fee - feeData.paid_amount);
            
            const options = {
                "key": "", // ENTER YOUR RAZORPAY KEY ID HERE
                "amount": (amountDue * 100).toFixed(0), // Amount is in currency subunits (Paisa)
                "currency": "INR",
                "name": "Digital Coaching Portal",
                "description": "Fee Payment for " + feeData.batch_name,
                "handler": function (response){
                    // This code runs when payment is successful
                    document.getElementById('onlineFeeId').value = feeData.id;
                    document.getElementById('onlineAmount').value = amountDue;
                    document.getElementById('onlinePayId').value = response.razorpay_payment_id;
                    document.getElementById('onlinePayForm').submit();
                },
                "prefill": {
                    "name": "<?= $current_user['first_name'] . ' ' . $current_user['last_name'] ?>",
                    "email": "<?= $current_user['email'] ?>"
                },
                "theme": {
                    "color": "#4f46e5"
                }
            };
            const rzp = new Razorpay(options);
            rzp.open();
        }

        // ADMIN SCRIPTS
        function openEditFeeModal(rec) {
            document.getElementById('editFeeId').value = rec.id;
            document.getElementById('editFeeStuName').innerText = rec.first_name + ' ' + rec.last_name + ' | Account: ' + rec.batch_name;
            document.getElementById('editFeeAmount').value = rec.total_fee;
            document.getElementById('editFeeModal').classList.remove('hidden');
        }
        function closeEditFeeModal() { document.getElementById('editFeeModal').classList.add('hidden'); }

        function openPayModal(rec) {
            document.getElementById('payFeeId').value = rec.id;
            document.getElementById('payStuName').innerText = rec.first_name + ' ' + rec.last_name + ' | Account: ' + rec.batch_name;
            const b = (rec.total_fee - rec.paid_amount).toFixed(2);
            document.getElementById('payAmountInput').value = b > 0 ? b : '';
            document.getElementById('payModal').classList.remove('hidden');
        }
        function closePayModal() { document.getElementById('payModal').classList.add('hidden'); }

        function openHistoryModal(rec, payments) {
            document.getElementById('historyStuName').innerText = rec.first_name + ' ' + rec.last_name + ' | Batch: ' + rec.batch_name;
            const mon = new Date().toISOString().slice(0, 7);
            document.getElementById('exportMonthlyBtn').onclick = () => window.location.href = `fees_management.php?export_monthly=1&student_id=${rec.user_id}&batch_id=${rec.batch_id}&month=${mon}`;
            const container = document.getElementById('historyContent');
            container.innerHTML = payments.length ? '' : '<p class="text-center py-10 opacity-40 italic">No transactions recorded.</p>';
            payments.forEach(p => {
                const div = document.createElement('div');
                div.className = 'flex items-center justify-between p-4 rounded-2xl border border-gray-500/10 bg-black/5';
                div.innerHTML = `<div class="flex items-center gap-4"><div class="w-10 h-10 rounded-xl bg-green-500/10 text-green-500 flex items-center justify-center text-sm font-bold">₹</div><div><p class="font-bold text-sm">₹${parseFloat(p.amount).toLocaleString()}</p><p class="text-[9px] opacity-60 font-bold uppercase">${p.payment_date} | ${p.payment_method}</p></div></div><div class="flex gap-2"><button onclick='printReceipt(${JSON.stringify(p)}, ${JSON.stringify(rec)})' class="text-[10px] font-black uppercase text-indigo-500 hover:underline transition">Receipt</button><form action="fees_management.php" method="POST" onsubmit="return confirm('Delete transaction record?')"><input type="hidden" name="action" value="delete_payment"><input type="hidden" name="pay_id" value="${p.id}"><input type="hidden" name="fee_id" value="${rec.id}"><input type="hidden" name="amount" value="${p.amount}"><button type="submit" class="text-[10px] font-black uppercase text-red-500 hover:underline ml-2">Delete</button></form></div>`;
                container.appendChild(div);
            });
            document.getElementById('historyModal').classList.remove('hidden');
        }
        function closeHistoryModal() { document.getElementById('historyModal').classList.add('hidden'); }

        function printReceipt(pay, fee) {
            document.getElementById('printName').innerText = fee.first_name + ' ' + fee.last_name;
            document.getElementById('printEmail').innerText = fee.email || '';
            document.getElementById('printBatch').innerText = 'Account: ' + (fee.batch_name || 'Class Fee');
            document.getElementById('printAmount').innerText = '₹' + parseFloat(pay.amount).toLocaleString();
            document.getElementById('printDate').innerText = pay.payment_date;
            window.print();
        }
    </script>
</body>
</html>