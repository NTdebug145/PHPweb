<?php

// 设置 Session Cookie 路径为根目录，使子目录也能读取
session_set_cookie_params(['path' => '/']);
session_start();


// 定义常量
define('DATA_DIR', __DIR__ . '/data');
define('USERS_FILE', DATA_DIR . '/users.json');
define('AVATAR_DIR', DATA_DIR . '/avatars');
define('UPLOAD_DIR', DATA_DIR . '/upFile');
define('FILE_NAME_JSON', UPLOAD_DIR . '/FileName/FileN.json');

// 创建目录
foreach ([DATA_DIR, AVATAR_DIR, UPLOAD_DIR, UPLOAD_DIR . '/FileName'] as $dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }
}

// 初始化JSON文件
if (!file_exists(USERS_FILE)) {
    file_put_contents(USERS_FILE, json_encode([]));
}
if (!file_exists(FILE_NAME_JSON)) {
    file_put_contents(FILE_NAME_JSON, json_encode([]));
}

// 处理API请求
$action = isset($_GET['action']) ? $_GET['action'] : '';
if ($action) {
    header('Content-Type: application/json');
    try {
        switch ($action) {
            case 'register':
                echo json_encode(handleRegister());
                break;
            case 'login':
                echo json_encode(handleLogin());
                break;
            case 'logout':
                echo json_encode(handleLogout());
                break;
            case 'getUserInfo':
                echo json_encode(handleGetUserInfo());
                break;
            case 'updateUser':
                echo json_encode(handleUpdateUser());
                break;
            case 'uploadAvatar':
                echo json_encode(handleUploadAvatar());
                break;
            case 'searchUser':
                echo json_encode(handleSearchUser());
                break;
            case 'searchUserInfo':
                echo json_encode(handleSearchUserInfo());
                break;
            case 'sendFriendRequest':
                echo json_encode(handleSendFriendRequest());
                break;
            case 'acceptFriendRequest':
                echo json_encode(handleAcceptFriendRequest());
                break;
            case 'rejectFriendRequest':
                echo json_encode(handleRejectFriendRequest());
                break;
            case 'getFriendRequests':
                echo json_encode(handleGetFriendRequests());
                break;
            case 'getFriends':
                echo json_encode(handleGetFriends());
                break;
            case 'getMessages':
                echo json_encode(handleGetMessages());
                break;
            case 'sendMessage':
                echo json_encode(handleSendMessage());
                break;
            case 'uploadImage':
                echo json_encode(handleUploadImage());
                break;
            case 'getImage':
                handleGetImage();
                break;
            case 'deleteFriend':
                echo json_encode(handleDeleteFriend());
                break;
            default:
                echo json_encode(['error' => '无效操作']);
        }
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}


// 处理删除好友
function handleDeleteFriend() {
    if (!isset($_SESSION['user_id'])) {
        return ['success' => false, 'error' => '未登录'];
    }
    $currentId = $_SESSION['user_id'];
    $friendId = $_POST['friendId'] ?? '';
    if (!$friendId) {
        return ['success' => false, 'error' => '缺少好友ID'];
    }

    // 从自己的好友列表中移除
    $myFriends = getFriends($currentId);
    $originalCount = count($myFriends);
    $myFriends = array_filter($myFriends, function($f) use ($friendId) {
        return !($f['id'] == $friendId && ($f['status'] ?? '') == 'accepted');
    });
    if (count($myFriends) == $originalCount) {
        return ['success' => false, 'error' => '好友关系不存在'];
    }
    saveFriends($currentId, array_values($myFriends));

    // 从对方好友列表中移除自己
    $theirFriends = getFriends($friendId);
    $theirFriends = array_filter($theirFriends, function($f) use ($currentId) {
        return !($f['id'] == $currentId && ($f['status'] ?? '') == 'accepted');
    });
    saveFriends($friendId, array_values($theirFriends));

    // 删除双方的聊天记录文件
    $myMsgFile = DATA_DIR . '/' . $currentId . '/' . $friendId . '.json';
    if (file_exists($myMsgFile)) {
        unlink($myMsgFile);
    }
    $theirMsgFile = DATA_DIR . '/' . $friendId . '/' . $currentId . '.json';
    if (file_exists($theirMsgFile)) {
        unlink($theirMsgFile);
    }

    return ['success' => true];
}

// 以下为HTML界面
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>NTC</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Microsoft YaHei', sans-serif; }

/* ========== 浅色模式变量（默认） ========== */
:root {
    --bg-color: #f0f2f5;
    --header-bg: #fff;
    --sidebar-bg: #fff;
    --chat-bg: #f5f7fa;
    --text-color: #333;
    --border-color: #ddd;
    --bubble-bg: #fff;
    --own-bubble-bg: #95ec69;
    --input-bg: #fff;
    --modal-bg: #fff;
    --dropdown-bg: #fff;
    --dropdown-hover: #f5f5f5;
    --friend-item-hover: #f0f0f0;
    --friend-item-active: #e0e0e0;
}

/* ========== 暗黑模式变量覆盖 ========== */
.dark-mode {
    --bg-color: #1e1e1e;
    --header-bg: #2d2d2d;
    --sidebar-bg: #2d2d2d;
    --chat-bg: #1e1e1e;
    --text-color: #eee;
    --border-color: #444;
    --bubble-bg: #3a3a3a;
    --own-bubble-bg: #666666;
    --input-bg: #262626;
    --modal-bg: #2d2d2d;
    --dropdown-bg: #2d2d2d;
    --dropdown-hover: #262626;
    --friend-item-hover: #262626;
    --friend-item-active: #3f3f3f;
}

/* 应用变量 */
body {
    height: 100vh;
    background: var(--bg-color);
    color: var(--text-color);
    transition: background-color 0.3s, color 0.3s;
}
.app-container {
    height: 100vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.hidden {
    display: none !important;
}
.header {
    height: 60px;
    background: var(--header-bg);
    display: flex;
    justify-content: space-between;  /* 改为左右分布 */
    align-items: center;
    padding: 0 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    position: relative;
    z-index: 10;
    flex-shrink: 0;
    border-bottom: 1px solid var(--border-color);
}
/* 左侧暗黑模式按钮 */
.header-left {
    display: flex;
    align-items: center;
}
.dark-mode-btn {
    background: transparent;
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
    background: rgba(128,128,128,0.2);
}
.user-info {
    display: flex;
    align-items: center;
    cursor: pointer;
    padding: 5px;
    border-radius: 30px;
    transition: background 0.2s;
}
.user-info:hover {
    background: var(--friend-item-hover);
}
.avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #ccc;
    margin-right: 10px;
    object-fit: cover;
}
.dropdown-menu {
    position: absolute;
    top: 60px;
    right: 20px;
    background: var(--dropdown-bg);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    display: none;
    flex-direction: column;
    min-width: 150px;
    z-index: 1000;
    color: var(--text-color);
}
.dropdown-menu a {
    padding: 10px 15px;
    text-decoration: none;
    color: var(--text-color);
    border-bottom: 1px solid var(--border-color);
    cursor: pointer;
    font-size: 14px;
}
.dropdown-menu a:last-child {
    border-bottom: none;
}
.dropdown-menu a:hover {
    background: var(--dropdown-hover);
}
.main {
    flex: 1;
    display: flex;
    overflow: hidden;
    background: var(--bg-color);
}
.sidebar {
    width: 260px;
    background: var(--sidebar-bg);
    border-right: 1px solid var(--border-color);
    display: flex;
    flex-direction: column;
    overflow-y: auto;
    flex-shrink: 0;
}
.chat-area {
    flex: 1;
    display: flex;
    flex-direction: column;
    background: var(--chat-bg);
    overflow: hidden;
    min-width: 0;
}
.chat-header {
    height: 50px;
    background: var(--header-bg);
    border-bottom: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    padding: 0 20px;
    font-weight: bold;
    color: var(--text-color);
    flex-shrink: 0;
}
.chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 15px;
}

/* 消息整体布局 */
.message {
    display: flex;
    align-items: flex-start;
    margin-bottom: 15px;
}
.message.own {
    flex-direction: row-reverse;
}
.message .avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    margin: 0 10px;
    flex-shrink: 0;
}

/* 右侧列（名称 + 气泡） */
.message .right-col {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    max-width: 100%;
    overflow: hidden;
    align-items: flex-start;
}
.message.own .right-col {
    align-items: flex-end;
}

