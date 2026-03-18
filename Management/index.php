<?php
// ==================== Management/index.php ====================
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
define('GROUP_MSG_DIR', DATA_DIR . '/groups');

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

// 新增：获取与某好友的详细信息（包括关系建立时间）
function getFriendRelation($userId, $friendId) {
    $friends = getFriends($userId);
    foreach ($friends as $f) {
        if ($f['id'] == $friendId && isset($f['status']) && $f['status'] == 'accepted') {
            return $f;
        }
    }
    return null;
}

// 新增：统计两人消息数量（需要读取消息文件）
function getMessageStats($userId, $friendId) {
    $dir = DATA_DIR . '/' . $userId;
    $file = $dir . '/' . $friendId . '.json';
    if (!file_exists($file)) {
        return ['total' => 0, 'mine' => 0];
    }
    $content = file_get_contents($file);
    // 尝试解密（此处简化，假设未加密，实际需根据主文件逻辑调整）
    $messages = json_decode($content, true) ?: [];
    $mine = 0;
    foreach ($messages as $msg) {
        if ($msg['from'] == $userId) $mine++;
    }
    return ['total' => count($messages), 'mine' => $mine];
}

// 新增：获取群消息统计
function getGroupMessageStats($groupId, $userId) {
    $file = GROUP_MSG_DIR . '/' . $groupId . '.json';
    if (!file_exists($file)) {
        return ['total' => 0, 'mine' => 0];
    }
    // 注意：群消息是加密存储的，此处简化直接读取（实际需解密）
    $encrypted = file_get_contents($file);
    // 假设是明文（仅用于演示，真实环境需解密）
    $messages = json_decode($encrypted, true) ?: [];
    $mine = 0;
    foreach ($messages as $msg) {
        if ($msg['from'] == $userId) $mine++;
    }
    return ['total' => count($messages), 'mine' => $mine];
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
                'id'       => $friendUser['id'],
                'username' => $friendUser['username'],
                'nickname' => $friendUser['nickname'] ?? $friendUser['username'],
                'avatar'   => $friendUser['avatar'] ?? null,
                'vip'      => $friendUser['vip'] ?? false,
                'since'    => $f['since'] ?? 0,        // 关系建立时间
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
            'created' => $group['created'] ?? 0,
            // 成员加入时间暂未记录，默认为创建时间
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

// 视图路由
$view = $_GET['view'] ?? 'home';
$targetId = $_GET['id'] ?? '';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>NTC - 我的信息</title>
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
            --profile-bg: #ffffff;
            --gradient-start: rgba(255,255,255,0);
            --gradient-end: rgba(255,255,255,0.8);
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
            --profile-bg: #2d2d2d;
            --gradient-start: rgba(45,45,45,0);
            --gradient-end: rgba(45,45,45,0.9);
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
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .left-actions {
            display: flex;
            align-items: center;
            gap: 8px;
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




/* 右侧头像容器 */
.right-actions {
    display: flex;
    align-items: center;
    margin-left: auto; /* 靠右对齐 */
}

/* 头部小头像样式 */
.header-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid var(--border-color);
    background: var(--card-bg);
    cursor: pointer;
    transition: opacity 0.3s;
}

/* 如果之前有 .float-avatar 样式，可以删除或保留但不再使用 */

        .content {
            max-width: 900px;
            margin: 20px auto;
            padding: 0 20px;
        }

/* 个人资料卡片 */
.profile-card {
    background: var(--card-bg);
    border-radius: 24px;
    overflow: hidden;
    box-shadow: 0 8px 24px rgba(0,0,0,0.08);
    margin-bottom: 28px;
    border: 1px solid var(--border-color);
    transition: box-shadow 0.3s;
}
.profile-card:hover {
    box-shadow: 0 12px 28px rgba(0,0,0,0.12);
}

.profile-bg {
    height: 200px;
    background-size: cover;
    background-position: center;
    position: relative;
    display: flex;
    justify-content: center;
    align-items: flex-end;
}
.profile-bg::after {
    content: '';
    position: absolute;
    left: 0;
    bottom: 0;
    width: 100%;
    height: 60%;
    background: linear-gradient(to top, 
        rgba(0,0,0,0.4) 0%,
        rgba(0,0,0,0.1) 50%,
        transparent 100%);
    pointer-events: none;
}

.profile-avatar-container {
    position: relative;
    z-index: 2;
    transform: translateY(40%);
    margin-bottom: -20px;
}

.profile-avatar {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    border: 4px solid var(--card-bg);
    object-fit: cover;
    background: #ccc;
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    transition: border-color 0.3s;
}
.dark-mode .profile-avatar {
    border-color: #666; /* 暗黑模式下更亮边框 */
}

.profile-name {
    text-align: center;
    font-size: 26px;
    font-weight: 600;
    padding: 28px 20px 24px;
    margin: 60px 0 0 0;   /* 上边距 30px，其余为 0 */
    line-height: 1.3;
    text-shadow: 0 1px 2px rgba(0,0,0,0.05);
    color: var(--text-color);          /* 明确颜色 */
    display: block;
    word-break: break-word;
}

/* 信息卡片通用 */
.info-card {
    background: var(--card-bg);
    border-radius: 16px;
    margin-bottom: 20px;
    border: 1px solid var(--border-color);
    overflow: hidden; /* 确保圆角裁剪 */
}

.info-card h3 {
    font-size: 18px;
    margin: 0;
    padding: 16px 20px;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    gap: 8px;
}

/* 信息项样式 */
.info-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 20px;
    border-bottom: 1px solid var(--border-color);
    transition: background 0.2s;
}

