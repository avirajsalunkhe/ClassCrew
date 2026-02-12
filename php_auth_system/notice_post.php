<?php
// notice_post.php - Admin Notice Management System
require_once 'db_config.php';
require_once 'User.class.php';

// Set Timezone
date_default_timezone_set('Asia/Kolkata');

$user_obj = new User();
$pdo = $user_obj->getPdo();

// CRITICAL FIX: Ensure $pdo is not null
if (!$pdo) {
    die("Database connection failed. Please check db_config.php.");
}

$user_id = $_SESSION['user_id'] ?? null;

// Access Control: Strict Admin Check
$user_data = $user_obj->getUserById($user_id);
if (!isset($_SESSION['logged_in']) || empty($user_data['is_admin'])) { 
    header('Location: index.php?error=access_denied'); 
    exit; 
}

$theme = $_COOKIE['theme'] ?? 'day';
$status_msg = $_GET['status'] ?? "";

// --- HANDLE ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_notice'])) {
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    $category = $_POST['category'];
    
    if (!empty($title) && !empty($content)) {
        $stmt = $pdo->prepare("INSERT INTO notices (title, content, category, created_by) VALUES (?, ?, ?, ?)");
        if ($stmt->execute([$title, $content, $category, $user_id])) {
            header("Location: notice_post.php?status=Notice Broadcasted Successfully");
            exit;
        }
    }
}

// Toggle or Delete
if (isset($_GET['action'])) {
    $id = $_GET['id'] ?? null;
    if ($_GET['action'] === 'delete' && $id) {
        $stmt = $pdo->prepare("DELETE FROM notices WHERE id = ?");
        $stmt->execute([$id]);
        header("Location: notice_post.php?status=Notice Deleted");
        exit;
    }
    if ($_GET['action'] === 'toggle' && $id) {
        $stmt = $pdo->prepare("UPDATE notices SET is_active = NOT is_active WHERE id = ?");
        $stmt->execute([$id]);
        header("Location: notice_post.php?status=Status Updated");
        exit;
    }
}