/* 发送者名称 */
.message .sender-name {
    font-size: 13px;
    font-weight: bold;
    color: var(--text-color);
    opacity: 0.8;
    margin-bottom: 4px;
    word-break: break-word;
    overflow-wrap: break-word;
}
.message.own .sender-name {
    text-align: right;
}

.message .bubble {
    background: var(--bubble-bg);
    padding: 10px 15px;
    border-radius: 18px;
    box-shadow: 0 1px 2px rgba(0,0,0,0.1);
    word-wrap: break-word;
    max-width: 70%;
    white-space: pre-wrap;
    line-height: 1.4;
    display: inline-block;
    color: var(--text-color);
}
.message.own .bubble {
    background: var(--own-bubble-bg);
}
.message .bubble .content {
    font-size: 14px;
    word-break: break-word;
    overflow-wrap: break-word;
}
.message .bubble img {
    max-width: 200px;
    max-height: 200px;
    border-radius: 8px;
    cursor: pointer;
    margin-top: 5px;
}

.chat-input {
    background: var(--input-bg);
    border-top: 1px solid var(--border-color);
    padding: 15px 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}
.chat-input textarea {
    flex: 1;
    height: 60px;
    padding: 10px;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    resize: none;
    font-size: 14px;
    outline: none;
    line-height: 1.4;
    background: var(--input-bg);
    color: var(--text-color);
}
.chat-input button {
    height: 40px;
    padding: 0 20px;
    background: #07c160;
    color: white;
    border: none;
    border-radius: 20px;
    cursor: pointer;
    font-size: 14px;
    font-weight: bold;
}
.chat-input button:hover {
    background: #06a858;
}
.chat-input .image-btn {
    background: var(--input-bg);
    color: var(--text-color);
    border: 1px solid var(--border-color);
}
.chat-input .image-btn:hover {
    background: var(--friend-item-hover);
}
.friend-item {
    padding: 13.9px 15px;
    border-bottom: 1px solid var(--border-color);
    cursor: pointer;
    transition: background 0.2s;
    display: flex;
    align-items: center;
    color: var(--text-color);
}
.friend-item:hover {
    background: var(--friend-item-hover);
}
.friend-item.active {
    background: var(--friend-item-active);
}
.friend-item .friend-avatar {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    margin-right: 10px;
    background: #ccc;
}
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
    box-shadow: 0 5px 15px rgba(0,0,0,0.3);
    color: var(--text-color);
}
.modal-content h3 {
    margin-bottom: 20px;
    font-size: 18px;
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
.modal-content button.secondary {
    background: #6c757d;
    color: white;
}
.modal-content .close {
    float: right;
    font-size: 24px;
    font-weight: bold;
    cursor: pointer;
    color: var(--text-color);
}
.error {
    color: red;
    font-size: 0.9em;
    margin-top: 5px;
}
.login-register {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 100vh;
    background: var(--bg-color);
}
.login-register .box {
    background: var(--modal-bg);
    padding: 30px;
    border-radius: 12px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.2);
    width: 350px;
    color: var(--text-color);
}
.login-register .box h2 {
    margin-bottom: 20px;
    text-align: center;
}
.login-register .box input {
    width: 100%;
    padding: 10px;
    margin: 10px 0;
    border: 1px solid var(--border-color);
    border-radius: 4px;
    background: var(--input-bg);
    color: var(--text-color);
}
.login-register .box button {
    width: 100%;
    padding: 10px;
    background: #07c160;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 16px;
}
.login-register .box .toggle {
    text-align: center;
    margin-top: 15px;
    color: #07c160;
    cursor: pointer;
}
.request-item {
    display: flex;
    align-items: center;
    padding: 10px;
    border-bottom: 1px solid var(--border-color);
}
.request-item img {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    margin-right: 10px;
}
.request-item .info {
    flex: 1;
    color: var(--text-color);
}
.request-item .info div {
    margin-bottom: 3px;
    font-size: 14px;
}
.request-item button {
    margin-left: 5px;
    padding: 5px 10px;
    font-size: 13px;
}
/* 菜单按钮动画 */
#menuToggle {
    transition: transform 0.3s ease;
}
#menuToggle.rotate {
    transform: rotate(270deg);
}


/* 太阳/月亮 SVG 显示控制 */
.sun-svg { display: none; }
.moon-svg { display: inline-block; }
html.dark-mode .sun-svg { display: inline-block; }
html.dark-mode .moon-svg { display: none; }

</style>
</head>
<body>
    <!-- 登录/注册界面 -->
    <div id="loginRegisterContainer" class="login-register">
        <div class="box" id="loginBox">
            <h2>登录</h2>
            <input type="text" id="loginUsername" placeholder="用户名或数字ID">
            <input type="password" id="loginPassword" placeholder="密码">
            <button onclick="doLogin()">登录</button>
            <div class="toggle" onclick="showRegister()">没有账号？立即注册</div>
        </div>
        <div class="box hidden" id="registerBox">
            <h2>注册</h2>
            <input type="text" id="regUsername" placeholder="用户名 (登录用)">
            <input type="password" id="regPassword" placeholder="密码">
            <input type="file" id="regAvatar" accept="image/*">
            <button onclick="doRegister()">注册</button>
            <div class="toggle" onclick="showLogin()">已有账号？去登录</div>
        </div>
    </div>

    <!-- 主聊天界面 (初始隐藏) -->
    <div id="mainApp" class="app-container hidden">
        <div class="header">
            <!-- 左侧暗黑模式按钮 -->
            <div class="header-left">
                <button class="dark-mode-btn" id="menuToggle">☰</button>


<button id="darkModeToggle" class="dark-mode-btn">
    <!-- 太阳 SVG -->
                    <svg class="sun-svg" style="width: 1em;height: 1em;vertical-align: middle;fill: currentColor;overflow: hidden;" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="787">
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
            <!-- 右侧用户信息 -->
            <div class="user-info" id="userInfo" onclick="toggleMenu(event)">
                <img class="avatar" id="avatar" src="" alt="avatar">
                <span id="username"></span>
            </div>
            <!-- 下拉菜单 (包含好友申请) -->
            <div class="dropdown-menu" id="dropdownMenu">
                <a onclick="showFriendRequests()">好友申请 <span id="requestCount" style="background:#f56c6c; color:white; border-radius:10px; padding:2px 6px; margin-left:5px;">0</span></a>
                <a onclick="deleteCurrentFriend()">删除好友</a>
                <a onclick="showChangeAvatar()">修改头像</a>
                <a onclick="showChangeNickname()">修改用户名</a>
                <a onclick="showChangePassword()">修改密码</a>
                <a onclick="showEditProfile()">修改资料</a>
                <a onclick="showEditBio()">用户简介</a>
                <a onclick="showSearchUserInfo()">查看用户信息</a>
                <a onclick="logout()">退出登录</a>
            </div>

<!-- 左侧导航菜单（可扩展） -->
<div class="dropdown-menu" id="leftMenu" style="left: 20px; right: auto; display: none;">
    <a href="#" onclick="goToAnnouncement()">公告</a>
    <a href="#" onclick="goToUpload(); return false;">压缩包上传</a>
    <a href="#" onclick="goToNTwiki(); return false;">NTwiki</a>
    <a href="#" onclick="goToVIP(); return false;">VIP用户</a>