.info-item:last-child {
    border-bottom: none; /* 最后一项无分割线 */
}

.info-item:hover {
    background: var(--friend-item-hover); /* 使用已有的悬停色 */
}

.info-item .label {
    color: #888;
    font-size: 14px;
}

.info-item .value {
    font-weight: 500;
    font-size: 16px;
}

        /* 操作按钮组 */
        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 20px 0;
        }
        .action-btn {
            padding: 8px 16px;
            border-radius: 30px;
            border: 1px solid var(--border-color);
            background: var(--btn-bg);
            color: var(--text-color);
            cursor: pointer;
            transition: 0.2s;
            font-size: 14px;
        }
        .action-btn:hover {
            background: var(--btn-hover-bg);
        }

        /* 列表项样式 */
        .list-item {
            display: flex;
            align-items: center;
            padding: 10px;
            border-radius: 12px;
            cursor: pointer;
            transition: background 0.2s;
            margin-bottom: 4px;
        }
        .list-item:hover {
            background: var(--friend-item-hover);
        }
        .item-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            margin-right: 12px;
            object-fit: cover;
        }
        .item-info {
            flex: 1;
        }
        .item-name {
            font-weight: 500;
            font-size: 16px;
        }
        .item-sub {
            font-size: 12px;
            color: #888;
        }
        .badge {
            background: #f5b342;
            color: #fff;
            font-size: 10px;
            padding: 2px 8px;
            border-radius: 12px;
            margin-left: 8px;
        }

        /* 返回按钮 */
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
            color: var(--text-color);
            text-decoration: none;
            font-size: 16px;
        }

        /* 删除按钮 */
        .delete-btn {
            background: #f56c6c;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 20px;
            cursor: pointer;
            font-size: 14px;
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

        /* 群成员列表 */
        .member-item {
            display: flex;
            align-items: center;
            padding: 10px;
            border-bottom: 1px solid var(--border-color);
        }
        .member-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            margin-right: 12px;
        }

        /* 底部注销/退出 */
        .bottom-actions {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin: 30px 0;
        }
        .bottom-btn {
            background: transparent;
            border: 1px solid var(--border-color);
            padding: 10px 30px;
            border-radius: 30px;
            color: var(--text-color);
            cursor: pointer;
            font-size: 16px;
        }
        .bottom-btn.logout {
            background: #07c160;
            color: white;
            border: none;
        }
        .bottom-btn.delete {
            background: #f56c6c;
            color: white;
            border: none;
        }

/* ---------- 列表项统一风格 ---------- */
.list-item {
    display: flex;
    align-items: center;
    padding: 12px 16px;
    border-bottom: 1px solid var(--border-color);
    cursor: pointer;
    transition: background 0.2s;
}
.list-item:last-child {
    border-bottom: none;
}
.list-item:hover {
    background: var(--friend-item-hover);
}

.item-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    margin-right: 14px;
    object-fit: cover;
    border: 1px solid var(--border-color);
}
.item-info {
    flex: 1;
    min-width: 0; /* 防止文字过长溢出 */
}
.item-name {
    font-weight: 500;
    font-size: 16px;
    display: flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.item-sub {
    font-size: 12px;
    color: #888;
    margin-top: 4px;
}
.badge {
    background: #f5b342;
    color: #fff;
    font-size: 10px;
    padding: 2px 8px;
    border-radius: 12px;
    margin-left: 8px;
    white-space: nowrap;
}

/* ---------- 好友申请预览项 ---------- */
.request-preview-item {
    display: flex;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px dashed var(--border-color);
    font-size: 14px;
}
.request-preview-item:last-child {
    border-bottom: none;
}
.request-preview-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    margin-right: 10px;
    object-fit: cover;
    border: 1px solid var(--border-color);
}
.request-preview-info {
    flex: 1;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
}
.request-preview-info strong {
    font-weight: 500;
}
.request-preview-info small {
    color: #888;
    margin-left: 6px;
}

/* ---------- 好友申请模态框条目 ---------- */
.request-item {
    display: flex;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid var(--border-color);
}
.request-item:last-child {
    border-bottom: none;
}
.request-item-avatar {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    margin-right: 14px;
    object-fit: cover;
    border: 1px solid var(--border-color);
}
.request-item-info {
    flex: 1;
    min-width: 0;
}
.request-item-name {
    font-weight: 500;
    font-size: 15px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.request-item-id {
    font-size: 12px;
    color: #888;
    margin-top: 4px;
}
.request-item-actions {
    display: flex;
    gap: 8px;
    margin-left: 10px;
}
.request-item-actions button {
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 13px;
    border: none;
    cursor: pointer;
    transition: 0.2s;
}
.request-item-actions .primary {
    background: #07c160;
    color: white;
}
.request-item-actions .secondary {
    background: #6c757d;
    color: white;
}
.request-item-actions button:hover {
    opacity: 0.8;
}

/* 调整卡片内边距，让列表项紧贴边缘 */
.info-card {
    padding: 0;  /* 移除内边距，让列表项占满 */
}
.info-card h3 {
    padding: 16px 20px;
    margin: 0;
}
/* 预览区域添加内边距 */
#friendRequestsPreview {
    padding: 0 20px;
}
/* 查看全部按钮保持外边距 */
.info-card .action-btn {
    margin: 16px 20px;
}
    </style>
