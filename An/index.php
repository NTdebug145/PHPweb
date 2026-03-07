<?php
// ==================== 会话与初始化 ====================
session_set_cookie_params(['path' => '/']);
session_start();

// 定义目录常量（基于当前文件位置，与管理界面保持一致）
define('ROOT_DIR', dirname(__DIR__));          // 网站根目录（假设当前文件在子目录）
define('DATA_DIR', ROOT_DIR . '/data');
define('USERS_PATTERN', DATA_DIR . '/users_*.json');

// ---------- 辅助函数（从管理界面复制）----------
function getUsers() {
    $users = [];
    $files = glob(USERS_PATTERN);
    foreach ($files as $file) {
        $content = file_get_contents($file);
        $data = json_decode($content, true);
        if (is_array($data)) {
            $users = array_merge($users, $data);
        }
    }
    usort($users, function($a, $b) {
        return $a['id'] <=> $b['id'];
    });
    return $users;
}

function getUserById($id) {
    $users = getUsers();
    foreach ($users as $user) {
        if ($user['id'] == $id) return $user;
    }
    return null;
}
// -------------------------------------------------

// 获取当前登录用户信息（若已登录）
$currentUser = null;
if (isset($_SESSION['user_id'])) {
    $currentUser = getUserById($_SESSION['user_id']);
}

// ---------- 公告数据读取（原逻辑）----------
function readData() {
    $file = 'AnData.json';   // 假设公告数据文件位于当前目录
    if (!file_exists($file)) {
        return ['tags' => [], 'announcements' => []];
    }
    $content = file_get_contents($file);
    $data = json_decode($content, true);
    if (!$data || !isset($data['announcements'])) {
        return ['tags' => [], 'announcements' => []];
    }
    foreach ($data['announcements'] as &$ann) {
        if (!isset($ann['visible'])) {
            $ann['visible'] = false;
        }
    }
    return $data;
}

$data = readData();
$allAnnouncements = array_filter($data['announcements'], function($ann) {
    return $ann['visible'] === true;
});
usort($allAnnouncements, function($a, $b) {
    return $b['id'] - $a['id'];
});

$justPublished = isset($_GET['published']) && $_GET['published'] == '1';