</div>

        </div>
        <div class="main">
            <div class="sidebar">
                <!-- 好友列表 (不再包含申请按钮) -->
                <div id="friendList"></div>
            </div>
            <div class="chat-area">
                <div class="chat-header" id="chatHeader">
                    请选择好友开始聊天
                </div>
                <div class="chat-messages" id="chatMessages"></div>
                <div class="chat-input">
                    <textarea id="messageInput" placeholder="输入消息..."></textarea>
                    <button onclick="sendMessage()"><svg class="icon" style="width: 1em;height: 1em;vertical-align: middle;fill: currentColor;overflow: hidden;" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="11646"><path d="M0 1024l106.496-474.112 588.8-36.864-588.8-39.936L0 0l1024 512z" fill="" p-id="11647"></path></svg></button>
                    <button class="image-btn" onclick="document.getElementById('imageUpload').click()"><svg class="icon" style="width: 1em;height: 1em;vertical-align: middle;fill: currentColor;overflow: hidden;" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="12502"><path d="M335.36 440.32c38.4 0 71.68-30.72 71.68-71.68 0-38.4-30.72-71.68-71.68-71.68-38.4 0-71.68 30.72-71.68 71.68 2.56 40.96 33.28 71.68 71.68 71.68z m0 0" fill="" p-id="12503"></path><path d="M652.8 396.8L445.44 691.2l-107.52-151.04-179.2 253.44h704l-209.92-396.8z m0 0" fill="" p-id="12504"></path><path d="M934.4 107.52h-844.8c-20.48 0-35.84 15.36-35.84 35.84v739.84c0 20.48 15.36 35.84 35.84 35.84h844.8c20.48 0 35.84-15.36 35.84-35.84V143.36c0-20.48-15.36-35.84-35.84-35.84z m-35.84 739.84H125.44V176.64h775.68v670.72h-2.56z m0 0" fill="" p-id="12505"></path></svg></button>
                    <input type="file" id="imageUpload" accept="image/*" style="display:none;">
                </div>
            </div>
        </div>
    </div>

    <!-- 模态框：修改头像 -->
    <div class="modal" id="changeAvatarModal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('changeAvatarModal')">&times;</span>
            <h3><svg class="icon" style="width: 1em;height: 1em;vertical-align: middle;fill: currentColor;overflow: hidden;" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="5477"><path d="M775.84 392.768l-155.2-172.352L160.768 643.264l-38.368 187.936 190.56-12.832zM929.952 229.952l-131.2-150.944-0.288-0.32a16 16 0 0 0-22.592-0.96l-131.168 120.576 155.168 172.352 128.832-118.464a15.936 15.936 0 0 0 1.248-22.24zM96 896h832v64H96z" p-id="5478"></path></svg> 修改头像</h3>
            <input type="file" id="newAvatar" accept="image/*">
            <button class="primary" onclick="uploadAvatar()">上传</button>
            <button class="secondary" onclick="closeModal('changeAvatarModal')">取消</button>
        </div>
    </div>

    <!-- 模态框：修改用户名（昵称） -->
    <div class="modal" id="changeNicknameModal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('changeNicknameModal')">&times;</span>
            <h3><svg class="icon" style="width: 1em;height: 1em;vertical-align: middle;fill: currentColor;overflow: hidden;" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="5477"><path d="M775.84 392.768l-155.2-172.352L160.768 643.264l-38.368 187.936 190.56-12.832zM929.952 229.952l-131.2-150.944-0.288-0.32a16 16 0 0 0-22.592-0.96l-131.168 120.576 155.168 172.352 128.832-118.464a15.936 15.936 0 0 0 1.248-22.24zM96 896h832v64H96z" p-id="5478"></path></svg> 修改昵称</h3>
            <input type="text" id="newNickname" placeholder="新昵称">
            <button class="primary" onclick="updateNickname()">保存</button>
            <button class="secondary" onclick="closeModal('changeNicknameModal')">取消</button>
        </div>
    </div>

    <!-- 模态框：修改密码 -->
    <div class="modal" id="changePasswordModal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('changePasswordModal')">&times;</span>
            <h3><svg class="icon" style="width: 1em;height: 1em;vertical-align: middle;fill: currentColor;overflow: hidden;" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="5477"><path d="M775.84 392.768l-155.2-172.352L160.768 643.264l-38.368 187.936 190.56-12.832zM929.952 229.952l-131.2-150.944-0.288-0.32a16 16 0 0 0-22.592-0.96l-131.168 120.576 155.168 172.352 128.832-118.464a15.936 15.936 0 0 0 1.248-22.24zM96 896h832v64H96z" p-id="5478"></path></svg> 修改密码</h3>
            <input type="password" id="oldPassword" placeholder="旧密码">
            <input type="password" id="newPassword" placeholder="新密码">
            <input type="password" id="confirmPassword" placeholder="确认新密码">
            <button class="primary" onclick="updatePassword()">保存</button>
            <button class="secondary" onclick="closeModal('changePasswordModal')">取消</button>
        </div>
    </div>

    <!-- 模态框：修改资料（好友验证方式） -->
    <div class="modal" id="editProfileModal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editProfileModal')">&times;</span>
            <h3><svg class="icon" style="width: 1em;height: 1em;vertical-align: middle;fill: currentColor;overflow: hidden;" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="5477"><path d="M775.84 392.768l-155.2-172.352L160.768 643.264l-38.368 187.936 190.56-12.832zM929.952 229.952l-131.2-150.944-0.288-0.32a16 16 0 0 0-22.592-0.96l-131.168 120.576 155.168 172.352 128.832-118.464a15.936 15.936 0 0 0 1.248-22.24zM96 896h832v64H96z" p-id="5478"></path></svg> 修改资料</h3>
            <label>好友验证方式:</label>
            <select id="profileVerifyMode">
                <option value="allow_all">允许任何人添加</option>
                <option value="need_verify">需要验证</option>
                <option value="deny_all">禁止添加</option>
            </select>
            <button class="primary" onclick="updateProfile()">保存</button>
            <button class="secondary" onclick="closeModal('editProfileModal')">取消</button>
        </div>
    </div>

    <!-- 模态框：修改用户简介 -->
    <div class="modal" id="editBioModal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editBioModal')">&times;</span>
            <h3><svg class="icon" style="width: 1em;height: 1em;vertical-align: middle;fill: currentColor;overflow: hidden;" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="5477"><path d="M775.84 392.768l-155.2-172.352L160.768 643.264l-38.368 187.936 190.56-12.832zM929.952 229.952l-131.2-150.944-0.288-0.32a16 16 0 0 0-22.592-0.96l-131.168 120.576 155.168 172.352 128.832-118.464a15.936 15.936 0 0 0 1.248-22.24zM96 896h832v64H96z" p-id="5478"></path></svg> 修改简介</h3>
            <textarea id="bioContent" rows="4" placeholder="写点什么..."></textarea>
            <button class="primary" onclick="updateBio()">保存</button>
            <button class="secondary" onclick="closeModal('editBioModal')">取消</button>
        </div>
    </div>

    <!-- 模态框：查看用户信息 (增加显示当前用户ID) -->
    <div class="modal" id="searchUserInfoModal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('searchUserInfoModal')">&times;</span>
            <h3><svg class="icon" style="width: 1em;height: 1em;vertical-align: middle;fill: currentColor;overflow: hidden;" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="6159"><path d="M469.333333 768c-166.4 0-298.666667-132.266667-298.666666-298.666667s132.266667-298.666667 298.666666-298.666666 298.666667 132.266667 298.666667 298.666666-132.266667 298.666667-298.666667 298.666667z m0-85.333333c119.466667 0 213.333333-93.866667 213.333334-213.333334s-93.866667-213.333333-213.333334-213.333333-213.333333 93.866667-213.333333 213.333333 93.866667 213.333333 213.333333 213.333334z m251.733334 0l119.466666 119.466666-59.733333 59.733334-119.466667-119.466667 59.733334-59.733333z" fill="currentColor" p-id="6160"></path></svg> 查看用户信息</h3>
            <p><strong><svg class="icon" style="width: 1em;height: 1em;vertical-align: middle;fill: currentColor;overflow: hidden;" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="17500"><path d="M928 160H96c-17.7 0-32 14.3-32 32v640c0 17.7 14.3 32 32 32h832c17.7 0 32-14.3 32-32V192c0-17.7-14.3-32-32-32z m-40 632H136V232h752v560zM610.3 476h123.4c1.3 0 2.3-3.6 2.3-8v-48c0-4.4-1-8-2.3-8H610.3c-1.3 0-2.3 3.6-2.3 8v48c0 4.4 1 8 2.3 8zM615.1 620h185.7c3.9 0 7.1-3.6 7.1-8v-48c0-4.4-3.2-8-7.1-8H615.1c-3.9 0-7.1 3.6-7.1 8v48c0 4.4 3.2 8 7.1 8zM224 673h43.9c4.2 0 7.6-3.3 7.9-7.5 3.8-50.5 46-90.5 97.2-90.5s93.4 40 97.2 90.5c0.3 4.2 3.7 7.5 7.9 7.5H522c4.6 0 8.2-3.8 8-8.4-2.8-53.3-32-99.7-74.6-126.1 18.1-19.9 29.1-46.4 29.1-75.5 0-61.9-49.9-112-111.4-112s-111.4 50.1-111.4 112c0 29.1 11 55.5 29.1 75.5-42.7 26.5-71.8 72.8-74.6 126.1-0.4 4.6 3.2 8.4 7.8 8.4z m149-262c28.5 0 51.7 23.3 51.7 52s-23.2 52-51.7 52-51.7-23.3-51.7-52 23.2-52 51.7-52z" p-id="17501"></path></svg> 我的数字ID:</strong> <span id="myUserId"></span></p>
            <input type="text" id="searchUserIdInput" placeholder="输入要查询的用户数字ID">
            <button onclick="searchUserInfo()">搜索</button>
            <div id="userInfoResult" style="margin-top: 15px;"></div>
        </div>
    </div>

    <!-- 模态框：好友申请列表 -->
    <div class="modal" id="friendRequestsModal">
        <div class="modal-content" style="max-width: 500px;">
            <span class="close" onclick="closeModal('friendRequestsModal')">&times;</span>
            <h3><svg class="icon" style="width: 1em;height: 1em;vertical-align: middle;fill: currentColor;overflow: hidden;" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="14013"><path d="M916.6 586.5l-42.7-48.6-0.5-0.5c-13-12.9-30.3-20-48.7-19.9-18.3 0.1-35.5 7.2-48.5 20L445.3 808.4l-3.7 3v143.4h140.7l332.9-270.1 0.4-0.3 0.4-0.4c26.9-27 27.1-70.6 0.6-97.5zM554 874.9h-32.6v-32.8l301.7-241.6 29.2 34L554 874.9zM232.1 727.9l-53 46.9-1.5 1.3V145.2H818v278.4l80 65.3V105.2c0-22.1-17.9-40-40-40H137.6c-22.1 0-40 17.9-40 40V953h0.7l133.8-118.3 134.5 118.9V846.8l-81.5-72.1-53-46.8zM295.4 342.6h265.5c21.9 0 39.7-17.9 39.7-39.7 0-21.9-17.9-39.7-39.7-39.7H295.4c-21.9 0-39.7 17.9-39.7 39.7-0.1 21.8 17.8 39.7 39.7 39.7zM255.6 507.9c0 21.9 17.9 39.7 39.7 39.7h334.5c21.9 0 39.7-17.9 39.7-39.7 0-21.9-17.9-39.7-39.7-39.7H295.4c-21.9-0.1-39.8 17.8-39.8 39.7z" p-id="14014"></path></svg> 好友申请</h3>
            <div id="friendRequestsList" style="max-height: 400px; overflow-y: auto;"></div>
        </div>
    </div>

    <!-- 添加好友模态框 -->
    <div class="modal" id="addFriendModal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('addFriendModal')">&times;</span>
            <h3><svg class="icon" style="width: 1em;height: 1em;vertical-align: middle;fill: currentColor;overflow: hidden;" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="10566"><path d="M512 64C264.6 64 64 264.6 64 512s200.6 448 448 448 448-200.6 448-448S759.4 64 512 64z m192 472c0 4.4-3.6 8-8 8H544v152c0 4.4-3.6 8-8 8h-48c-4.4 0-8-3.6-8-8V544H328c-4.4 0-8-3.6-8-8v-48c0-4.4 3.6-8 8-8h152V328c0-4.4 3.6-8 8-8h48c4.4 0 8 3.6 8 8v152h152c4.4 0 8 3.6 8 8v48z" p-id="10567"></path></svg> 添加好友</h3>
            <input type="text" id="searchUserId" placeholder="输入用户数字ID">
            <button onclick="searchUser()">搜索</button>
            <div id="searchResult"></div>
        </div>
    </div>

    <script>
        let currentUser = null;
        let currentFriendId = null;
        let currentFriendInfo = null;
        let messagePollingInterval = null; // 轮询定时器