</head>
<body>
    <!-- 头部 -->
<div class="header">
<div class="left-actions">
    <!-- 返回按钮：主界面返回首页，子界面返回管理主页 -->
    <a href="<?php echo $view === 'home' ? '/index.php' : '?view=home'; ?>" 
       class="dark-mode-btn" 
       id="backBtn" 
       title="<?php echo $view === 'home' ? '返回首页' : '返回管理'; ?>">
        <svg style="width: 1em; height: 1em; vertical-align: middle; fill: currentColor;" viewBox="0 0 1024 1024">
            <path d="M424.319032 885.693004 424.319032 620.123556 601.364307 620.123556 601.364307 885.693004 822.671669 885.693004 822.671669 531.60143 955.455881 531.60143 512.841158 133.24777 70.226434 531.60143 203.01167 531.60143 203.01167 885.693004Z"/>
        </svg>
    </a>
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
</div>
    <!-- 右侧固定显示的小头像 -->
    <div class="right-actions">
        <img class="header-avatar" id="headerAvatar" 
             src="<?php echo !empty($user['avatar']) ? '/' . htmlspecialchars($user['avatar']) : 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2240%22%20height%3D%2240%22%3E%3Ccircle%20cx%3D%2220%22%20cy%3D%2220%22%20r%3D%2220%22%20fill%3D%22%23ccc%22%2F%3E%3C%2Fsvg%3E'; ?>" 
             alt="avatar" 
             onclick="window.scrollTo({top:0,behavior:'smooth'});">
    </div>
</div>
    <!-- 主内容区，根据视图动态渲染 -->
    <div class="content" id="mainContent">
        <?php if ($view === 'home'): ?>
            <!-- ========== 主页视图 ========== -->
            <!-- 个人资料卡片 -->
            <div class="profile-card">
                <div class="profile-bg" style="background-image: url('<?php echo !empty($user['background']) ? '/' . htmlspecialchars($user['background']) : 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22400%22%20height%3D%22200%22%3E%3Crect%20width%3D%22400%22%20height%3D%22200%22%20fill%3D%22%23444%22%2F%3E%3C%2Fsvg%3E'; ?>');">
                    <div class="profile-avatar-container">
                        <img class="profile-avatar" id="profileAvatar" src="<?php echo !empty($user['avatar']) ? '/' . htmlspecialchars($user['avatar']) : 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22120%22%20height%3D%22120%22%3E%3Ccircle%20cx%3D%2260%22%20cy%3D%2260%22%20r%3D%2260%22%20fill%3D%22%23ccc%22%2F%3E%3C%2Fsvg%3E'; ?>" alt="avatar">
                    </div>
                </div>
                <div class="profile-name"><?php echo htmlspecialchars($user['nickname'] ?? $user['username']); ?></div>
            </div>

            <!-- 基本信息卡片 -->
            <div class="info-card">
                <h3>📋 基本信息</h3>
                <div class="info-item"><span class="label">数字ID</span><span class="value"><?php echo htmlspecialchars($user['id']); ?></span></div>
                <div class="info-item"><span class="label">用户名</span><span class="value"><?php echo htmlspecialchars($user['username']); ?></span></div>
                <div class="info-item"><span class="label">昵称</span><span class="value"><?php echo htmlspecialchars($user['nickname'] ?? $user['username']); ?></span></div>
                <div class="info-item"><span class="label">简介</span><span class="value"><?php echo htmlspecialchars($user['bio'] ?? '未填写'); ?></span></div>
                <div class="info-item"><span class="label">注册时间</span><span class="value"><?php echo $registered; ?></span></div>
                <div class="info-item"><span class="label">验证模式</span><span class="value"><?php echo $verifyModeText; ?></span></div>
            </div>

            <!-- 操作按钮组 -->
            <div class="action-buttons">
                <button class="action-btn" onclick="showChangeAvatarModal()">修改头像</button>
                <button class="action-btn" onclick="showChangeNicknameModal()">修改昵称</button>
                <button class="action-btn" onclick="showChangePasswordModal()">修改密码</button>
                <button class="action-btn" onclick="showChangeBackgroundModal()">修改背景图</button>
                <button class="action-btn" onclick="showEditProfileModal()">验证方式</button>
                <button class="action-btn" onclick="showEditBioModal()">修改简介</button>
            </div>

            <!-- 好友申请列表（简化：直接显示入口） -->
            <div class="info-card">
                <h3>📩 好友申请 <span id="requestCount" style="background:#f56c6c; color:white; border-radius:10px; padding:2px 8px; margin-left:10px;">0</span></h3>
                <div id="friendRequestsPreview">加载中...</div>
                <button class="action-btn" onclick="showFriendRequestsModal()" style="margin-top:10px;">查看全部</button>
            </div>

            <!-- 好友列表 -->
            <div class="info-card">
                <h3>👥 好友列表 (<?php echo count($friends); ?>)</h3>
                <?php if (empty($friends)): ?>
                    <div class="empty-tip">暂无好友</div>
                <?php else: ?>
                    <?php foreach ($friends as $f): ?>
                        <div class="list-item" onclick="location.href='?view=friend&id=<?php echo urlencode($f['id']); ?>'">
                            <img class="item-avatar" src="<?php echo !empty($f['avatar']) ? '/' . htmlspecialchars($f['avatar']) : 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2248%22%20height%3D%2248%22%3E%3Ccircle%20cx%3D%2224%22%20cy%3D%2224%22%20r%3D%2224%22%20fill%3D%22%23ccc%22%2F%3E%3C%2Fsvg%3E'; ?>" alt="avatar">
                            <div class="item-info">
                                <span class="item-name"><?php echo htmlspecialchars($f['nickname']); ?></span>
                                <span class="item-sub">ID: <?php echo htmlspecialchars($f['id']); ?></span>
                            </div>
                            <?php if (!empty($f['vip'])): ?><span class="badge">VIP</span><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- 群组列表 -->
            <div class="info-card">
                <h3>💬 群组列表 (<?php echo count($myGroups); ?>)</h3>
                <?php if (empty($myGroups)): ?>
                    <div class="empty-tip">暂无群组</div>
                <?php else: ?>
                    <?php foreach ($myGroups as $g): ?>
                        <div class="list-item" onclick="location.href='?view=group&id=<?php echo urlencode($g['id']); ?>'">
                            <img class="item-avatar" src="<?php echo !empty($g['avatar']) ? '/' . htmlspecialchars($g['avatar']) : 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2248%22%20height%3D%2248%22%3E%3Ccircle%20cx%3D%2224%22%20cy%3D%2224%22%20r%3D%2224%22%20fill%3D%22%23ccc%22%2F%3E%3C%2Fsvg%3E'; ?>" alt="avatar">
                            <div class="item-info">
                                <span class="item-name"><?php echo htmlspecialchars($g['name']); ?></span>
                                <span class="item-sub">ID: <?php echo htmlspecialchars($g['id']); ?></span>
                            </div>
                            <?php if ($g['creator'] == $userId): ?><span class="badge">群主</span><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- 底部注销/退出 -->
            <div class="bottom-actions">
                <button class="bottom-btn logout" onclick="logout()">退出登录</button>
                <button class="bottom-btn delete" onclick="showDeleteAccountModal()">注销账号</button>
            </div>