// CSRF令牌（用于可能的安全扩展，此处仅占位）
$csrf_token = $_SESSION['csrf_token'] ?? '';
if (empty($csrf_token)) {
    $csrf_token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $csrf_token;
}
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NTC - An</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* ========== 浅色模式变量（完全沿用管理界面）========= */
        :root {
            --bg-color: #f0f2f5;
            --header-bg: #fff;
            --text-color: #333;
            --border-color: #ddd;
            --card-bg: #fff;
            --btn-bg: transparent;
            --btn-hover-bg: rgba(128,128,128,0.2);
            --friend-item-hover: #f5f5f5;  /* 可用于公告条目光标悬停 */
            --modal-bg: #fff;
            --input-bg: #fff;
            --tag-bg: #ecf1f7;
            --tag-color: #254358;
            --tag-border: #d7e2ee;
        }

        /* ========== 暗黑模式变量 ========== */
        .dark-mode {
            --bg-color: #1e1e1e;
            --header-bg: #2d2d2d;
            --text-color: #eee;
            --border-color: #444;
            --card-bg: #2d2d2d;
            --btn-bg: transparent;
            --btn-hover-bg: rgba(255,255,255,0.1);
            --friend-item-hover: #3a3a3a;
            --modal-bg: #2d2d2d;
            --input-bg: #3a3a3a;
            --tag-bg: #3a3f4a;
            --tag-color: #cbd5e0;
            --tag-border: #4a5568;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Microsoft YaHei', 'Inter', sans-serif; }
        body {
            background: var(--bg-color);
            min-height: 100vh;
            transition: background-color 0.3s, color 0.3s;
            color: var(--text-color);
        }

        /* ----- 头部（与管理界面完全一致）----- */
        .header {
            height: 60px;
            background: var(--header-bg);
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border-bottom: 1px solid var(--border-color);
            transition: background-color 0.3s, border-color 0.3s;
        }
        .left-actions {
            display: flex;
            align-items: center;
        }
        .dark-mode-btn {
            background: var(--btn-bg);
            border: none;
            font-size: 24px;
            cursor: pointer;
            padding: 5px 10px;
            color: var(--text-color);
            line-height: 1;
            border-radius: 30px;
            transition: background 0.2s;
        }
        .dark-mode-btn:hover {
            background: var(--btn-hover-bg);
        }
        .sun-svg { display: none; }
        .moon-svg { display: inline-block; }
        .dark-mode .sun-svg { display: inline-block; }
        .dark-mode .moon-svg { display: none; }

        .user-info {
            display: flex;
            align-items: center;
            padding: 5px;
            border-radius: 30px;
            cursor: default;
        }
        .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            margin-right: 10px;
            object-fit: cover;
        }
        .username {
            font-weight: 500;
            color: var(--text-color);
        }

        /* ----- 主内容区域：单列网格 ----- */
        .content {
            max-width: 1000px;
            margin: 20px auto;
            padding: 0 20px;
            display: grid;
            grid-template-columns: 1fr;   /* 单列，公告纵向排列 */
            gap: 20px;
        }

        /* 卡片（复用管理界面） */
        .card {
            background: var(--card-bg);
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.1);
            padding: 20px;
            transition: background-color 0.3s;
            border: 1px solid var(--border-color);
        }

        .card h2 {
            font-size: 18px;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        /* 公告标题 */
        .announcement-title {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--text-color);
        }
        .announcement-summary {
            color: var(--text-color);
            opacity: 0.8;
            margin-bottom: 1rem;
            font-size: 0.95rem;
            word-break: break-word;
            line-height: 1.6;
        }
        .meta-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 1rem 0.8rem;
            margin-top: 0.8rem;
        }
        .date {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            background: var(--tag-bg);
            padding: 0.3rem 1.2rem 0.3rem 0.9rem;
            border-radius: 40px;
            font-size: 0.85rem;
            color: var(--tag-color);
            border: 1px solid var(--tag-border);
        }
        .tag-group {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .tag {
            display: inline-block;
            background: var(--tag-bg);
            color: var(--tag-color);
            padding: 0.25rem 1rem;
            border-radius: 40px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.02em;
            border: 1px solid var(--tag-border);
        }

        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: var(--card-bg);
            border-radius: 12px;
            color: var(--text-color);
            opacity: 0.7;
            border: 1px dashed var(--border-color);
        }

        .success-message {
            background: #dff9e6;
            border: 1px solid #a8e5bd;
            border-radius: 50px;
            padding: 0.8rem 2rem;
            margin-bottom: 2rem;
            color: #0d6632;
            font-weight: 500;
            display: inline-block;
        }
        .dark-mode .success-message {
            background: #1e3a2a;
            border-color: #2f6e47;
            color: #b3e6c0;
        }

        hr {
            border: 1px dashed var(--border-color);
            margin: 2.5rem 0 1rem;
        }
        .footer-note {
            text-align: center;
            color: var(--text-color);
            opacity: 0.6;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <!-- 头部：返回主页 + 暗黑模式切换 + 用户信息 -->
    <div class="header">
        <div class="left-actions">
            <!-- 返回主页（指向管理界面首页） -->
            <a href="/index.php" class="dark-mode-btn" style="text-decoration: none; margin-right: 8px;" title="返回主页">
                <span class="icon-btn">
                    <svg class="icon" style="width: 1em;height: 1em;vertical-align: middle;fill: currentColor;overflow: hidden;" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="7257"><path d="M424.319032 885.693004 424.319032 620.123556 601.364307 620.123556 601.364307 885.693004 822.671669 885.693004 822.671669 531.60143 955.455881 531.60143 512.841158 133.24777 70.226434 531.60143 203.01167 531.60143 203.01167 885.693004Z" fill="currentColor" p-id="7258"></path></svg>
                </span>
            </a>
            <!-- 暗黑模式切换按钮 -->
            <button class="dark-mode-btn" id="darkModeToggle">
                <svg class="sun-svg" style="width: 1em; height: 1em; vertical-align: middle; fill: currentColor; overflow: hidden;" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="787">
                    <path d="M501.48 493.55m-233.03 0a233.03 233.03 0 1 0 466.06 0 233.03 233.03 0 1 0-466.06 0Z" fill="#F9C626" p-id="788"></path>
                    <path d="M501.52 185.35H478.9c-8.28 0-15-6.72-15-15V87.59c0-8.28 6.72-15 15-15h22.62c8.28 0 15 6.72 15 15v82.76c0 8.28-6.72 15-15 15zM281.37 262.76l-16 16c-5.86 5.86-15.36 5.86-21.21 0l-58.52-58.52c-5.86-5.86-5.86-15.36 0-21.21l16-16c5.86-5.86 15.36-5.86 21.21 0l58.52 58.52c5.86 5.86 5.86 15.35 0 21.21zM185.76 478.48v22.62c0 8.28-6.72 15-15 15H88c-8.28 0-15-6.72-15-15v-22.62c0-8.28 6.72-15 15-15h82.76c8.28 0 15 6.72 15 15zM270.69 698.63l16 16c5.86 5.86 5.86 15.36 0 21.21l-58.52 58.52c-5.86 5.86-15.36 5.86-21.21 0l-16-16c-5.86-5.86-5.86-15.36 0-21.21l58.52-58.52c5.85-5.86 15.35-5.86 21.21 0zM486.41 794.24h22.62c8.28 0 15 6.72 15 15V892c0 8.28-6.72 15-15 15h-22.62c-8.28 0-15-6.72-15-15v-82.76c0-8.28 6.72-15 15-15zM706.56 709.31l16-16c5.86-5.86 15.36-5.86 21.21 0l58.52 58.52c5.86 5.86 5.86 15.36 0 21.21l-16 16c-5.86 5.86-15.36 5.86-21.21 0l-58.52-58.52c-5.86-5.85-5.86-15.35 0-21.21zM802.17 493.59v-22.62c0-8.28 6.72-15 15-15h82.76c8.28 0 15 6.72 15 15v22.62c0 8.28-6.72 15-15 15h-82.76c-8.28 0-15-6.72-15-15zM717.24 273.44l-16-16c-5.86-5.86-5.86-15.36 0-21.21l58.52-58.52c5.86-5.86 15.36-5.86 21.21 0l16 16c5.86 5.86 5.86 15.36 0 21.21l-58.52 58.52c-5.86 5.86-15.35 5.86-21.21 0z" fill="#F9C626" p-id="789"></path>
                </svg>
                <svg class="moon-svg" style="width: 1em; height: 1em; vertical-align: middle; fill: currentColor; overflow: hidden;" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="6243">
                    <path d="M565 200.4c25.6 44.6 40.4 96.2 40.4 151.3 0 167.9-136.1 304-304 304-22.9 0-45.2-2.6-66.7-7.4C284.5 760.7 397 839.2 527.8 839.2c177 0 320.5-143.5 320.5-320.5 0-164.4-123.8-299.8-283.3-318.3zM312.9 243.6h-39.2v-39.2c0-10.8-8.8-19.6-19.6-19.6s-19.6 8.8-19.6 19.6v39.2h-39.2c-10.8 0-19.6 8.8-19.6 19.6s8.8 19.6 19.6 19.6h39.2V322c0 10.8 8.8 19.6 19.6 19.6s19.6-8.8 19.6-19.6v-39.2h39.2c10.8 0 19.6-8.8 19.6-19.6s-8.8-19.6-19.6-19.6z" fill="#FFF0C2" p-id="6244"></path>
                    <path d="M306.9 245.6h-35.2v-35.2c0-9.7-7.9-17.6-17.6-17.6-9.7 0-17.6 7.9-17.6 17.6v35.2h-35.2c-9.7 0-17.6 7.9-17.6 17.6 0 9.7 7.9 17.6 17.6 17.6h35.2V316c0 9.7 7.9 17.6 17.6 17.6 9.7 0 17.6-7.9 17.6-17.6v-35.2h35.2c9.7 0 17.6-7.9 17.6-17.6 0-9.7-7.9-17.6-17.6-17.6z" fill="#FFC445" p-id="6245"></path>
                    <path d="M427.8 475.3h-27.5v-27.5c0-7.6-6.2-13.8-13.8-13.8-7.6 0-13.8 6.2-13.8 13.8v27.5h-27.5c-7.6 0-13.8 6.2-13.8 13.8 0 7.6 6.2 13.8 13.8 13.8h27.5v27.5c0 7.6 6.2 13.8 13.8 13.8 7.6 0 13.8-6.2 13.8-13.8v-27.5h27.5c7.6 0 13.8-6.2 13.8-13.8 0-7.6-6.2-13.8-13.8-13.8z" fill="#FFF0C2" p-id="6246"></path>
                    <path d="M423.6 476.7h-24.7V452c0-6.8-5.5-12.4-12.4-12.4-6.8 0-12.4 5.5-12.4 12.4v24.7h-24.7c-6.8 0-12.4 5.5-12.4 12.4 0 6.8 5.5 12.4 12.4 12.4h24.7v24.7c0 6.8 5.5 12.4 12.4 12.4 6.8 0 12.4-5.5 12.4-12.4v-24.7h24.7c6.8 0 12.4-5.5 12.4-12.4 0-6.8-5.5-12.4-12.4-12.4z" fill="#FFC445" p-id="6247"></path>
                    <path d="M563.4 223c23.8 41.4 37.5 89.4 37.5 140.6 0 156-126.5 282.5-282.5 282.5-21.3 0-42-2.4-62-6.9 46.3 104.5 150.8 177.4 272.4 177.4 164.5 0 297.9-133.4 297.9-297.9 0-152.7-115.1-278.6-263.3-295.7z" fill="#FFB948" p-id="6248"></path>
                </svg>
            </button>
        </div>

        <!-- 右侧用户信息 -->
        <div class="user-info">
            <?php
            if ($currentUser) {
                $avatarUrl = !empty($currentUser['avatar']) ? '/' . htmlspecialchars($currentUser['avatar']) : 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2240%22%20height%3D%2240%22%20viewBox%3D%220%200%2040%2040%22%3E%3Ccircle%20cx%3D%2220%22%20cy%3D%2220%22%20r%3D%2220%22%20fill%3D%22%23ccc%22%2F%3E%3C%2Fsvg%3E';
                $displayName = htmlspecialchars($currentUser['nickname'] ?? $currentUser['username']);
            } else {
                $avatarUrl = 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2240%22%20height%3D%2240%22%20viewBox%3D%220%200%2040%2040%22%3E%3Ccircle%20cx%3D%2220%22%20cy%3D%2220%22%20r%3D%2220%22%20fill%3D%22%23aaa%22%2F%3E%3Ctext%20x%3D%2210%22%20y%3D%2225%22%20fill%3D%22%23fff%22%3E%3F%3C%2Ftext%3E%3C%2Fsvg%3E';
                $displayName = '游客';
            }
            ?>
            <img class="avatar" src="<?php echo $avatarUrl; ?>" alt="avatar">
            <span class="username"><?php echo $displayName; ?></span>
        </div>
    </div>

    <div class="content">
        <!-- 发布成功提示（如果有） -->
        <?php if ($justPublished): ?>
            <div class="card" style="padding: 10px 20px; background: #dff9e6; border-color: #a8e5bd; color: #0d6632;">
                <i class="fa-regular fa-circle-check"></i> 公告已成功发布！（若未公开则用户暂时不可见）
            </div>
        <?php endif; ?>

        <!-- 公告标题卡片（统计卡片） -->
        <div class="card">
            <h2>
                <span><i class="fa-regular fa-bell"></i> 公告中心</span>
                <span style="font-size:0.9rem; opacity:0.7;">共 <?php echo count($allAnnouncements); ?> 条</span>
            </h2>
        </div>

        <!-- 公告列表 -->
        <?php if (empty($allAnnouncements)): ?>
            <div class="empty-state">
                <i class="fa-regular fa-message" style="font-size: 3rem; opacity: 0.4; margin-bottom: 1rem;"></i>
                <p>暂无公开公告，请稍后访问。</p>
            </div>
        <?php else: ?>
            <?php foreach ($allAnnouncements as $ann): ?>
                <div class="card">
                    <div class="announcement-title"><?php echo htmlspecialchars($ann['title']); ?></div>
                    <div class="announcement-summary"><?php echo nl2br(htmlspecialchars($ann['summary'])); ?></div>
                    <div class="meta-row">
                        <span class="date"><i class="fa-regular fa-calendar"></i> <?php echo htmlspecialchars($ann['date']); ?></span>
                        <?php if (!empty($ann['tags'])): ?>
                            <div class="tag-group">
                                <?php foreach ($ann['tags'] as $tag): ?>
                                    <span class="tag"><?php echo htmlspecialchars($tag); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <hr>
        <div class="footer-note">
            <i class="fa-regular fa-clock"></i> 仅显示已公开的公告
        </div>
    </div>

    <script>
        (function() {
            // 暗黑模式切换（完全沿用管理界面逻辑）
            const darkModeToggle = document.getElementById('darkModeToggle');
            const prefersDark = localStorage.getItem('darkMode') === 'true';
            if (prefersDark) {
                document.documentElement.classList.add('dark-mode');
            }
            darkModeToggle.addEventListener('click', () => {
                document.documentElement.classList.toggle('dark-mode');
                const isDark = document.documentElement.classList.contains('dark-mode');
                localStorage.setItem('darkMode', isDark);
            });
        })();
    </script>
</body>
</html>