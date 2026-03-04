<?php
// 设置 Session 路径并启动
session_set_cookie_params(['path' => '/']);
session_start();

// 未登录则跳转回首页
if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}

$userId = $_SESSION['user_id'];

// 定义目录常量（基于当前文件位置）
define('ROOT_DIR', dirname(__DIR__));          // 网站根目录
define('DATA_DIR', ROOT_DIR . '/data');
define('USERS_FILE', DATA_DIR . '/users.json');
define('GROUPS_FILE', DATA_DIR . '/groups.json');
define('AVATAR_DIR', DATA_DIR . '/avatars');
define('GROUP_AVATAR_DIR', DATA_DIR . '/group_avatars');

// ---------- 辅助函数（从主文件复制所需部分）----------
function getUsers() {
    return json_decode(file_get_contents(USERS_FILE), true) ?: [];
}

function getUserById($id) {
    $users = getUsers();
    foreach ($users as $user) {
        if ($user['id'] == $id) return $user;
    }
    return null;
}

function getFriends($userId) {
    $file = DATA_DIR . '/friends_' . $userId . '.json';
    if (!file_exists($file)) return [];
    return json_decode(file_get_contents($file), true) ?: [];
}

function getGroupInfo($groupId) {
    $groups = json_decode(file_get_contents(GROUPS_FILE), true) ?: [];
    foreach ($groups as $group) {
        if ($group['id'] == $groupId) {
            return $group;
        }
    }
    return null;
}
// -------------------------------------------------

// 获取当前用户信息
$user = getUserById($userId);
if (!$user) {
    session_destroy();
    header('Location: /index.php');
    exit;
}

// 获取已接受的好友列表（并加载详细信息）
$friendsRaw = getFriends($userId);
$friends = [];
foreach ($friendsRaw as $f) {
    if (isset($f['status']) && $f['status'] === 'accepted') {
        $friendUser = getUserById($f['id']);
        if ($friendUser) {
            $friends[] = [
                'id'   => $friendUser['id'],
                'username' => $friendUser['username'],
                'nickname' => $friendUser['nickname'] ?? $friendUser['username'],
                'avatar'   => $friendUser['avatar'] ?? null,
                'vip'      => $friendUser['vip'] ?? false,
            ];
        }
    }
}

// 获取当前用户所在的群组
$allGroups = json_decode(file_get_contents(GROUPS_FILE), true) ?: [];
$myGroups = [];
foreach ($allGroups as $group) {
    if (in_array($userId, $group['members'])) {
        // 不返回密钥等敏感信息
        $myGroups[] = [
            'id'      => $group['id'],
            'name'    => $group['name'],
            'creator' => $group['creator'],
            'avatar'  => $group['avatar'] ?? null,
        ];
    }
}

// 判断是否为 VIP
$isVip = isset($user['vip']) && $user['vip'] === true;

// 格式化注册时间
$registered = date('Y-m-d H:i:s', $user['registered']);