<?php elseif ($view === 'friend' && $targetId): ?>
    <?php
    $friend = getUserById($targetId);
    if (!$friend) {
        echo '<p>好友不存在</p>';
    } else {
        // 获取关系信息
        $relation = getFriendRelation($userId, $targetId);
        $sinceDays = $relation ? floor((time() - $relation['since']) / 86400) : 0;
        $stats = getMessageStats($userId, $targetId);
        $totalMsgs = $stats['total'];
        $myMsgs = $stats['mine'];
    ?>
    <!-- 好友详情视图 -->
    <div class="profile-card" style="margin-bottom:20px;">
        <div class="profile-bg" style="background-image: url('<?php echo !empty($friend['background']) ? '/' . htmlspecialchars($friend['background']) : 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22400%22%20height%3D%22200%22%3E%3Crect%20width%3D%22400%22%20height%3D%22200%22%20fill%3D%22%23444%22%2F%3E%3C%2Fsvg%3E'; ?>');">
            <div class="profile-avatar-container">
                <img class="profile-avatar" src="<?php echo !empty($friend['avatar']) ? '/' . htmlspecialchars($friend['avatar']) : 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22120%22%20height%3D%22120%22%3E%3Ccircle%20cx%3D%2260%22%20cy%3D%2260%22%20r%3D%2260%22%20fill%3D%22%23ccc%22%2F%3E%3C%2Fsvg%3E'; ?>" alt="avatar">
            </div>
        </div>
        <div class="profile-name"><?php echo htmlspecialchars($friend['nickname'] ?? $friend['username']); ?></div>
    </div>

    <div class="info-card" id="friendStatsCard">
        <h3>📊 好友数据</h3>
        <div class="info-item"><span class="label">成为好友</span><span class="value" id="friendSinceDays">加载中...</span></div>
        <div class="info-item"><span class="label">共计消息</span><span class="value" id="friendTotalMsgs">加载中...</span></div>
        <div class="info-item"><span class="label">我发送</span><span class="value" id="friendMyMsgs">加载中...</span></div>
    </div>

    <div style="text-align:center; margin:30px 0;">
        <button class="delete-btn" onclick="deleteFriend('<?php echo htmlspecialchars($targetId); ?>')">删除好友</button>
    </div>
    <?php } ?>