let currentMessages = [];          // 缓存当前好友的消息列表
let currentFriendIdForMessages = null; // 当前正在聊天的好友ID

        // 切换登录/注册界面
        function showLogin() {
            document.getElementById('loginBox').classList.remove('hidden');
            document.getElementById('registerBox').classList.add('hidden');
        }
        function showRegister() {
            document.getElementById('loginBox').classList.add('hidden');
            document.getElementById('registerBox').classList.remove('hidden');
        }

        // 注册
        async function doRegister() {
            const username = document.getElementById('regUsername').value.trim();
            const password = document.getElementById('regPassword').value.trim();
            if (!username || !password) {
                alert('用户名和密码不能为空');
                return;
            }
            const formData = new FormData();
            formData.append('username', username);
            formData.append('password', password);
            const avatarFile = document.getElementById('regAvatar').files[0];
            if (avatarFile) {
                formData.append('avatar', avatarFile);
            }
            const res = await fetch('?action=register', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                alert('注册成功，请登录');
                showLogin();
            } else {
                alert('注册失败: ' + data.error);
            }
        }

        // 登录（支持用户名或数字ID）
        async function doLogin() {
            const usernameOrId = document.getElementById('loginUsername').value.trim();
            const password = document.getElementById('loginPassword').value.trim();
            if (!usernameOrId || !password) {
                alert('请输入用户名/ID和密码');
                return;
            }
            const res = await fetch('?action=login', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ username: usernameOrId, password })
            });
            const data = await res.json();
            if (data.success) {
                currentUser = data.user;
                document.getElementById('loginRegisterContainer').classList.add('hidden');
                document.getElementById('mainApp').classList.remove('hidden');
                updateUserInfo();
                loadFriends();
                loadFriendRequestCount();
                startMessagePolling(); // 启动消息轮询
            } else {
                alert('登录失败: ' + data.error);
            }
        }

        // 退出登录
        async function logout() {
            stopMessagePolling();
            await fetch('?action=logout');
            currentUser = null;
            document.getElementById('loginRegisterContainer').classList.remove('hidden');
            document.getElementById('mainApp').classList.add('hidden');
            document.getElementById('dropdownMenu').style.display = 'none';
        }

        // 更新用户信息显示
        function updateUserInfo() {
            document.getElementById('username').textContent = currentUser.nickname || currentUser.username;
            if (currentUser.avatar) {
                document.getElementById('avatar').src = currentUser.avatar + '?t=' + Date.now();
            } else {
                document.getElementById('avatar').src = 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2240%22%20height%3D%2240%22%20viewBox%3D%220%200%2040%2040%22%3E%3Ccircle%20cx%3D%2220%22%20cy%3D%2220%22%20r%3D%2220%22%20fill%3D%22%23ccc%22%2F%3E%3C%2Fsvg%3E';
            }
        }

        // 下拉菜单
        function toggleMenu(e) {
            e.stopPropagation();
            const menu = document.getElementById('dropdownMenu');
            menu.style.display = menu.style.display === 'flex' ? 'none' : 'flex';
        }
// 全局点击隐藏菜单（替换原 window.onclick）
window.onclick = function(e) {
    // 隐藏右侧用户菜单（点击非用户区域时）
    if (!e.target.closest('.user-info')) {
        document.getElementById('dropdownMenu').style.display = 'none';
    }
    // 隐藏左侧菜单（点击不是菜单按钮也不是菜单本身时）
    if (!e.target.closest('#menuToggle') && !e.target.closest('#leftMenu')) {
        document.getElementById('leftMenu').style.display = 'none';
        document.getElementById('menuToggle').classList.remove('rotate');
    }
}

// 左侧菜单按钮点击事件
document.getElementById('menuToggle').addEventListener('click', function(e) {
    e.stopPropagation(); // 阻止事件冒泡，避免立即触发 window.onclick
    const leftMenu = document.getElementById('leftMenu');
    const userMenu = document.getElementById('dropdownMenu');
    
    // 如果左侧菜单正在显示，则隐藏并移除旋转类
    if (leftMenu.style.display === 'flex') {
        leftMenu.style.display = 'none';
        this.classList.remove('rotate');
    } else {
        // 隐藏右侧菜单（避免同时显示两个菜单）
        userMenu.style.display = 'none';
        // 显示左侧菜单并添加旋转动画
        leftMenu.style.display = 'flex';
        this.classList.add('rotate');
    }
});

// 跳转到 /upf/ 目录
function goToUpload() {
    window.location.href = '/upF/';
}

function goToAnnouncement() {
    window.location.href = '/An/';
}

function goToNTwiki() {
    window.location.href = '/NTwiki/';
}