// Fetch existing notices
$notices = $pdo->query("SELECT n.*, u.first_name FROM notices n JOIN users u ON n.created_by = u.id ORDER BY n.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// Helper function for category colors used in preview and history
function getCategoryColorClass($category) {
    return match($category) {
        'Urgent', 'Holiday' => 'text-red-600',
        'General' => 'text-green-600',
        'Update' => 'text-orange-500',
        default => 'text-indigo-600',
    };
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Post Notice | Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap');
        :root { --bg-primary: #f8f8f8; --bg-secondary: #ffffff; --text-color: #34495e; --border-color: #d3dce0; --accent: #4f46e5; }
        html[data-theme='night'] { --bg-primary: #2c3e50; --bg-secondary: #34495e; --text-color: #ecf0f1; --border-color: #556080; --accent: #818cf8; }
        body { background-color: var(--bg-primary); color: var(--text-color); font-family: 'Plus Jakarta Sans', sans-serif; transition: all 0.3s ease; }
        .glass { background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 2rem; box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.05); }
        .input-style { background: var(--bg-primary); border: 1px solid var(--border-color); color: var(--text-color); }

        /* Marquee Animation logic for Admin Preview */
        .marquee-container {
            overflow: hidden;
            white-space: nowrap;
            position: relative;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            padding: 12px 0;
            border-radius: 1rem;
        }
        .marquee-content {
            display: inline-block;
            animation: marquee 35s linear infinite;
            padding-left: 100%;
        }
        .marquee-content:hover {
            animation-play-state: paused;
        }
        @keyframes marquee {
            0% { transform: translate(0, 0); }
            100% { transform: translate(-100%, 0); }
        }
        .notice-item {
            display: inline-flex;
            align-items: center;
            margin-right: 80px;
            font-weight: 900;
            font-size: 0.875rem;
            text-transform: uppercase;
        }
    </style>
</head>
<body class="min-h-screen p-4 lg:p-10">
    <div class="max-w-4xl mx-auto">
        <header class="flex justify-between items-center mb-10 px-4">
            <div>
                <h1 class="text-3xl font-black italic tracking-tighter uppercase">Announcements</h1>
                <p class="text-[10px] uppercase font-black opacity-40">System-wide Broadcast Center</p>
            </div>
            <div class="flex gap-2">
                <a href="admin_dashboard.php" class="glass px-6 py-2.5 text-[10px] font-black uppercase hover:bg-black/5 transition">Dashboard</a>
            </div>
        </header>

        <!-- Live Marquee Preview: Admins see exactly what students see -->
        <?php 
        $active_notices = array_filter($notices, fn($n) => $n['is_active'] == 1);
        if (!empty($active_notices)): 
        ?>
        <div class="mx-4 mb-8">
            <p class="text-[10px] font-black uppercase opacity-40 ml-1 mb-2">Live Broadcast Preview</p>
            <div class="marquee-container">
                <div class="marquee-content">
                    <?php foreach ($active_notices as $an): ?>
                        <span class="notice-item <?= getCategoryColorClass($an['category']) ?>">
                            <i class="fas fa-bullhorn mr-2"></i>
                            [<?= $an['category'] ?>] <?= htmlspecialchars($an['title']) ?>: <?= htmlspecialchars($an['content']) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($status_msg): ?>
            <div class="mx-4 mb-8 p-4 rounded-2xl bg-indigo-500/10 text-indigo-500 border border-indigo-500/20 text-xs font-bold">
                <i class="fas fa-info-circle mr-2"></i> <?= htmlspecialchars($status_msg) ?>
            </div>
        <?php endif; ?>

        <!-- Create Notice Form -->
        <section class="mb-12">
            <div class="glass p-8 shadow-xl mx-4">
                <h2 class="text-xl font-black mb-6 flex items-center gap-2"><i class="fas fa-paper-plane text-indigo-500"></i> New Broadcast</h2>
                <form action="" method="POST" class="space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="text-[10px] font-black uppercase opacity-40 ml-1">Notice Headline</label>
                            <input type="text" name="title" placeholder="e.g., Exam Schedule Updated" required class="input-style w-full px-4 py-3 rounded-xl mt-1 outline-none text-sm font-semibold">
                        </div>
                        <div>
                            <label class="text-[10px] font-black uppercase opacity-40 ml-1">Urgency Level</label>
                            <select name="category" class="input-style w-full px-4 py-3 rounded-xl mt-1 outline-none text-sm font-bold">
                                <option>General</option>
                                <option>Urgent</option>
                                <option>Update</option>
                                <option>Holiday</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="text-[10px] font-black uppercase opacity-40 ml-1">Notice Content</label>
                        <textarea name="content" rows="4" placeholder="Detailed information for students..." required class="input-style w-full px-4 py-3 rounded-xl mt-1 outline-none text-sm"></textarea>
                    </div>
                    <button type="submit" name="post_notice" class="w-full py-4 bg-indigo-600 text-white rounded-2xl font-black uppercase text-xs tracking-[0.2em] hover:bg-indigo-700 transition shadow-xl active:scale-[0.98]">
                        Send Global Notification
                    </button>
                </form>
            </div>
        </section>

        <!-- Manage Notices -->
        <section class="space-y-4 px-4 pb-20">
            <h2 class="text-xs font-black uppercase tracking-widest opacity-40 mb-4">Broadcast History</h2>
            <?php if (empty($notices)): ?>
                <div class="glass p-10 text-center opacity-30 italic text-sm">No notices have been sent yet.</div>
            <?php endif; ?>
            
            <?php foreach ($notices as $n): ?>
                <div class="glass p-6 flex flex-col md:flex-row justify-between items-start md:items-center gap-4 group transition hover:border-indigo-500/30">
                    <div class="flex-1">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="text-[8px] font-black px-2 py-0.5 rounded-full bg-indigo-500 text-white uppercase"><?= $n['category'] ?></span>
                            <span class="text-[10px] font-bold opacity-30"><?= date('M d, Y', strtotime($n['created_at'])) ?></span>
                        </div>
                        <!-- Titles now reflect their category color as well -->
                        <h3 class="font-bold text-lg leading-tight <?= getCategoryColorClass($n['category']) ?>"><?= htmlspecialchars($n['title']) ?></h3>
                        <p class="text-xs opacity-50 mt-1">Author: <?= htmlspecialchars($n['first_name']) ?></p>
                    </div>
                    <div class="flex gap-4 items-center w-full md:w-auto pt-4 md:pt-0 border-t md:border-t-0 border-gray-500/10">
                        <a href="?action=toggle&id=<?= $n['id'] ?>" class="flex-1 md:flex-none text-center text-[10px] font-black uppercase <?= $n['is_active'] ? 'text-green-500' : 'text-gray-400' ?> hover:opacity-70 transition">
                            <i class="fas <?= $n['is_active'] ? 'fa-check-circle' : 'fa-clock' ?> mr-1"></i> <?= $n['is_active'] ? 'Active' : 'Draft' ?>
                        </a>
                        <a href="?action=delete&id=<?= $n['id'] ?>" onclick="return confirm('Delete permanently?')" class="flex-1 md:flex-none text-center text-[10px] font-black uppercase text-red-400 hover:text-red-600 transition">
                            <i class="fas fa-trash-alt"></i>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
    </div>
</body>
</html>