<?php elseif ($view === 'group' && $targetId): ?>
    <?php
    $group = getGroupInfo($targetId);
    if (!$group || !in_array($userId, $group['members'])) {
        echo '<p>群组不存在或您不是成员</p>';
    } else {
        // 获取群消息统计
        $stats = getGroupMessageStats($targetId, $userId);
        $totalMsgs = $stats['total'];
        $myMsgs = $stats['mine'];
        $isCreator = ($group['creator'] == $userId);
        // 获取所有成员详细信息
        $members = [];
        foreach ($group['members'] as $mid) {
            $mu = getUserById($mid);
            if ($mu) {
                $members[] = $mu;
            }
        }
        $created = date('Y-m-d H:i:s', $group['created']);
        $joined = $created;
    ?>
    <!-- 群组详情视图 -->
    <div class="profile-card" style="margin-bottom:20px;">
        <div class="profile-bg" style="background-image: url('<?php echo !empty($group['background']) ? '/' . htmlspecialchars($group['background']) : 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22400%22%20height%3D%22200%22%3E%3Crect%20width%3D%22400%22%20height%3D%22200%22%20fill%3D%22%23444%22%2F%3E%3C%2Fsvg%3E'; ?>');">
            <div class="profile-avatar-container">
                <img class="profile-avatar" src="<?php echo !empty($group['avatar']) ? '/' . htmlspecialchars($group['avatar']) : 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22120%22%20height%3D%22120%22%3E%3Ccircle%20cx%3D%2260%22%20cy%3D%2260%22%20r%3D%2260%22%20fill%3D%22%23ccc%22%2F%3E%3C%2Fsvg%3E'; ?>" alt="avatar">
            </div>
        </div>
        <div class="profile-name"><?php echo htmlspecialchars($group['name']); ?></div>
    </div>

    <?php if ($isCreator): ?>
        <div style="text-align:right; margin-bottom:10px;">
            <button class="action-btn" onclick="showGroupSettingsModal('<?php echo htmlspecialchars($group['id']); ?>', '<?php echo htmlspecialchars($group['name']); ?>')">⚙️ 设置</button>
        </div>
    <?php endif; ?>

    <div class="info-card" id="groupStatsCard">
        <h3>📅 群组信息</h3>
        <div class="info-item"><span class="label">创建时间</span><span class="value" id="groupCreated">加载中...</span></div>
        <div class="info-item"><span class="label">我加入时间</span><span class="value" id="groupJoined">加载中...</span></div>
        <div class="info-item"><span class="label">共计消息</span><span class="value" id="groupTotalMsgs">加载中...</span></div>
        <div class="info-item"><span class="label">我发送</span><span class="value" id="groupMyMsgs">加载中...</span></div>
    </div>

    <div class="info-card">
        <h3>👥 成员列表 (<?php echo count($members); ?>)</h3>
        <?php foreach ($members as $m): ?>
            <div class="member-item">
                <img class="member-avatar" src="<?php echo !empty($m['avatar']) ? '/' . htmlspecialchars($m['avatar']) : 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2240%22%20height%3D%2240%22%3E%3Ccircle%20cx%3D%2220%22%20cy%3D%2220%22%20r%3D%2220%22%20fill%3D%22%23ccc%22%2F%3E%3C%2Fsvg%3E'; ?>" alt="avatar">
                <div class="member-info">
                    <span class="member-name"><?php echo htmlspecialchars($m['nickname'] ?? $m['username']); ?></span>
                    <span class="member-id">ID: <?php echo htmlspecialchars($m['id']); ?></span>
                </div>
                <?php if ($m['id'] == $group['creator']): ?><span class="badge">群主</span><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div style="text-align:center; margin:30px 0;">
        <button class="delete-btn" onclick="leaveGroup('<?php echo htmlspecialchars($group['id']); ?>', '<?php echo htmlspecialchars($group['name']); ?>')">退出群聊</button>
    </div>
    <?php } ?>
        <?php endif; ?>
    </div>

    <!-- ========== 模态框 ========== -->

    <!-- 修改头像模态框（保留原有） -->
    <div class="modal" id="changeAvatarModal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('changeAvatarModal')">&times;</span>
            <h3>修改头像</h3>
            <input type="file" id="newAvatar" accept="image/*">
            <button class="primary" onclick="uploadAvatar()">上传</button>
            <button class="secondary" onclick="closeModal('changeAvatarModal')">取消</button>
        </div>
    </div>

    <!-- 修改昵称模态框 -->
    <div class="modal" id="changeNicknameModal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('changeNicknameModal')">&times;</span>
            <h3>修改昵称</h3>
            <input type="text" id="newNickname" placeholder="新昵称">
            <button class="primary" onclick="updateNickname()">保存</button>
            <button class="secondary" onclick="closeModal('changeNicknameModal')">取消</button>
        </div>
    </div>

    <!-- 修改密码模态框 -->
    <div class="modal" id="changePasswordModal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('changePasswordModal')">&times;</span>
            <h3>修改密码</h3>
            <input type="password" id="oldPassword" placeholder="旧密码">
            <input type="password" id="newPassword" placeholder="新密码">
            <input type="password" id="confirmPassword" placeholder="确认新密码">
            <button class="primary" onclick="updatePassword()">保存</button>
            <button class="secondary" onclick="closeModal('changePasswordModal')">取消</button>
        </div>
    </div>

    <!-- 修改背景图模态框（新增） -->
    <div class="modal" id="changeBackgroundModal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('changeBackgroundModal')">&times;</span>
            <h3>修改背景图</h3>
            <input type="file" id="newBackground" accept="image/*">
            <button class="primary" onclick="uploadBackground()">上传</button>
            <button class="secondary" onclick="closeModal('changeBackgroundModal')">取消</button>
        </div>
    </div>

    <!-- 验证方式模态框 -->
    <div class="modal" id="editProfileModal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editProfileModal')">&times;</span>
            <h3>修改验证方式</h3>
            <select id="profileVerifyMode">
                <option value="allow_all">允许任何人添加</option>
                <option value="need_verify">需要验证</option>
                <option value="deny_all">禁止添加</option>
            </select>
            <button class="primary" onclick="updateProfile()">保存</button>
            <button class="secondary" onclick="closeModal('editProfileModal')">取消</button>
        </div>
    </div>

    <!-- 修改简介模态框 -->
    <div class="modal" id="editBioModal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editBioModal')">&times;</span>
            <h3>修改简介</h3>
            <textarea id="bioContent" rows="4" placeholder="写点什么..."></textarea>
            <button class="primary" onclick="updateBio()">保存</button>
            <button class="secondary" onclick="closeModal('editBioModal')">取消</button>
        </div>
    </div>

    <!-- 好友申请列表模态框 -->
    <div class="modal" id="friendRequestsModal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('friendRequestsModal')">&times;</span>
            <h3>好友申请</h3>
            <div id="friendRequestsList" style="max-height:400px; overflow-y:auto;"></div>
        </div>
    </div>

    <!-- 注销账号模态框 -->
    <div class="modal" id="deleteAccountModal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('deleteAccountModal')">&times;</span>
            <h3>注销账号</h3>
            <p style="color:#f56c6c;">警告：此操作不可逆，所有数据将被永久删除！</p>
            <input type="password" id="deletePassword" placeholder="请输入密码">
            <input type="text" id="deleteConfirm" placeholder="输入 'yes' 确认">
            <button class="danger" onclick="deleteAccount()">确认注销</button>
            <button class="secondary" onclick="closeModal('deleteAccountModal')">取消</button>
        </div>
    </div>

    <!-- 群组设置模态框（群主用） -->
    <div class="modal" id="groupSettingsModal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('groupSettingsModal')">&times;</span>
            <h3>群组设置</h3>
            <input type="text" id="groupNewName" placeholder="新群名称">
            <input type="file" id="groupNewAvatar" accept="image/*" placeholder="群头像">
            <input type="file" id="groupNewBackground" accept="image/*" placeholder="群背景图">
            <button class="primary" onclick="updateGroupSettings()">保存</button>
            <button class="secondary" onclick="closeModal('groupSettingsModal')">取消</button>
        </div>
    </div>

    <script>
        // 当前用户ID和CSRF令牌
        const CURRENT_USER_ID = '<?php echo $userId; ?>';
        const CSRF_TOKEN = '<?php echo $csrf_token; ?>';

        // ---------- 通用函数 ----------
        function closeModal(id) {
            document.getElementById(id).style.display = 'none';
        }
        function showModal(id) {
            document.getElementById(id).style.display = 'flex';
        }

        // 退出登录
        async function logout() {
            const formData = new URLSearchParams();
            formData.append('_csrf', CSRF_TOKEN);
            const res = await fetch('/index.php?action=logout', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            });
            window.location.href = '/index.php';
        }

        // 注销账号
        function showDeleteAccountModal() { showModal('deleteAccountModal'); }
        async function deleteAccount() {
            const password = document.getElementById('deletePassword').value.trim();
            const confirmYes = document.getElementById('deleteConfirm').value.trim();
            if (!password || confirmYes !== 'yes') {
                alert('请填写密码并输入 "yes" 确认');
                return;
            }
            const formData = new URLSearchParams();
            formData.append('password', password);
            formData.append('confirm_yes', confirmYes);
            formData.append('_csrf', CSRF_TOKEN);
            const res = await fetch('/index.php?action=deleteAccount', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            });
            const data = await res.json();
            if (data.success) {
                alert('账号已注销');
                window.location.href = '/index.php';
            } else {
                alert('注销失败：' + data.error);
            }
        }

        // ---------- 个人资料操作 ----------
        function showChangeAvatarModal() { showModal('changeAvatarModal'); }
        async function uploadAvatar() {
            const file = document.getElementById('newAvatar').files[0];
            if (!file) return;
            const formData = new FormData();
            formData.append('avatar', file);
            formData.append('_csrf', CSRF_TOKEN);
            const res = await fetch('/index.php?action=uploadAvatar', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                location.reload();
            } else {
                alert('上传失败：' + data.error);
            }
        }

        function showChangeNicknameModal() { showModal('changeNicknameModal'); }
        async function updateNickname() {
            const nickname = document.getElementById('newNickname').value.trim();
            if (!nickname) return;
            const formData = new URLSearchParams();
            formData.append('nickname', nickname);
            formData.append('_csrf', CSRF_TOKEN);
            const res = await fetch('/index.php?action=updateUser', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            });
            const data = await res.json();
            if (data.success) {
                location.reload();
            } else {
                alert('更新失败：' + data.error);
            }
        }

        function showChangePasswordModal() { showModal('changePasswordModal'); }
        async function updatePassword() {
            const old = document.getElementById('oldPassword').value.trim();
            const newPwd = document.getElementById('newPassword').value.trim();
            const confirm = document.getElementById('confirmPassword').value.trim();
            if (!old || !newPwd || !confirm || newPwd !== confirm) {
                alert('请正确填写密码');
                return;
            }
            const formData = new URLSearchParams();
            formData.append('old_password', old);
            formData.append('password', newPwd);
            formData.append('_csrf', CSRF_TOKEN);
            const res = await fetch('/index.php?action=updateUser', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            });
            const data = await res.json();
            if (data.success) {
                alert('密码修改成功');
                closeModal('changePasswordModal');
            } else {
                alert('修改失败：' + data.error);
            }
        }

        function showChangeBackgroundModal() { showModal('changeBackgroundModal'); }
        async function uploadBackground() {
            const file = document.getElementById('newBackground').files[0];
            if (!file) return;
            const formData = new FormData();
            formData.append('background', file);  // 需要后端支持
            formData.append('_csrf', CSRF_TOKEN);
            // 假设有 action=uploadBackground
            const res = await fetch('/index.php?action=uploadBackground', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                location.reload();
            } else {
                alert('上传失败：' + data.error);
            }
        }

        function showEditProfileModal() { showModal('editProfileModal'); }
        async function updateProfile() {
            const mode = document.getElementById('profileVerifyMode').value;
            const formData = new URLSearchParams();
            formData.append('verify_mode', mode);
            formData.append('_csrf', CSRF_TOKEN);
            const res = await fetch('/index.php?action=updateUser', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            });
            const data = await res.json();
            if (data.success) {
                location.reload();
            } else {
                alert('更新失败：' + data.error);
            }
        }

        function showEditBioModal() { showModal('editBioModal'); }
        async function updateBio() {
            const bio = document.getElementById('bioContent').value.trim();
            const formData = new URLSearchParams();
            formData.append('bio', bio);
            formData.append('_csrf', CSRF_TOKEN);
            const res = await fetch('/index.php?action=updateUser', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            });
            const data = await res.json();
            if (data.success) {
                location.reload();
            } else {
                alert('更新失败：' + data.error);
            }
        }

        // ---------- 好友申请 ----------
        async function loadFriendRequests() {
            const res = await fetch('/index.php?action=getFriendRequests');
            const data = await res.json();
            if (data.success) {
                document.getElementById('requestCount').textContent = data.requests.length;
                // 预览
                const preview = document.getElementById('friendRequestsPreview');
                if (data.requests.length === 0) {
                    preview.innerHTML = '<div style="color:#888;">暂无新申请</div>';
                } else {
let html = '';
data.requests.slice(0, 3).forEach(r => {
    html += `
        <div class="request-preview-item">
            <img class="request-preview-avatar" src="${r.avatar ? '/' + r.avatar : 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2232%22%20height%3D%2232%22%3E%3Ccircle%20cx%3D%2216%22%20cy%3D%2216%22%20r%3D%2216%22%20fill%3D%22%23ccc%22%2F%3E%3C%2Fsvg%3E'}" alt="avatar">
            <div class="request-preview-info">
                <strong>${escapeHtml(r.nickname)}</strong>
                <small>ID: ${escapeHtml(r.id)}</small>
            </div>
        </div>
    `;
});
preview.innerHTML = html;
                }
                // 完整列表（用于模态框）
                const listDiv = document.getElementById('friendRequestsList');
                if (listDiv) {
                    listDiv.innerHTML = '';
                    data.requests.forEach(r => {
const div = document.createElement('div');
div.className = 'request-item';
div.innerHTML = `
    <img class="request-item-avatar" src="${r.avatar ? '/' + r.avatar : 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2244%22%20height%3D%2244%22%3E%3Ccircle%20cx%3D%2222%22%20cy%3D%2222%22%20r%3D%2222%22%20fill%3D%22%23ccc%22%2F%3E%3C%2Fsvg%3E'}" alt="avatar">
    <div class="request-item-info">
        <div class="request-item-name">${escapeHtml(r.nickname)}</div>
        <div class="request-item-id">ID: ${escapeHtml(r.id)}</div>
    </div>
    <div class="request-item-actions">
        <button class="primary" onclick="acceptRequest('${escapeHtml(r.id)}')">接受</button>
        <button class="secondary" onclick="rejectRequest('${escapeHtml(r.id)}')">拒绝</button>
    </div>
`;
listDiv.appendChild(div);
                    });
                }
            }
        }

        function showFriendRequestsModal() { showModal('friendRequestsModal'); }

        async function acceptRequest(requesterId) {
            const formData = new URLSearchParams();
            formData.append('requesterId', requesterId);
            formData.append('_csrf', CSRF_TOKEN);
            const res = await fetch('/index.php?action=acceptFriendRequest', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            });
            const data = await res.json();
            if (data.success) {
                location.reload();
            } else {
                alert('接受失败：' + data.error);
            }
        }

        async function rejectRequest(requesterId) {
            const formData = new URLSearchParams();
            formData.append('requesterId', requesterId);
            formData.append('_csrf', CSRF_TOKEN);
            const res = await fetch('/index.php?action=rejectFriendRequest', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            });
            const data = await res.json();
            if (data.success) {
                location.reload();
            } else {
                alert('拒绝失败：' + data.error);
            }
        }

        // ---------- 好友详情操作 ----------
        async function deleteFriend(friendId) {
            if (!confirm('确定删除该好友吗？')) return;
            const formData = new URLSearchParams();
            formData.append('friendId', friendId);
            formData.append('_csrf', CSRF_TOKEN);
            const res = await fetch('/index.php?action=deleteFriend', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            });
            const data = await res.json();
            if (data.success) {
                alert('好友已删除');
                window.location.href = '?view=home';
            } else {
                alert('删除失败：' + data.error);
            }
        }

        // ---------- 群组操作 ----------
        async function leaveGroup(groupId, groupName) {
            if (!confirm(`确定退出群组 “${groupName}” 吗？`)) return;
            const formData = new URLSearchParams();
            formData.append('groupId', groupId);
            formData.append('_csrf', CSRF_TOKEN);
            const res = await fetch('/index.php?action=leaveGroup', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            });
            const data = await res.json();
            if (data.success) {
                alert('已退出群组');
                window.location.href = '?view=home';
            } else {
                alert('退出失败：' + data.error);
            }
        }

        function showGroupSettingsModal(groupId, groupName) {
            document.getElementById('groupNewName').value = groupName;
            // 可以填充现有信息
            showModal('groupSettingsModal');
            window.currentGroupId = groupId;
        }

        async function updateGroupSettings() {
            const newName = document.getElementById('groupNewName').value.trim();
            const avatarFile = document.getElementById('groupNewAvatar').files[0];
            const bgFile = document.getElementById('groupNewBackground').files[0];
            const formData = new FormData();
            formData.append('groupId', window.currentGroupId);
            if (newName) formData.append('name', newName);
            if (avatarFile) formData.append('avatar', avatarFile);
            if (bgFile) formData.append('background', bgFile);
            formData.append('_csrf', CSRF_TOKEN);
            const res = await fetch('/index.php?action=updateGroup', {  // 需要后端支持
                method: 'POST',
                body: formData
            });
            const data = await res.json();
            if (data.success) {
                alert('更新成功');
                location.reload();
            } else {
                alert('更新失败：' + data.error);
            }
        }



        // 初始化
        loadFriendRequests();