function goToVIP() {
    window.location.href = '/VIP/';
}



        // 模态框操作
        function closeModal(id) {
            document.getElementById(id).style.display = 'none';
        }

        function showChangeAvatar() {
            document.getElementById('dropdownMenu').style.display = 'none';
            document.getElementById('changeAvatarModal').style.display = 'flex';
        }

        function showChangeNickname() {
            document.getElementById('dropdownMenu').style.display = 'none';
            document.getElementById('changeNicknameModal').style.display = 'flex';
        }

        function showChangePassword() {
            document.getElementById('dropdownMenu').style.display = 'none';
            document.getElementById('changePasswordModal').style.display = 'flex';
        }

        function showEditProfile() {
            document.getElementById('dropdownMenu').style.display = 'none';
            document.getElementById('profileVerifyMode').value = currentUser.verify_mode || 'need_verify';
            document.getElementById('editProfileModal').style.display = 'flex';
        }

        function showEditBio() {
            document.getElementById('dropdownMenu').style.display = 'none';
            document.getElementById('bioContent').value = currentUser.bio || '';
            document.getElementById('editBioModal').style.display = 'flex';
        }

        // 查看用户信息：显示当前用户数字ID
        function showSearchUserInfo() {
            document.getElementById('dropdownMenu').style.display = 'none';
            document.getElementById('myUserId').textContent = currentUser.id;
            document.getElementById('searchUserIdInput').value = '';
            document.getElementById('userInfoResult').innerHTML = '';
            document.getElementById('searchUserInfoModal').style.display = 'flex';
        }

        async function showFriendRequests() {
            document.getElementById('dropdownMenu').style.display = 'none';
            const res = await fetch('?action=getFriendRequests');
            const data = await res.json();
            if (data.success) {
                renderFriendRequests(data.requests);
                document.getElementById('friendRequestsModal').style.display = 'flex';
            } else {
                alert('获取申请列表失败');
            }
        }

        function renderFriendRequests(requests) {
            const container = document.getElementById('friendRequestsList');
            container.innerHTML = '';
            if (requests.length === 0) {
                container.innerHTML = '<p style="text-align:center; padding:20px;">暂无好友申请</p>';
                return;
            }
            requests.forEach(req => {
                const div = document.createElement('div');
                div.className = 'request-item';
                const avatarUrl = req.avatar ? req.avatar : 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2240%22%20height%3D%2240%22%20viewBox%3D%220%200%2040%2040%22%3E%3Ccircle%20cx%3D%2220%22%20cy%3D%2220%22%20r%3D%2220%22%20fill%3D%22%23ccc%22%2F%3E%3C%2Fsvg%3E';
                div.innerHTML = `
                    <img src="${escapeHtml(avatarUrl)}" alt="avatar">
                    <div class="info">
                        <div><strong>${escapeHtml(req.nickname)}</strong> (ID: ${escapeHtml(req.id)})</div>
                        <div>用户名: ${escapeHtml(req.username)}</div>
                    </div>
                    <button class="primary" onclick="acceptFriendRequest('${escapeHtml(req.id)}')">接受</button>
                    <button class="secondary" onclick="rejectFriendRequest('${escapeHtml(req.id)}')">拒绝</button>
                `;
                container.appendChild(div);
            });
        }

        async function acceptFriendRequest(requesterId) {
            const res = await fetch('?action=acceptFriendRequest', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({requesterId})
            });
            const data = await res.json();
            if (data.success) {
                alert('已接受');
                closeModal('friendRequestsModal');
                loadFriends();
                loadFriendRequestCount();
            } else {
                alert('接受失败: ' + data.error);
            }
        }

        async function rejectFriendRequest(requesterId) {
            const res = await fetch('?action=rejectFriendRequest', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({requesterId})
            });
            const data = await res.json();
            if (data.success) {
                alert('已拒绝');
                closeModal('friendRequestsModal');
                loadFriendRequestCount();
            } else {
                alert('拒绝失败: ' + data.error);
            }
        }

        async function loadFriendRequestCount() {
            const res = await fetch('?action=getFriendRequests');
            const data = await res.json();
            if (data.success) {
                const count = data.requests.length;
                document.getElementById('requestCount').textContent = count;
            }
        }

        // 上传头像
        async function uploadAvatar() {
            const file = document.getElementById('newAvatar').files[0];
            if (!file) return;
            const formData = new FormData();
            formData.append('avatar', file);
            const res = await fetch('?action=uploadAvatar', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                currentUser.avatar = data.path;
                updateUserInfo();
                closeModal('changeAvatarModal');
            } else {
                alert('上传失败: ' + data.error);
            }
        }

        // 修改昵称
        async function updateNickname() {
            const nickname = document.getElementById('newNickname').value.trim();
            if (!nickname) return;
            const formData = new FormData();
            formData.append('nickname', nickname);
            const res = await fetch('?action=updateUser', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                currentUser = data.user;
                updateUserInfo();
                closeModal('changeNicknameModal');
            } else {
                alert('更新失败: ' + data.error);
            }
        }

        // 修改密码
        async function updatePassword() {
            const old = document.getElementById('oldPassword').value.trim();
            const newPwd = document.getElementById('newPassword').value.trim();
            const confirm = document.getElementById('confirmPassword').value.trim();
            if (!old || !newPwd || !confirm) {
                alert('请填写所有密码字段');
                return;
            }
            if (newPwd !== confirm) {
                alert('新密码不一致');
                return;
            }
            const formData = new FormData();
            formData.append('old_password', old);
            formData.append('password', newPwd);
            const res = await fetch('?action=updateUser', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                currentUser = data.user;
                alert('密码修改成功');
                closeModal('changePasswordModal');
            } else {
                alert('修改失败: ' + data.error);
            }
        }

        // 修改资料（好友验证方式）
        async function updateProfile() {
            const verify_mode = document.getElementById('profileVerifyMode').value;
            const formData = new FormData();
            formData.append('verify_mode', verify_mode);
            const res = await fetch('?action=updateUser', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                currentUser = data.user;
                alert('资料更新成功');
                closeModal('editProfileModal');
            } else {
                alert('更新失败: ' + data.error);
            }
        }

        // 修改简介
        async function updateBio() {
            const bio = document.getElementById('bioContent').value.trim();
            const formData = new FormData();
            formData.append('bio', bio);
            const res = await fetch('?action=updateUser', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                currentUser = data.user;
                alert('简介更新成功');
                closeModal('editBioModal');
            } else {
                alert('更新失败: ' + data.error);
            }
        }

        // 搜索用户信息
        async function searchUserInfo() {
            const userId = document.getElementById('searchUserIdInput').value.trim();
            if (!userId) {
                alert('请输入用户ID');
                return;
            }
            const res = await fetch('?action=searchUserInfo&userId=' + encodeURIComponent(userId));
            const data = await res.json();
            const resultDiv = document.getElementById('userInfoResult');
            if (data.success) {
                const user = data.user;
                const registered = new Date(user.registered * 1000).toLocaleString();
                resultDiv.innerHTML = `
                    <div style="border:1px solid var(--border-color); padding:10px; border-radius:5px;">
                        <p><strong>数字ID:</strong> ${escapeHtml(user.id)}</p>
                        <p><strong>用户名称:</strong> ${escapeHtml(user.nickname || user.username)}</p>
                        <p><strong>注册时间:</strong> ${escapeHtml(registered)}</p>
                        <p><strong>简介:</strong> ${escapeHtml(user.bio || '无')}</p>
                    </div>
                `;
            } else {
                resultDiv.innerHTML = '<span class="error">' + escapeHtml(data.error) + '</span>';
            }
        }

