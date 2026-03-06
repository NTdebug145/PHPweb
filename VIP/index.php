<?php
session_set_cookie_params(['path' => '/']);
session_start();

// 未登录则跳转
if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}

$userId = $_SESSION['user_id'];

// 定义根目录和数据路径
define('ROOT_DIR', dirname(__DIR__));
define('DATA_DIR', ROOT_DIR . '/data');
define('USERS_FILE', DATA_DIR . '/users.json');

// 获取用户信息
function getUserById($id) {
    $users = getUsers();  // 改为调用修改后的 getUsers()
    foreach ($users as $user) {
        if ($user['id'] == $id) return $user;
    }
    return null;
}

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
    // 可选：按 ID 排序，保持一致性
    usort($users, function($a, $b) {
        return $a['id'] <=> $b['id'];
    });
    return $users;
}

$user = getUserById($userId);
if (!$user) {
    session_destroy();
    header('Location: /index.php');
    exit;
}

// 检查 VIP 状态（字段 vip 必须为 true）
$isVip = isset($user['vip']) && $user['vip'] === true;

if (!$isVip) {
    // 非 VIP：显示提示并在 3 秒后跳转回首页
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>VIP专区 - 权限不足</title>
        <meta http-equiv="refresh" content="3;url=/index.php">
        <style>
            body { font-family: 'Microsoft YaHei', sans-serif; background: #f0f2f5; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
            .message { background: white; padding: 30px; border-radius: 12px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); text-align: center; max-width: 400px; }
            h2 { color: #f56c6c; margin-bottom: 10px; }
            p { color: #666; margin-bottom: 20px; }
            .timer { color: #999; font-size: 14px; }
        </style>
    </head>
    <body>
        <div class="message">
            <h2>⛔ 访问受限</h2>
            <p>您不是 VIP 用户，无法访问此页面。</p>
            <p class="timer">3 秒后将自动返回首页...</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// VIP 用户：显示带头像的页面，其余留白待扩展
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>VIP 专属页面</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Microsoft YaHei', sans-serif; }
        body { background: #f0f2f5; min-height: 100vh; }
        .header { height: 60px; background: white; display: flex; justify-content: flex-end; align-items: center; padding: 0 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); border-bottom: 1px solid #ddd; }
        .user-info { display: flex; align-items: center; padding: 5px; border-radius: 30px; cursor: default; }
        .avatar { width: 40px; height: 40px; border-radius: 50%; margin-right: 10px; object-fit: cover; }
        .username { font-weight: 500; color: #333; }
        .content { display: flex; justify-content: center; align-items: center; height: calc(100vh - 60px); color: #999; font-size: 18px; }
        .content p { background: white; padding: 40px; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
    <div class="header">
        <div class="user-info">
            <?php if (!empty($user['avatar'])): ?>
                <img class="avatar" src="/<?php echo htmlspecialchars($user['avatar']); ?>" alt="avatar">
            <?php else: ?>
                <img class="avatar" src="data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2240%22%20height%3D%2240%22%20viewBox%3D%220%200%2040%2040%22%3E%3Ccircle%20cx%3D%2220%22%20cy%3D%2220%22%20r%3D%2220%22%20fill%3D%22%23ccc%22%2F%3E%3C%2Fsvg%3E" alt="默认头像">
            <?php endif; ?>
            <span class="username"><?php echo htmlspecialchars($user['nickname'] ?? $user['username']); ?></span>
        </div>
    </div>
    <div class="content">
        <p>✨ VIP 功能开发中，敬请期待 ✨</p>
    </div>
</body>
</html>