// 根据当前视图加载统计数据
const urlParams = new URLSearchParams(window.location.search);
const view = urlParams.get('view');
const id = urlParams.get('id');
if (view === 'friend' && id) {
    loadFriendStats(id);
} else if (view === 'group' && id) {
    loadGroupStats(id);
}

        // 暗黑模式
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

        function escapeHtml(unsafe) {
            if (!unsafe) return '';
            return unsafe.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
        }

// ---------- 加载好友统计数据 ----------
async function loadFriendStats(friendId) {
    try {
        const res = await fetch('/index.php?action=getFriendStats&friendId=' + encodeURIComponent(friendId));
        const data = await res.json();
        if (data.success) {
            document.getElementById('friendSinceDays').textContent = data.since_days + ' 天';
            document.getElementById('friendTotalMsgs').textContent = data.total_messages + ' 条';
            document.getElementById('friendMyMsgs').textContent = data.my_messages + ' 条';
        } else {
            document.getElementById('friendSinceDays').textContent = '获取失败';
            document.getElementById('friendTotalMsgs').textContent = '获取失败';
            document.getElementById('friendMyMsgs').textContent = '获取失败';
        }
    } catch (e) {
        console.error('加载好友统计失败', e);
        document.getElementById('friendSinceDays').textContent = '错误';
        document.getElementById('friendTotalMsgs').textContent = '错误';
        document.getElementById('friendMyMsgs').textContent = '错误';
    }
}