// 验证模式映射
$verifyModeMap = [
    'allow_all'   => '允许任何人',
    'need_verify' => '需要验证',
    'deny_all'    => '禁止添加'
];
$verifyModeText = $verifyModeMap[$user['verify_mode'] ?? 'need_verify'] ?? '未知';

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>我的信息 - NTC</title>
    <style>
        /* ========== 浅色模式变量 ========== */
        :root {
            --bg-color: #f0f2f5;
            --header-bg: #fff;
            --text-color: #333;
            --border-color: #ddd;
            --card-bg: #fff;
            --btn-bg: transparent;
            --btn-hover-bg: rgba(128,128,128,0.2);
            --friend-item-hover: #f5f5f5;
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
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Microsoft YaHei', sans-serif; }
        body {
            background: var(--bg-color);
            min-height: 100vh;
            transition: background-color 0.3s, color 0.3s;
            color: var(--text-color);
        }
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
        /* 太阳/月亮 SVG 显示控制 */
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

        /* 主内容区域：网格布局，三列，适应移动端 */
        .content {
            max-width: 1400px;
            margin: 20px auto;
            padding: 0 20px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 20px;
        }

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
            gap: 8px;
        }

        .info-item {
            display: flex;
            margin-bottom: 12px;
            line-height: 1.6;
        }
        .info-item .label {
            width: 90px;
            flex-shrink: 0;
            color: #888;
        }
        .info-item .value {
            flex: 1;
            word-break: break-word;
        }
        .vip-badge {
            display: inline-block;
            background: #f5b342;
            color: #fff;
            font-size: 12px;
            padding: 2px 8px;
            border-radius: 12px;
            margin-left: 8px;
        }

        /* 好友/群组列表样式 */
        .friend-list, .group-list {
            max-height: 400px;
            overflow-y: auto;
            margin-top: 10px;
        }
        .friend-item, .group-item {
            display: flex;
            align-items: center;
            padding: 8px 10px;
            border-radius: 8px;
            transition: background 0.2s;
            cursor: default;
            margin-bottom: 4px;
        }
        .friend-item:hover, .group-item:hover {
            background: var(--friend-item-hover);
        }
        .friend-avatar, .group-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            margin-right: 12px;
            object-fit: cover;
            background: #ccc;
        }
        .friend-info, .group-info {
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        .friend-name, .group-name {
            font-weight: 500;
            font-size: 15px;
        }
        .friend-id, .group-id {
            font-size: 12px;
            color: #888;
        }
        .group-creator-tag {
            background: #fedf00;
            color: #ffffff;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 10px;
            margin-left: 8px;
            display: inline-block;
        }
        .empty-tip {
            text-align: center;
            color: #888;
            padding: 30px 0;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="left-actions">
            <!-- 暗黑模式切换按钮 -->
            <button class="dark-mode-btn" id="darkModeToggle">
                <!-- 太阳 SVG -->
                <svg class="sun-svg" style="width: 1em; height: 1em; vertical-align: middle; fill: currentColor; overflow: hidden;" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="787">
                    <path d="M501.48 493.55m-233.03 0a233.03 233.03 0 1 0 466.06 0 233.03 233.03 0 1 0-466.06 0Z" fill="#F9C626" p-id="788"></path>
                    <path d="M501.52 185.35H478.9c-8.28 0-15-6.72-15-15V87.59c0-8.28 6.72-15 15-15h22.62c8.28 0 15 6.72 15 15v82.76c0 8.28-6.72 15-15 15zM281.37 262.76l-16 16c-5.86 5.86-15.36 5.86-21.21 0l-58.52-58.52c-5.86-5.86-5.86-15.36 0-21.21l16-16c5.86-5.86 15.36-5.86 21.21 0l58.52 58.52c5.86 5.86 5.86 15.35 0 21.21zM185.76 478.48v22.62c0 8.28-6.72 15-15 15H88c-8.28 0-15-6.72-15-15v-22.62c0-8.28 6.72-15 15-15h82.76c8.28 0 15 6.72 15 15zM270.69 698.63l16 16c5.86 5.86 5.86 15.36 0 21.21l-58.52 58.52c-5.86 5.86-15.36 5.86-21.21 0l-16-16c-5.86-5.86-5.86-15.36 0-21.21l58.52-58.52c5.85-5.86 15.35-5.86 21.21 0zM486.41 794.24h22.62c8.28 0 15 6.72 15 15V892c0 8.28-6.72 15-15 15h-22.62c-8.28 0-15-6.72-15-15v-82.76c0-8.28 6.72-15 15-15zM706.56 709.31l16-16c5.86-5.86 15.36-5.86 21.21 0l58.52 58.52c5.86 5.86 5.86 15.36 0 21.21l-16 16c-5.86 5.86-15.36 5.86-21.21 0l-58.52-58.52c-5.86-5.85-5.86-15.35 0-21.21zM802.17 493.59v-22.62c0-8.28 6.72-15 15-15h82.76c8.28 0 15 6.72 15 15v22.62c0 8.28-6.72 15-15 15h-82.76c-8.28 0-15-6.72-15-15zM717.24 273.44l-16-16c-5.86-5.86-5.86-15.36 0-21.21l58.52-58.52c5.86-5.86 15.36-5.86 21.21 0l16 16c5.86 5.86 5.86 15.36 0 21.21l-58.52 58.52c-5.86 5.86-15.35 5.86-21.21 0z" fill="#F9C626" p-id="789"></path>
                </svg>
                <!-- 月亮 SVG -->
                <svg class="moon-svg" style="width: 1em; height: 1em; vertical-align: middle; fill: currentColor; overflow: hidden;" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="6243">
                    <path d="M565 200.4c25.6 44.6 40.4 96.2 40.4 151.3 0 167.9-136.1 304-304 304-22.9 0-45.2-2.6-66.7-7.4C284.5 760.7 397 839.2 527.8 839.2c177 0 320.5-143.5 320.5-320.5 0-164.4-123.8-299.8-283.3-318.3zM312.9 243.6h-39.2v-39.2c0-10.8-8.8-19.6-19.6-19.6s-19.6 8.8-19.6 19.6v39.2h-39.2c-10.8 0-19.6 8.8-19.6 19.6s8.8 19.6 19.6 19.6h39.2V322c0 10.8 8.8 19.6 19.6 19.6s19.6-8.8 19.6-19.6v-39.2h39.2c10.8 0 19.6-8.8 19.6-19.6s-8.8-19.6-19.6-19.6z" fill="#FFF0C2" p-id="6244"></path>
                    <path d="M306.9 245.6h-35.2v-35.2c0-9.7-7.9-17.6-17.6-17.6-9.7 0-17.6 7.9-17.6 17.6v35.2h-35.2c-9.7 0-17.6 7.9-17.6 17.6 0 9.7 7.9 17.6 17.6 17.6h35.2V316c0 9.7 7.9 17.6 17.6 17.6 9.7 0 17.6-7.9 17.6-17.6v-35.2h35.2c9.7 0 17.6-7.9 17.6-17.6 0-9.7-7.9-17.6-17.6-17.6z" fill="#FFC445" p-id="6245"></path>
                    <path d="M427.8 475.3h-27.5v-27.5c0-7.6-6.2-13.8-13.8-13.8-7.6 0-13.8 6.2-13.8 13.8v27.5h-27.5c-7.6 0-13.8 6.2-13.8 13.8 0 7.6 6.2 13.8 13.8 13.8h27.5v27.5c0 7.6 6.2 13.8 13.8 13.8 7.6 0 13.8-6.2 13.8-13.8v-27.5h27.5c7.6 0 13.8-6.2 13.8-13.8 0-7.6-6.2-13.8-13.8-13.8z" fill="#FFF0C2" p-id="6246"></path>
                    <path d="M423.6 476.7h-24.7V452c0-6.8-5.5-12.4-12.4-12.4-6.8 0-12.4 5.5-12.4 12.4v24.7h-24.7c-6.8 0-12.4 5.5-12.4 12.4 0 6.8 5.5 12.4 12.4 12.4h24.7v24.7c0 6.8 5.5 12.4 12.4 12.4 6.8 0 12.4-5.5 12.4-12.4v-24.7h24.7c6.8 0 12.4-5.5 12.4-12.4 0-6.8-5.5-12.4-12.4-12.4z" fill="#FFC445" p-id="6247"></path>
                    <path d="M563.4 223c23.8 41.4 37.5 89.4 37.5 140.6 0 156-126.5 282.5-282.5 282.5-21.3 0-42-2.4-62-6.9 46.3 104.5 150.8 177.4 272.4 177.4 164.5 0 297.9-133.4 297.9-297.9 0-152.7-115.1-278.6-263.3-295.7z" fill="#FFB948" p-id="6248"></path>
                </svg>
            </button>
        </div>
        <div class="user-info">
            <?php
            $avatarUrl = !empty($user['avatar']) ? '/' . htmlspecialchars($user['avatar']) : 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2240%22%20height%3D%2240%22%20viewBox%3D%220%200%2040%2040%22%3E%3Ccircle%20cx%3D%2220%22%20cy%3D%2220%22%20r%3D%2220%22%20fill%3D%22%23ccc%22%2F%3E%3C%2Fsvg%3E';
            ?>
            <img class="avatar" src="<?php echo $avatarUrl; ?>" alt="avatar">
            <span class="username"><?php echo htmlspecialchars($user['nickname'] ?? $user['username']); ?></span>
        </div>
    </div>

    <div class="content">
        <!-- 个人信息卡片 -->
        <div class="card">
            <h2>📋 个人信息</h2>
            <div class="info-item">
                <span class="label">数字ID</span>
                <span class="value"><?php echo htmlspecialchars($user['id']); ?></span>
            </div>
            <div class="info-item">
                <span class="label">用户名</span>
                <span class="value"><?php echo htmlspecialchars($user['username']); ?></span>
            </div>
            <div class="info-item">
                <span class="label">昵称</span>
                <span class="value"><?php echo htmlspecialchars($user['nickname'] ?? $user['username']); ?></span>
            </div>
            <div class="info-item">
                <span class="label">简介</span>
                <span class="value"><?php echo htmlspecialchars($user['bio'] ?? '未填写'); ?></span>
            </div>
            <div class="info-item">
                <span class="label">注册时间</span>
                <span class="value"><?php echo $registered; ?></span>
            </div>
            <div class="info-item">
                <span class="label">验证模式</span>
                <span class="value"><?php echo $verifyModeText; ?></span>
            </div>
            <div class="info-item">
                <span class="label">VIP 状态</span>
                <span class="value">
                    <?php if ($isVip): ?>
                        <span class="vip-badge">VIP</span>
                    <?php else: ?>
                        普通用户
                    <?php endif; ?>
                </span>
            </div>
            <!-- 预留扩展：可以添加更多字段，如邮箱、手机等 -->
        </div>

        <!-- 好友列表卡片 -->
        <div class="card">
            <h2>👥 好友列表 (<?php echo count($friends); ?>)</h2>
            <div class="friend-list">
                <?php if (empty($friends)): ?>
                    <div class="empty-tip">暂无好友</div>
                <?php else: ?>
                    <?php foreach ($friends as $f): ?>
                        <div class="friend-item">
                            <?php
                            $fAvatar = !empty($f['avatar']) ? '/' . htmlspecialchars($f['avatar']) : 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2240%22%20height%3D%2240%22%20viewBox%3D%220%200%2040%2040%22%3E%3Ccircle%20cx%3D%2220%22%20cy%3D%2220%22%20r%3D%2220%22%20fill%3D%22%23ccc%22%2F%3E%3C%2Fsvg%3E';
                            ?>
                            <img class="friend-avatar" src="<?php echo $fAvatar; ?>" alt="avatar">
                            <div class="friend-info">
                                <span class="friend-name"><?php echo htmlspecialchars($f['nickname']); ?></span>
                                <span class="friend-id">ID: <?php echo htmlspecialchars($f['id']); ?></span>
                            </div>
                            <?php if (!empty($f['vip'])): ?>
                                <span class="vip-badge" style="margin-left: auto;">VIP</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <!-- 预留扩展：可添加“查看全部”链接或更多操作 -->
        </div>

        <!-- 群组列表卡片 -->
        <div class="card">
            <h2>💬 群组列表 (<?php echo count($myGroups); ?>)</h2>
            <div class="group-list">
                <?php if (empty($myGroups)): ?>
                    <div class="empty-tip">暂无群组</div>
                <?php else: ?>
                    <?php foreach ($myGroups as $g): ?>
                        <div class="group-item">
                            <?php
                            $gAvatar = !empty($g['avatar']) ? '/' . htmlspecialchars($g['avatar']) : 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2240%22%20height%3D%2240%22%20viewBox%3D%220%200%2040%2040%22%3E%3Ccircle%20cx%3D%2220%22%20cy%3D%2220%22%20r%3D%2220%22%20fill%3D%22%23ccc%22%2F%3E%3C%2Fsvg%3E';
                            ?>
                            <img class="group-avatar" src="<?php echo $gAvatar; ?>" alt="group avatar">
                            <div class="group-info">
                                <span class="group-name"><?php echo htmlspecialchars($g['name']); ?>
                                    <?php if ($g['creator'] == $userId): ?>
                                        <span class="group-creator-tag">群主</span>
                                    <?php endif; ?>
                                </span>
                                <span class="group-id">ID: <?php echo htmlspecialchars($g['id']); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <!-- 预留扩展：可添加“创建群组”按钮或更多功能 -->
        </div>
    </div>

    <script>
        // 暗黑模式切换逻辑（与 VIP 页面一致）
        (function() {
            const darkModeToggle = document.getElementById('darkModeToggle');
            const prefersDark = localStorage.getItem('darkMode') === 'true';
            
            if (prefersDark) {
                document.documentElement.classList.add('dark-mode');
            } else {
                document.documentElement.classList.remove('dark-mode');
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