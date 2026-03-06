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
    $users = [];
    $files = glob(DATA_DIR . '/users_*.json');
    foreach ($files as $file) {
        $content = file_get_contents($file);
        $data = json_decode($content, true);
        if (is_array($data)) {
            $users = array_merge($users, $data);
        }
    }
    // 按ID排序，保持一致性（可选）
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
        if ($f['id'] == '1000000000') continue; // 过滤机器人
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

// 获取当前用户 CSRF 令牌（从 session 中获取）
$csrf_token = $_SESSION['csrf_token'] ?? '';
if (empty($csrf_token)) {
    $csrf_token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $csrf_token;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>NTC - Management</title>
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
            --modal-bg: #fff;
            --input-bg: #fff;
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

        /* 主内容区域：网格布局 */
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
            justify-content: space-between;
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
            margin-bottom: 4px;
            cursor: pointer;
            position: relative;
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

        .manage-btn {
            background: transparent;
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 4px 12px;
            font-size: 12px;
            cursor: pointer;
            color: var(--text-color);
            transition: all 0.2s;
        }
        .manage-btn:hover {
            background: var(--btn-hover-bg);
        }

        .group-manage-icon {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            margin-left: 8px;
            font-size: 18px;
            color: var(--text-color);
            background: var(--btn-bg);
            transition: background 0.2s;
        }
        .group-manage-icon:hover {
            background: var(--btn-hover-bg);
        }
        .group-manage-icon.hidden {
            display: none;
        }

        /* 模态框样式 */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            justify-content: center;
            align-items: center;
            z-index: 2000;
        }
        .modal-content {
            background: var(--modal-bg);
            padding: 25px;
            border-radius: 12px;
            min-width: 350px;
            max-width: 500px;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            color: var(--text-color);
            border: 1px solid var(--border-color);
        }
        .modal-content h3 {
            margin-bottom: 20px;
            font-size: 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-content .close {
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            color: var(--text-color);
        }
        .modal-content input,
        .modal-content select,
        .modal-content textarea {
            width: 100%;
            padding: 8px 12px;
            margin: 10px 0;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            font-size: 14px;
            background: var(--input-bg);
            color: var(--text-color);
        }
        .modal-content button {
            padding: 8px 16px;
            margin-right: 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        .modal-content button.primary {
            background: #07c160;
            color: white;
        }
        .modal-content button.danger {
            background: #f56c6c;
            color: white;
        }
        .modal-content button.secondary {
            background: #6c757d;
            color: white;
        }

        .member-item {
            display: flex;
            align-items: center;
            padding: 10px;
            border-bottom: 1px solid var(--border-color);
        }
        .member-item:last-child {
            border-bottom: none;
        }
        .member-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            margin-right: 12px;
            object-fit: cover;
        }
        .member-info {
            flex: 1;
        }
        .member-name {
            font-weight: 500;
        }
        .member-id {
            font-size: 12px;
            color: #888;
        }
        .kick-btn {
            background: #f56c6c;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 5px 10px;
            cursor: pointer;
            font-size: 12px;
        }
        .kick-btn:hover {
            background: #e04a4a;
        }

        .friend-delete-btn {
            background: transparent;
            border: none;
            color: #f56c6c;
            font-size: 18px;
            cursor: pointer;
            margin-left: 8px;
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

<div class="info-item">
    <span class="label"></span>
    <span class="value">
        <button class="manage-btn" onclick="showDeleteAccountModal()" style="background:#f56c6c; color:white; border:none;">注销账号</button>
    </span>
</div>

            <!-- 预留扩展：可以添加更多字段，如邮箱、手机等 -->
        </div>

        <!-- 好友列表卡片 -->
        <div class="card">
            <h2>
                <span>👥 好友列表 (<?php echo count($friends); ?>)</span>
                <button class="manage-btn" onclick="showManageFriendsModal()">管理好友</button>
            </h2>
            <div class="friend-list" id="friendList">
                <?php if (empty($friends)): ?>
                    <div class="empty-tip">暂无好友</div>
                <?php else: ?>
                    <?php foreach ($friends as $f): ?>
                        <div class="friend-item" data-id="<?php echo htmlspecialchars($f['id']); ?>" onclick="showFriendInfo('<?php echo htmlspecialchars($f['id']); ?>')">
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
        </div>

        <!-- 群组列表卡片 -->
        <div class="card">
            <h2>
                <span>💬 群组列表 (<?php echo count($myGroups); ?>)</span>
                <button class="manage-btn" onclick="showManageGroupsModal()">管理群组</button>
            </h2>
            <div class="group-list" id="groupList">
                <?php if (empty($myGroups)): ?>
                    <div class="empty-tip">暂无群组</div>
                <?php else: ?>
                    <?php foreach ($myGroups as $g): ?>
                        <div class="group-item" data-id="<?php echo htmlspecialchars($g['id']); ?>">
                            <div style="display: flex; align-items: center; flex:1; cursor:pointer;" onclick="showGroupInfo('<?php echo htmlspecialchars($g['id']); ?>')">
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
                            <?php if ($g['creator'] == $userId): ?>
                                <div class="group-manage-icon" title="管理成员" onclick="showGroupMembers('<?php echo htmlspecialchars($g['id']); ?>', '<?php echo htmlspecialchars($g['name']); ?>')">⚙️</div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ========== 模态框 ========== -->

    <!-- 管理好友弹窗 -->
    <div class="modal" id="manageFriendsModal">
        <div class="modal-content">
            <h3>
                管理好友
                <span class="close" onclick="closeModal('manageFriendsModal')">&times;</span>
            </h3>
            <div id="manageFriendsList" style="max-height: 400px; overflow-y: auto;"></div>
        </div>
    </div>

    <!-- 好友基本信息弹窗 -->
    <div class="modal" id="friendInfoModal">
        <div class="modal-content">
            <h3>
                好友信息
                <span class="close" onclick="closeModal('friendInfoModal')">&times;</span>
            </h3>
            <div id="friendInfoContent"></div>
        </div>
    </div>

    <!-- 管理群组弹窗（退出群组） -->
    <div class="modal" id="manageGroupsModal">
        <div class="modal-content">
            <h3>
                管理群组
                <span class="close" onclick="closeModal('manageGroupsModal')">&times;</span>
            </h3>
            <div id="manageGroupsList" style="max-height: 400px; overflow-y: auto;"></div>
        </div>
    </div>

    <!-- 群组基本信息弹窗 -->
    <div class="modal" id="groupInfoModal">
        <div class="modal-content">
            <h3>
                群组信息
                <span class="close" onclick="closeModal('groupInfoModal')">&times;</span>
            </h3>
            <div id="groupInfoContent"></div>
        </div>
    </div>

    <!-- 群成员管理弹窗 -->
    <div class="modal" id="groupMembersModal">
        <div class="modal-content">
            <h3>
                群成员管理 - <span id="groupNameSpan"></span>
                <span class="close" onclick="closeModal('groupMembersModal')">&times;</span>
            </h3>
            <div id="groupMembersList" style="max-height: 400px; overflow-y: auto;"></div>
        </div>
    </div>

<!-- 注销账号模态框 -->
<div class="modal" id="deleteAccountModal">
    <div class="modal-content">
        <h3>
            注销账号
            <span class="close" onclick="closeModal('deleteAccountModal')">&times;</span>
        </h3>
        <p style="color: #f56c6c; margin-bottom: 15px;">警告：注销账号将永久删除所有数据，包括好友、群组、聊天记录等，且无法恢复！</p>
        <input type="password" id="deletePassword" placeholder="请输入您的密码">
        <input type="text" id="deleteConfirm" placeholder="输入 'yes' 以确认">
        <div style="text-align: right; margin-top: 20px;">
            <button class="danger" onclick="deleteAccount()">确认注销</button>
            <button class="secondary" onclick="closeModal('deleteAccountModal')">取消</button>
        </div>
    </div>
</div>

    <script>
        // 当前用户ID和CSRF令牌
        const CURRENT_USER_ID = '<?php echo $userId; ?>';
        const CSRF_TOKEN = '<?php echo $csrf_token; ?>';

        // 关闭模态框通用函数
        function closeModal(id) {
            document.getElementById(id).style.display = 'none';
        }

        // 显示模态框
        function showModal(id) {
            document.getElementById(id).style.display = 'flex';
        }

        // ---------- 好友管理 ----------
        function showManageFriendsModal() {
            fetch('/index.php?action=getFriends')
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        const container = document.getElementById('manageFriendsList');
                        container.innerHTML = '';
                        if (data.friends.length === 0) {
                            container.innerHTML = '<div class="empty-tip">暂无好友</div>';
                        } else {
                            data.friends.forEach(f => {
                                const div = document.createElement('div');
                                div.className = 'friend-item';
                                div.style.cursor = 'default';
                                div.innerHTML = `
                                    <img class="friend-avatar" src="${f.avatar ? '/' + f.avatar : 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2240%22%20height%3D%2240%22%20viewBox%3D%220%200%2040%2040%22%3E%3Ccircle%20cx%3D%2220%22%20cy%3D%2220%22%20r%3D%2220%22%20fill%3D%22%23ccc%22%2F%3E%3C%2Fsvg%3E'}" alt="avatar">
                                    <div class="friend-info">
                                        <span class="friend-name">${escapeHtml(f.nickname || f.username)}</span>
                                        <span class="friend-id">ID: ${escapeHtml(f.id)}</span>
                                    </div>
                                    <button class="friend-delete-btn" onclick="deleteFriend('${escapeHtml(f.id)}')">🗑️</button>
                                `;
                                container.appendChild(div);
                            });
                        }
                        showModal('manageFriendsModal');
                    } else {
                        alert('获取好友列表失败：' + data.error);
                    }
                });
        }

        function deleteFriend(friendId) {
            if (!confirm('确定要删除该好友吗？聊天记录也将被清除。')) return;
            const formData = new URLSearchParams();
            formData.append('friendId', friendId);
            formData.append('_csrf', CSRF_TOKEN);
            fetch('/index.php?action=deleteFriend', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        alert('好友已删除');
                        closeModal('manageFriendsModal');
                        location.reload(); // 刷新页面以更新列表
                    } else {
                        alert('删除失败：' + (data.error || '未知错误'));
                    }
                });
        }

        // 显示好友基本信息
        function showFriendInfo(friendId) {
            fetch('/index.php?action=searchUserInfo&userId=' + encodeURIComponent(friendId))
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        const user = data.user;
                        const registered = new Date(user.registered * 1000).toLocaleString();
                        const content = `
                            <div class="info-item"><span class="label">ID</span><span class="value">${escapeHtml(user.id)}</span></div>
                            <div class="info-item"><span class="label">用户名</span><span class="value">${escapeHtml(user.username)}</span></div>
                            <div class="info-item"><span class="label">昵称</span><span class="value">${escapeHtml(user.nickname)}</span></div>
                            <div class="info-item"><span class="label">简介</span><span class="value">${escapeHtml(user.bio || '无')}</span></div>
                            <div class="info-item"><span class="label">注册时间</span><span class="value">${escapeHtml(registered)}</span></div>
                        `;
                        document.getElementById('friendInfoContent').innerHTML = content;
                        showModal('friendInfoModal');
                    } else {
                        alert('获取信息失败：' + data.error);
                    }
                });
        }

        // ---------- 群组管理 ----------
        function showManageGroupsModal() {
            fetch('/index.php?action=getGroupList')
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        const container = document.getElementById('manageGroupsList');
                        container.innerHTML = '';
                        if (data.groups.length === 0) {
                            container.innerHTML = '<div class="empty-tip">暂无群组</div>';
                        } else {
                            data.groups.forEach(g => {
                                const div = document.createElement('div');
                                div.className = 'group-item';
                                div.style.cursor = 'default';
                                div.innerHTML = `
                                    <img class="group-avatar" src="${g.avatar ? '/' + g.avatar : 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2240%22%20height%3D%2240%22%20viewBox%3D%220%200%2040%2040%22%3E%3Ccircle%20cx%3D%2220%22%20cy%3D%2220%22%20r%3D%2220%22%20fill%3D%22%23ccc%22%2F%3E%3C%2Fsvg%3E'}" alt="avatar">
                                    <div class="group-info" style="flex:1;">
                                        <span class="group-name">${escapeHtml(g.name)}</span>
                                        <span class="group-id">ID: ${escapeHtml(g.id)}</span>
                                    </div>
                                    <button class="friend-delete-btn" onclick="leaveGroup('${escapeHtml(g.id)}', '${escapeHtml(g.name)}')">🚪</button>
                                `;
                                container.appendChild(div);
                            });
                        }
                        showModal('manageGroupsModal');
                    } else {
                        alert('获取群组列表失败：' + data.error);
                    }
                });
        }

        function leaveGroup(groupId, groupName) {
            if (!confirm(`确定要退出群组 “${groupName}” 吗？`)) return;
            const formData = new URLSearchParams();
            formData.append('groupId', groupId);
            formData.append('_csrf', CSRF_TOKEN);
            fetch('/index.php?action=leaveGroup', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        alert('已退出群组');
                        closeModal('manageGroupsModal');
                        location.reload();
                    } else {
                        alert('退出失败：' + (data.error || '未知错误'));
                    }
                });
        }

        // 显示群组基本信息
        function showGroupInfo(groupId) {
            fetch('/index.php?action=getGroupInfo&groupId=' + encodeURIComponent(groupId))
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        const g = data.group;
                        const created = new Date(g.created * 1000).toLocaleString();
                        const content = `
                            <div class="info-item"><span class="label">群ID</span><span class="value">${escapeHtml(g.id)}</span></div>
                            <div class="info-item"><span class="label">群名称</span><span class="value">${escapeHtml(g.name)}</span></div>
                            <div class="info-item"><span class="label">群主</span><span class="value">${escapeHtml(g.creator)}</span></div>
                            <div class="info-item"><span class="label">创建时间</span><span class="value">${escapeHtml(created)}</span></div>
                            <div class="info-item"><span class="label">成员数</span><span class="value">${g.members.length}</span></div>
                        `;
                        document.getElementById('groupInfoContent').innerHTML = content;
                        showModal('groupInfoModal');
                    } else {
                        alert('获取群组信息失败：' + data.error);
                    }
                });
        }

        // 显示群成员管理弹窗（仅群主）
        function showGroupMembers(groupId, groupName) {
            document.getElementById('groupNameSpan').textContent = groupName;
            fetch('/index.php?action=getGroupInfo&groupId=' + encodeURIComponent(groupId))
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        const group = data.group;
                        const members = group.members;
                        // 获取所有成员详细信息
                        Promise.all(members.map(mid => 
                            fetch('/index.php?action=searchUserInfo&userId=' + encodeURIComponent(mid))
                                .then(res => res.json())
                                .then(data => data.success ? data.user : null)
                        )).then(users => {
                            const container = document.getElementById('groupMembersList');
                            container.innerHTML = '';
                            users.forEach(u => {
                                if (!u) return;
                                const div = document.createElement('div');
                                div.className = 'member-item';
                                const avatarUrl = u.avatar ? '/' + u.avatar : 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2240%22%20height%3D%2240%22%20viewBox%3D%220%200%2040%2040%22%3E%3Ccircle%20cx%3D%2220%22%20cy%3D%2220%22%20r%3D%2220%22%20fill%3D%22%23ccc%22%2F%3E%3C%2Fsvg%3E';
                                div.innerHTML = `
                                    <img class="member-avatar" src="${avatarUrl}" alt="avatar">
                                    <div class="member-info">
                                        <span class="member-name">${escapeHtml(u.nickname || u.username)}</span>
                                        <span class="member-id">ID: ${escapeHtml(u.id)}</span>
                                    </div>
                                    ${u.id !== CURRENT_USER_ID ? `<button class="kick-btn" onclick="kickMember('${escapeHtml(groupId)}', '${escapeHtml(u.id)}', '${escapeHtml(u.nickname || u.username)}')">踢出</button>` : ''}
                                `;
                                container.appendChild(div);
                            });
                            showModal('groupMembersModal');
                        });
                    } else {
                        alert('获取群组信息失败：' + data.error);
                    }
                });
        }

        function kickMember(groupId, memberId, memberName) {
            if (!confirm(`确定要将 ${memberName} 踢出群组吗？`)) return;
            const formData = new URLSearchParams();
            formData.append('groupId', groupId);
            formData.append('memberId', memberId);
            formData.append('_csrf', CSRF_TOKEN);
            fetch('/index.php?action=removeGroupMember', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        alert('已踢出');
                        closeModal('groupMembersModal');
                        // 刷新群组列表（可选）
                    } else {
                        alert('踢出失败：' + (data.error || '未知错误'));
                    }
                });
        }

        // HTML 转义
        function escapeHtml(unsafe) {
            if (!unsafe) return '';
            return unsafe
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        // 暗黑模式切换
        (function() {
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

// 显示注销账号模态框
function showDeleteAccountModal() {
    document.getElementById('deletePassword').value = '';
    document.getElementById('deleteConfirm').value = '';
    showModal('deleteAccountModal');
}

// 执行注销请求
function deleteAccount() {
    const password = document.getElementById('deletePassword').value.trim();
    const confirmYes = document.getElementById('deleteConfirm').value.trim();
    if (!password) {
        alert('请输入密码');
        return;
    }
    if (confirmYes !== 'yes') {
        alert('请输入 "yes" 以确认注销');
        return;
    }
    const formData = new URLSearchParams();
    formData.append('password', password);
    formData.append('confirm_yes', confirmYes);
    formData.append('_csrf', CSRF_TOKEN);
    fetch('/index.php?action=deleteAccount', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert('账号已注销，即将返回首页');
            window.location.href = '/index.php';
        } else {
            alert('注销失败：' + (data.error || '未知错误'));
        }
    })
    .catch(err => {
        alert('请求失败：' + err);
    });
}

    </script>
</body>
</html>