// ---------- 加载群组统计数据 ----------
async function loadGroupStats(groupId) {
    try {
        const res = await fetch('/index.php?action=getGroupStats&groupId=' + encodeURIComponent(groupId));
        const data = await res.json();
        if (data.success) {
            const createdDate = new Date(data.created * 1000).toLocaleString();
            const joinedDate = new Date(data.joined * 1000).toLocaleString();
            document.getElementById('groupCreated').textContent = createdDate;
            document.getElementById('groupJoined').textContent = joinedDate;
            document.getElementById('groupTotalMsgs').textContent = data.total_messages + ' 条';
            document.getElementById('groupMyMsgs').textContent = data.my_messages + ' 条';
        } else {
            document.getElementById('groupCreated').textContent = '获取失败';
            document.getElementById('groupJoined').textContent = '获取失败';
            document.getElementById('groupTotalMsgs').textContent = '获取失败';
            document.getElementById('groupMyMsgs').textContent = '获取失败';
        }
    } catch (e) {
        console.error('加载群组统计失败', e);
        document.getElementById('groupCreated').textContent = '错误';
        document.getElementById('groupJoined').textContent = '错误';
        document.getElementById('groupTotalMsgs').textContent = '错误';
        document.getElementById('groupMyMsgs').textContent = '错误';
    }
}

    </script>
</body>
</html>