// 删除当前好友
async function deleteCurrentFriend() {
    if (!currentFriendId) {
        alert('请先选择一个好友');
        return;
    }
    if (!confirm('确定要删除该好友吗？聊天记录也将被清除。')) {
        return;
    }
    const res = await fetch('?action=deleteFriend', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ friendId: currentFriendId })
    });
    const data = await res.json();
    if (data.success) {
        alert('好友已删除');
        // 清除当前选中状态
        currentFriendId = null;
        currentFriendInfo = null;
        currentFriendIdForMessages = null;
        currentMessages = [];
        document.getElementById('chatHeader').textContent = '请选择好友开始聊天';
        document.getElementById('chatMessages').innerHTML = '';
        // 刷新好友列表
        loadFriends();
        // 可选：关闭下拉菜单
        document.getElementById('dropdownMenu').style.display = 'none';
    } else {
        alert('删除失败：' + (data.error || '未知错误'));
    }
}

        // 好友列表相关
        async function loadFriends() {
            const res = await fetch('?action=getFriends');
            const data = await res.json();
            if (data.success) {
                renderFriendList(data.friends);
            }
        }

        function renderFriendList(friends) {
            const container = document.getElementById('friendList');
            container.innerHTML = '';
            const addBtn = document.createElement('div');
            addBtn.className = 'friend-item';
            addBtn.innerHTML = '<span style="flex:1;"><svg class="icon" style="width: 1em;height: 1em;vertical-align: middle;fill: currentColor;overflow: hidden;" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="10566"><path d="M512 64C264.6 64 64 264.6 64 512s200.6 448 448 448 448-200.6 448-448S759.4 64 512 64z m192 472c0 4.4-3.6 8-8 8H544v152c0 4.4-3.6 8-8 8h-48c-4.4 0-8-3.6-8-8V544H328c-4.4 0-8-3.6-8-8v-48c0-4.4 3.6-8 8-8h152V328c0-4.4 3.6-8 8-8h48c4.4 0 8 3.6 8 8v152h152c4.4 0 8 3.6 8 8v48z" p-id="10567"></path></svg> 添加好友</span>';
            addBtn.onclick = showAddFriend;
            container.appendChild(addBtn);

            friends.forEach(f => {
                const div = document.createElement('div');
                div.className = 'friend-item';
                const avatarUrl = f.avatar ? f.avatar : 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2230%22%20height%3D%2230%22%20viewBox%3D%220%200%2030%2030%22%3E%3Ccircle%20cx%3D%2215%22%20cy%3D%2215%22%20r%3D%2215%22%20fill%3D%22%23ccc%22%2F%3E%3C%2Fsvg%3E';
                div.innerHTML = `<img src="${escapeHtml(avatarUrl)}" class="friend-avatar" alt="avatar"><span>${escapeHtml(f.nickname || f.username)}</span>`;
                div.dataset.id = f.id;
                div.onclick = () => selectFriend(f, div);
                container.appendChild(div);
            });
        }

function selectFriend(friend, element) {
    document.querySelectorAll('.friend-item').forEach(el => el.classList.remove('active'));
    element.classList.add('active');
    currentFriendId = friend.id;
    currentFriendInfo = friend; // 保存好友信息用于显示头像
    currentFriendIdForMessages = friend.id; // 记录当前好友
    currentMessages = []; // 清空消息缓存
    document.getElementById('chatHeader').textContent = `与 ${friend.nickname || friend.username} 聊天中`;
    loadMessages(friend.id);
}


async function loadMessages(friendId) {
    const res = await fetch('?action=getMessages&friendId=' + friendId);
    const data = await res.json();
    const newMessages = data.messages;
    const container = document.getElementById('chatMessages');

    // 如果当前选中的好友已经改变，则忽略这次返回的数据
    if (friendId !== currentFriendIdForMessages) {
        return;
    }

    if (currentMessages.length === 0) {
        // 首次加载（或切换好友后清空），完全重新渲染
        renderMessages(newMessages);
    } else {
        // 同一好友，增量更新
        if (newMessages.length > currentMessages.length) {
            const added = newMessages.slice(currentMessages.length);
            for (const msg of added) {
                appendMessage(msg);
            }
            // 智能滚动：如果用户在底部附近（<50px），则自动滚到底部
            const atBottom = container.scrollHeight - container.scrollTop - container.clientHeight < 50;
            if (atBottom) {
                container.scrollTop = container.scrollHeight;
            }
            currentMessages = newMessages;
        }
    }
}


function appendMessage(msg) {
    const container = document.getElementById('chatMessages');
    const div = document.createElement('div');
    div.className = 'message';
    if (msg.from === currentUser.id) {
        div.classList.add('own');
    }

    // 获取发送者信息
    const senderId = msg.from;
    const isMe = senderId === currentUser.id;
    const senderInfo = isMe ? currentUser : currentFriendInfo;
    let avatarUrl = senderInfo?.avatar || 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2240%22%20height%3D%2240%22%20viewBox%3D%220%200%2040%2040%22%3E%3Ccircle%20cx%3D%2220%22%20cy%3D%2220%22%20r%3D%2220%22%20fill%3D%22%23ccc%22%2F%3E%3C%2Fsvg%3E';
    let senderName = isMe ? '我' : (senderInfo?.nickname || senderInfo?.username || msg.fromName || msg.from);

    // 头像
    const avatarImg = document.createElement('img');
    avatarImg.className = 'avatar';
    avatarImg.src = avatarUrl;
    avatarImg.onerror = () => { avatarImg.src = 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2240%22%20height%3D%2240%22%20viewBox%3D%220%200%2040%2040%22%3E%3Ccircle%20cx%3D%2220%22%20cy%3D%2220%22%20r%3D%2220%22%20fill%3D%22%23ccc%22%2F%3E%3C%2Fsvg%3E'; };
    div.appendChild(avatarImg);

    // 右侧列（名称 + 气泡）
    const rightCol = document.createElement('div');
    rightCol.className = 'right-col';

    const nameDiv = document.createElement('div');
    nameDiv.className = 'sender-name';
    nameDiv.textContent = senderName;
    rightCol.appendChild(nameDiv);

    const bubble = document.createElement('div');
    bubble.className = 'bubble';
    const contentDiv = document.createElement('div');
    contentDiv.className = 'content';
    if (msg.type === 'text') {
        contentDiv.textContent = msg.content;
    } else if (msg.type === 'image') {
        const img = document.createElement('img');
        img.src = '?action=getImage&file=' + encodeURIComponent(msg.content);
        img.alt = 'image';
        img.onclick = () => window.open(img.src);
        contentDiv.appendChild(img);
    }
    bubble.appendChild(contentDiv);
    rightCol.appendChild(bubble);

    div.appendChild(rightCol);
    container.appendChild(div);
}

function renderMessages(messages) {
    const container = document.getElementById('chatMessages');
    container.innerHTML = '';
    messages.forEach(msg => appendMessage(msg));
    currentMessages = messages;

    // 滚动到底部的辅助函数
    const scrollToBottom = () => {
        container.scrollTop = container.scrollHeight;
    };

    // 立即尝试滚动（此时图片可能未加载，高度可能不完整）
    scrollToBottom();

    // 获取所有图片元素
    const images = container.querySelectorAll('.message .bubble img');
    if (images.length === 0) return;

    let loadedCount = 0;
    const totalImages = images.length;

    const checkAllLoaded = () => {
        loadedCount++;
        if (loadedCount === totalImages) {
            // 所有图片加载完成，再次滚动到底部
            scrollToBottom();
        }
    };

    images.forEach(img => {
        if (img.complete) {
            // 图片已缓存，立即计数
            loadedCount++;
        } else {
            // 监听加载完成
            img.addEventListener('load', checkAllLoaded);
            // 可选：监听加载失败（避免计数卡住）
            img.addEventListener('error', checkAllLoaded);
        }
    });

    // 如果所有图片一开始就已完成，直接再次滚动
    if (loadedCount === totalImages) {
        scrollToBottom();
    }
}

        function escapeHtml(unsafe) {
            if (!unsafe) return '';
            return unsafe
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

async function sendMessage() {
    const input = document.getElementById('messageInput');
    const content = input.value.trim();
    if (!content || !currentFriendId) return;
    const res = await fetch('?action=sendMessage', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({friendId: currentFriendId, content, type: 'text'})
    });
    const data = await res.json();
    if (data.success) {
        input.value = '';
        loadMessages(currentFriendId);
        const container = document.getElementById('chatMessages');
        container.scrollTop = container.scrollHeight;
    }
}

        // 支持回车发送（Shift+Enter换行）
        document.getElementById('messageInput').addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        // 图片上传
        document.getElementById('imageUpload').onchange = async function(e) {
            const file = e.target.files[0];
            if (!file) return;
            const formData = new FormData();
            formData.append('image', file);
            const res = await fetch('?action=uploadImage', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                const sendRes = await fetch('?action=sendMessage', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({friendId: currentFriendId, content: data.fileId, type: 'image'})
                });
                if (sendRes.ok) {
                    loadMessages(currentFriendId);
                }
            } else {
                alert('上传失败: ' + data.error);
            }
            e.target.value = '';
        };

        // 添加好友
        function showAddFriend() {
            document.getElementById('addFriendModal').style.display = 'flex';
            document.getElementById('searchResult').innerHTML = '';
        }

        async function searchUser() {
            const userId = document.getElementById('searchUserId').value.trim();
            if (!userId) return;
            const res = await fetch('?action=searchUser&userId=' + encodeURIComponent(userId));
            const data = await res.json();
            const resultDiv = document.getElementById('searchResult');
            if (data.success) {
                resultDiv.innerHTML = `
                    找到用户: ${escapeHtml(data.user.username)} (ID: ${escapeHtml(data.user.id)})<br>
                    <button onclick="addFriend('${escapeHtml(data.user.id)}')">添加好友</button>
                `;
            } else {
                resultDiv.innerHTML = '<span class="error">' + escapeHtml(data.error) + '</span>';
            }
        }

        async function addFriend(userId) {
            const res = await fetch('?action=sendFriendRequest', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({targetId: userId})
            });
            const data = await res.json();
            if (data.success) {
                alert('好友请求已发送');
                closeModal('addFriendModal');
            } else {
                alert('失败: ' + data.error);
            }
        }

        // 实时消息轮询
        function startMessagePolling() {
            if (messagePollingInterval) clearInterval(messagePollingInterval);
            messagePollingInterval = setInterval(() => {
                if (currentFriendId) {
                    loadMessages(currentFriendId);
                }
            }, 2000); // 每2秒刷新
        }

        function stopMessagePolling() {
            if (messagePollingInterval) {
                clearInterval(messagePollingInterval);
                messagePollingInterval = null;
            }
        }

        // 初始化检查登录状态
        async function init() {
            const res = await fetch('?action=getUserInfo');
            const data = await res.json();
            if (data.loggedIn) {
                currentUser = data.user;
                document.getElementById('loginRegisterContainer').classList.add('hidden');
                document.getElementById('mainApp').classList.remove('hidden');
                updateUserInfo();
                loadFriends();
                loadFriendRequestCount();
                startMessagePolling();
            } else {
                document.getElementById('loginRegisterContainer').classList.remove('hidden');
                document.getElementById('mainApp').classList.add('hidden');
            }
        }

// 暗黑模式切换
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

        init();
    </script>
</body>
</html>


<?php
// ==================== 后端函数 ====================

function getUsers() {
    $content = file_get_contents(USERS_FILE);
    return json_decode($content, true) ?: [];
}

function saveUsers($users) {
    file_put_contents(USERS_FILE, json_encode($users), LOCK_EX); // 压缩存储
}

function getUserById($id) {
    $users = getUsers();
    foreach ($users as $user) {
        if ($user['id'] == $id) return $user;
    }
    return null;
}

function getUserByUsername($username) {
    $users = getUsers();
    foreach ($users as $user) {
        if ($user['username'] == $username) return $user;
    }
    return null;
}

function getFriends($userId) {
    $file = DATA_DIR . '/friends_' . $userId . '.json';
    if (!file_exists($file)) return [];
    return json_decode(file_get_contents($file), true) ?: [];
}

function saveFriends($userId, $friends) {
    $file = DATA_DIR . '/friends_' . $userId . '.json';
    file_put_contents($file, json_encode($friends), LOCK_EX);
}

function getMessages($userId, $friendId) {
    $dir = DATA_DIR . '/' . $userId;
    if (!file_exists($dir)) mkdir($dir, 0777, true);
    $file = $dir . '/' . $friendId . '.json';
    if (!file_exists($file)) return [];
    return json_decode(file_get_contents($file), true) ?: [];
}

function saveMessageToUser($userId, $friendId, $message) {
    $messages = getMessages($userId, $friendId);
    $messages[] = $message;
    $dir = DATA_DIR . '/' . $userId;
    if (!file_exists($dir)) mkdir($dir, 0777, true);
    $file = $dir . '/' . $friendId . '.json';
    file_put_contents($file, json_encode($messages), LOCK_EX); // 压缩存储
}

// 生成唯一10位数字ID
function generateUserId() {
    do {
        $id = strval(random_int(1000000000, 9999999999));
    } while (getUserById($id) !== null);
    return $id;
}

// 处理注册
function handleRegister() {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    if (!$username || !$password) {
        return ['success' => false, 'error' => '用户名和密码不能为空'];
    }
    if (getUserByUsername($username) !== null) {
        return ['success' => false, 'error' => '用户名已存在'];
    }
    $userId = generateUserId();
    $newUser = [
        'id' => $userId,
        'username' => $username,
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'nickname' => $username,
        'avatar' => null,
        'bio' => '',
        'verify_mode' => 'need_verify',
        'registered' => time()
    ];

    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $avatarResult = handleAvatarUpload($_FILES['avatar']);
        if ($avatarResult['success']) {
            $newUser['avatar'] = $avatarResult['path'];
        }
    }

    $users = getUsers();
    $users[] = $newUser;
    saveUsers($users);
    return ['success' => true, 'user' => $newUser];
}

// 处理登录
function handleLogin() {
    $usernameOrId = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    $user = getUserByUsername($usernameOrId);
    if (!$user) {
        $user = getUserById($usernameOrId);
    }
    
    if (!$user || !password_verify($password, $user['password'])) {
        return ['success' => false, 'error' => '用户名/ID或密码错误'];
    }
    $_SESSION['user_id'] = $user['id'];
    return ['success' => true, 'user' => $user];
}

function handleLogout() {
    session_destroy();
    return ['success' => true];
}

function handleGetUserInfo() {
    if (!isset($_SESSION['user_id'])) {
        return ['loggedIn' => false];
    }
    $user = getUserById($_SESSION['user_id']);
    if (!$user) {
        session_destroy();
        return ['loggedIn' => false];
    }
    return ['loggedIn' => true, 'user' => $user];
}

function handleUpdateUser() {
    if (!isset($_SESSION['user_id'])) {
        return ['success' => false, 'error' => '未登录'];
    }
    $userId = $_SESSION['user_id'];
    $users = getUsers();
    $index = array_search($userId, array_column($users, 'id'));
    if ($index === false) return ['success' => false, 'error' => '用户不存在'];

    $user = &$users[$index];

    if (isset($_POST['old_password']) && isset($_POST['password']) && !empty($_POST['password'])) {
        if (!password_verify($_POST['old_password'], $user['password'])) {
            return ['success' => false, 'error' => '旧密码错误'];
        }
        $user['password'] = password_hash($_POST['password'], PASSWORD_DEFAULT);
    }

    if (isset($_POST['nickname']) && !empty($_POST['nickname'])) {
        $user['nickname'] = $_POST['nickname'];
    }
    if (isset($_POST['verify_mode'])) {
        $user['verify_mode'] = $_POST['verify_mode'];
    }
    if (isset($_POST['bio'])) {
        $user['bio'] = $_POST['bio'];
    }

    saveUsers($users);
    return ['success' => true, 'user' => $user];
}

function handleUploadAvatar() {
    if (!isset($_SESSION['user_id'])) {
        return ['success' => false, 'error' => '未登录'];
    }
    if (!isset($_FILES['avatar'])) {
        return ['success' => false, 'error' => '没有文件'];
    }
    $result = handleAvatarUpload($_FILES['avatar']);
    if (!$result['success']) return $result;

    $userId = $_SESSION['user_id'];
    $users = getUsers();
    $index = array_search($userId, array_column($users, 'id'));
    if ($index !== false) {
        $users[$index]['avatar'] = $result['path'];
        saveUsers($users);
    }
    return $result;
}

function handleAvatarUpload($file) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => '上传错误'];
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        return ['success' => false, 'error' => '图片不能超过2MB'];
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mime, $allowed)) {
        return ['success' => false, 'error' => '只允许上传JPG、PNG、GIF、WEBP格式的图片'];
    }
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid() . '.' . $ext;
    $dest = AVATAR_DIR . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['success' => false, 'error' => '保存失败'];
    }
    return ['success' => true, 'path' => 'data/avatars/' . $filename];
}

function handleSearchUser() {
    if (!isset($_SESSION['user_id'])) return ['success' => false, 'error' => '未登录'];
    $userId = $_GET['userId'] ?? '';
    if (!$userId) return ['success' => false, 'error' => '请输入用户ID'];
    $user = getUserById($userId);
    if (!$user) return ['success' => false, 'error' => '用户不存在'];
    if ($user['id'] == $_SESSION['user_id']) {
        return ['success' => false, 'error' => '不能添加自己'];
    }
    return ['success' => true, 'user' => ['id' => $user['id'], 'username' => $user['username']]];
}

function handleSearchUserInfo() {
    if (!isset($_SESSION['user_id'])) return ['success' => false, 'error' => '未登录'];
    $userId = $_GET['userId'] ?? '';
    if (!$userId) return ['success' => false, 'error' => '请输入用户ID'];
    $user = getUserById($userId);
    if (!$user) return ['success' => false, 'error' => '用户不存在'];
    return [
        'success' => true,
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'nickname' => $user['nickname'] ?? $user['username'],
            'registered' => $user['registered'],
            'bio' => $user['bio'] ?? ''
        ]
    ];
}

function handleSendFriendRequest() {
    if (!isset($_SESSION['user_id'])) return ['success' => false, 'error' => '未登录'];
    $currentId = $_SESSION['user_id'];
    $targetId = $_POST['targetId'] ?? '';
    if (!$targetId) return ['success' => false, 'error' => '目标用户ID不能为空'];

    $targetUser = getUserById($targetId);
    if (!$targetUser) return ['success' => false, 'error' => '目标用户不存在'];

    $myFriends = getFriends($currentId);
    foreach ($myFriends as $f) {
        if ($f['id'] == $targetId && $f['status'] == 'accepted') {
            return ['success' => false, 'error' => '已经是好友'];
        }
    }

    $verifyMode = $targetUser['verify_mode'] ?? 'need_verify';

    if ($verifyMode == 'deny_all') {
        return ['success' => false, 'error' => '对方禁止添加好友'];
    }

    if ($verifyMode == 'allow_all') {
        $myFriends[] = ['id' => $targetId, 'status' => 'accepted', 'since' => time()];
        saveFriends($currentId, $myFriends);

        $targetFriends = getFriends($targetId);
        $targetFriends[] = ['id' => $currentId, 'status' => 'accepted', 'since' => time()];
        saveFriends($targetId, $targetFriends);
        return ['success' => true, 'message' => '添加好友成功'];
    }

    // need_verify
    $targetFriends = getFriends($targetId);
    foreach ($targetFriends as $f) {
        if ($f['id'] == $currentId && $f['status'] == 'pending') {
            return ['success' => false, 'error' => '请求已发送，请等待'];
        }
    }
    $targetFriends[] = ['id' => $currentId, 'status' => 'pending', 'since' => time()];
    saveFriends($targetId, $targetFriends);
    return ['success' => true, 'message' => '好友请求已发送'];
}

function handleAcceptFriendRequest() {
    if (!isset($_SESSION['user_id'])) return ['success' => false, 'error' => '未登录'];
    $currentId = $_SESSION['user_id'];
    $requesterId = $_POST['requesterId'] ?? '';
    $myFriends = getFriends($currentId);
    $found = false;
    foreach ($myFriends as &$f) {
        if ($f['id'] == $requesterId && $f['status'] == 'pending') {
            $f['status'] = 'accepted';
            $f['since'] = time();
            $found = true;
            break;
        }
    }
    if (!$found) return ['success' => false, 'error' => '没有找到该请求'];
    saveFriends($currentId, $myFriends);
    $requesterFriends = getFriends($requesterId);
    $requesterFriends[] = ['id' => $currentId, 'status' => 'accepted', 'since' => time()];
    saveFriends($requesterId, $requesterFriends);
    return ['success' => true];
}

function handleRejectFriendRequest() {
    if (!isset($_SESSION['user_id'])) return ['success' => false, 'error' => '未登录'];
    $currentId = $_SESSION['user_id'];
    $requesterId = $_POST['requesterId'] ?? '';
    $myFriends = getFriends($currentId);
    $myFriends = array_filter($myFriends, function($f) use ($requesterId) {
        return !($f['id'] == $requesterId && $f['status'] == 'pending');
    });
    saveFriends($currentId, array_values($myFriends));
    return ['success' => true];
}

function handleGetFriendRequests() {
    if (!isset($_SESSION['user_id'])) return ['success' => false, 'error' => '未登录'];
    $currentId = $_SESSION['user_id'];
    $friends = getFriends($currentId);
    $pending = array_filter($friends, function($f) { return $f['status'] == 'pending'; });
    $result = [];
    foreach ($pending as $p) {
        $user = getUserById($p['id']);
        if ($user) {
            $result[] = [
                'id' => $user['id'],
                'username' => $user['username'],
                'nickname' => $user['nickname'] ?? $user['username'],
                'avatar' => $user['avatar'] ?? null
            ];
        }
    }
    return ['success' => true, 'requests' => $result];
}

function handleGetFriends() {
    if (!isset($_SESSION['user_id'])) return ['success' => false, 'error' => '未登录'];
    $userId = $_SESSION['user_id'];
    $friends = getFriends($userId);
    $accepted = array_filter($friends, function($f) { return $f['status'] == 'accepted'; });
    $result = [];
    foreach ($accepted as $f) {
        $user = getUserById($f['id']);
        if ($user) {
            $result[] = [
                'id' => $user['id'],
                'username' => $user['username'],
                'nickname' => $user['nickname'] ?? $user['username'],
                'avatar' => $user['avatar'] ?? null
            ];
        }
    }
    return ['success' => true, 'friends' => $result];
}

function handleGetMessages() {
    if (!isset($_SESSION['user_id'])) return ['success' => false, 'error' => '未登录'];
    $userId = $_SESSION['user_id'];
    $friendId = $_GET['friendId'] ?? '';
    if (!$friendId) return ['success' => false, 'error' => '缺少好友ID'];
    $messages = getMessages($userId, $friendId);
    $usersCache = [];
    foreach ($messages as &$msg) {
        if (!isset($usersCache[$msg['from']])) {
            $u = getUserById($msg['from']);
            $usersCache[$msg['from']] = $u ? ($u['nickname'] ?? $u['username']) : $msg['from'];
        }
        $msg['fromName'] = $usersCache[$msg['from']];
    }
    return ['success' => true, 'messages' => $messages];
}

function handleSendMessage() {
    if (!isset($_SESSION['user_id'])) return ['success' => false, 'error' => '未登录'];
    $fromId = $_SESSION['user_id'];
    $toId = $_POST['friendId'] ?? '';
    $content = $_POST['content'] ?? '';
    $type = $_POST['type'] ?? 'text';
    if (!$toId || !$content) return ['success' => false, 'error' => '参数不足'];
    $myFriends = getFriends($fromId);
    $isFriend = false;
    foreach ($myFriends as $f) {
        if ($f['id'] == $toId && $f['status'] == 'accepted') {
            $isFriend = true;
            break;
        }
    }
    if (!$isFriend) return ['success' => false, 'error' => '不是好友关系'];
    $message = [
        'from' => $fromId,
        'content' => $content,
        'type' => $type,
        'timestamp' => time()
    ];
    saveMessageToUser($fromId, $toId, $message);
    saveMessageToUser($toId, $fromId, $message);
    return ['success' => true];
}

function handleUploadImage() {
    if (!isset($_SESSION['user_id'])) return ['success' => false, 'error' => '未登录'];
    if (!isset($_FILES['image'])) return ['success' => false, 'error' => '没有文件'];
    $file = $_FILES['image'];
    if ($file['error'] !== UPLOAD_ERR_OK) return ['success' => false, 'error' => '上传错误'];
    if ($file['size'] > 10 * 1024 * 1024) {
        return ['success' => false, 'error' => '图片不能超过10MB'];
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mime, $allowed)) {
        return ['success' => false, 'error' => '只允许上传图片'];
    }
    $md5 = md5_file($file['tmp_name']);
    $dest = UPLOAD_DIR . '/' . $md5;
    if (!file_exists($dest)) {
        move_uploaded_file($file['tmp_name'], $dest);
    }
    $map = json_decode(file_get_contents(FILE_NAME_JSON), true);
    $map[] = [
        'original' => $file['name'],
        'md5' => $md5,
        'mime' => $mime
    ];
    file_put_contents(FILE_NAME_JSON, json_encode($map), LOCK_EX);
    return ['success' => true, 'fileId' => $md5];
}

function handleGetImage() {
    $fileId = $_GET['file'] ?? '';
    if (!$fileId) { header('HTTP/1.0 404 Not Found'); exit; }
    if (strpos($fileId, '/') !== false || strpos($fileId, '\\') !== false) {
        header('HTTP/1.0 400 Bad Request'); exit;
    }
    $path = UPLOAD_DIR . '/' . $fileId;
    if (!file_exists($path)) { header('HTTP/1.0 404 Not Found'); exit; }
    $map = json_decode(file_get_contents(FILE_NAME_JSON), true);
    $mime = 'image/jpeg';
    foreach ($map as $item) {
        if ($item['md5'] == $fileId) { $mime = $item['mime']; break; }
    }
    header('Content-Type: ' . $mime);
    readfile($path);
    exit;
}
?>