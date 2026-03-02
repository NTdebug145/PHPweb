<?php
// publish.php - 发布公告（仅管理员可访问，需要 ?admin=yes 参数）publish.php?admin=yes
session_start(); // 可选，这里用简单的 GET 令牌控制

// 简单的访问控制：只有 URL 中带有 ?admin=yes 才允许访问
if (!isset($_GET['admin']) || $_GET['admin'] !== 'yes') {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><title>404 Not Found</title></head><body><h1>404 Not Found</h1><p>页面不存在。</p></body></html>';
    exit;
}

header('Content-Type: text/html; charset=utf-8');

// 读取/初始化 JSON 数据
function readData() {
    $file = 'AnData.json';
    if (!file_exists($file)) {
        // 初始标签和一条可见的示例公告
        $default = [
            'tags' => ['系统维护', '产品更新', '假期通知', '政策更新', '有奖调研'],
            'announcements' => [
                [
                    'id' => 1,
                    'title' => '欢迎使用公告系统（示例）',
                    'summary' => '这是一条可见的示例公告。您可以通过发布页面添加新公告，并选择是否公开。',
                    'date' => date('Y-m-d'),
                    'tags' => ['系统维护'],
                    'visible' => true   // 示例公告默认可见
                ]
            ]
        ];
        file_put_contents($file, json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $default;
    }
    $content = file_get_contents($file);
    $data = json_decode($content, true);
    // 确保数据完整性
    if (!$data || !isset($data['tags']) || !isset($data['announcements'])) {
        $data = ['tags' => [], 'announcements' => []];
    }
    // 为历史公告补充 visible 字段（默认为 false，即用户不可见）
    foreach ($data['announcements'] as &$ann) {
        if (!isset($ann['visible'])) {
            $ann['visible'] = false;
        }
    }
    return $data;
}

// 写入 JSON 数据
function writeData($data) {
    file_put_contents('AnData.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// 处理表单提交
$error = '';
$success = false;
$data = readData();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $summary = trim($_POST['summary'] ?? '');
    // 获取选中的标签（数组）
    $selectedTags = $_POST['tags'] ?? [];
    // 过滤：只保留存在于标签库中的标签
    $validTags = array_intersect($selectedTags, $data['tags']);
    $visible = isset($_POST['visible']) ? true : false;

    if ($title === '') {
        $error = '公告标题不能为空。';
    } else {
        // 生成新公告ID
        $maxId = 0;
        foreach ($data['announcements'] as $ann) {
            if ($ann['id'] > $maxId) $maxId = $ann['id'];
        }
        $newId = $maxId + 1;

        // 创建公告
        $newAnnouncement = [
            'id' => $newId,
            'title' => $title,
            'summary' => $summary,
            'date' => date('Y-m-d'), // 自动使用当前日期
            'tags' => array_values($validTags), // 重新索引
            'visible' => $visible
        ];
        $data['announcements'][] = $newAnnouncement;
        writeData($data);
        $success = true;
        // 重定向到查看页面，并提示发布成功
        header('Location: index.php?published=1');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>发布公告 · 管理后台</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: #f5f7fb;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            line-height: 1.5;
            color: #1e293b;
            padding: 2rem 1.5rem;
            display: flex;
            justify-content: center;
        }
        .container {
            max-width: 720px;
            width: 100%;
        }
        .card {
            background: #ffffff;
            border-radius: 32px;
            padding: 2rem 2.2rem;
            box-shadow: 0 20px 35px -10px rgba(0,20,40,0.1);
            border: 1px solid #ffffffaa;
        }
        h1 {
            font-size: 2rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }
        h1 i {
            color: #2563eb;
            background: #e9effa;
            width: 48px;
            height: 48px;
            border-radius: 30% 70% 50% 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .alert-info {
            background: #e8f0fe;
            border-left: 5px solid #3b82f6;
            padding: 1rem 1.5rem;
            border-radius: 18px;
            margin-bottom: 2rem;
            color: #1e3a8a;
            font-size: 0.95rem;
        }
        .form-group {
            margin-bottom: 1.8rem;
        }
        label {
            font-weight: 600;
            display: block;
            margin-bottom: 0.5rem;
            color: #2d3c50;
        }
        input[type="text"], textarea {
            width: 100%;
            padding: 0.9rem 1.2rem;
            border: 1.5px solid #e2e8f0;
            border-radius: 24px;
            font-size: 1rem;
            transition: 0.15s;
            background: #ffffff;
            font-family: inherit;
        }
        input:focus, textarea:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px #bfdbfe;
        }
        textarea {
            min-height: 120px;
            resize: vertical;
        }
        .checkbox-group {
            background: #f8fafc;
            border-radius: 24px;
            padding: 1.2rem 1.5rem;
            border: 1px solid #e2e8f0;
        }
        .checkbox-item {
            display: inline-block;
            margin-right: 1.2rem;
            margin-bottom: 0.6rem;
        }
        .checkbox-item input {
            margin-right: 0.4rem;
            transform: scale(1.1);
            accent-color: #2563eb;
        }
        .checkbox-item label {
            display: inline;
            font-weight: 500;
            color: #334155;
        }
        .visible-checkbox {
            margin: 1rem 0 0.5rem;
        }
        .visible-checkbox input {
            margin-right: 0.5rem;
            transform: scale(1.1);
        }
        .btn {
            background: #1e2f4a;
            color: white;
            border: none;
            padding: 1rem 2.5rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 8px 18px -8px #0b1e33;
        }
        .btn:hover {
            background: #113355;
            transform: scale(1.02);
        }
        .btn-secondary {
            background: white;
            color: #2c3e5c;
            border: 1.5px solid #cbd5e1;
            box-shadow: none;
            margin-left: 1rem;
        }
        .btn-secondary:hover {
            background: #f1f6fd;
            transform: none;
        }
        .error {
            background: #fee9e7;
            border-left: 5px solid #dc2626;
            padding: 1rem 1.5rem;
            border-radius: 18px;
            margin-bottom: 2rem;
            color: #991b1b;
            font-weight: 500;
        }
        .action-bar {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            margin-top: 2rem;
        }
        .note {
            margin-top: 1.5rem;
            color: #64748b;
            font-size: 0.9rem;
            border-top: 1px dashed #cbd5e1;
            padding-top: 1.2rem;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <h1>
            <i class="fa-regular fa-pen-to-square"></i> 发布公告
        </h1>
        <div class="alert-info">
            <i class="fa-regular fa-lock"></i> 管理员模式 · 标签只能从下方勾选（如需新标签请直接编辑 AnData.json）
        </div>

        <?php if ($error): ?>
            <div class="error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <div class="form-group">
                <label>标题 <span style="color:#c00;">*</span></label>
                <input type="text" name="title" value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" placeholder="公告标题" required>
            </div>
            <div class="form-group">
                <label>内容</label>
                <textarea name="summary" placeholder="公告详细内容……"><?= htmlspecialchars($_POST['summary'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>选择标签（可多选）</label>
                <div class="checkbox-group">
                    <?php foreach ($data['tags'] as $tag): ?>
                        <span class="checkbox-item">
                            <input type="checkbox" name="tags[]" value="<?= htmlspecialchars($tag) ?>" id="tag_<?= htmlspecialchars($tag) ?>" 
                                <?= (isset($_POST['tags']) && in_array($tag, $_POST['tags'])) ? 'checked' : '' ?>>
                            <label for="tag_<?= htmlspecialchars($tag) ?>"><?= htmlspecialchars($tag) ?></label>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="form-group visible-checkbox">
                <input type="checkbox" name="visible" id="visible" value="1" <?= (isset($_POST['visible']) || !isset($_POST['title'])) ? 'checked' : '' ?>>
                <label for="visible" style="display: inline;">公开此公告（用户可见）</label>
            </div>

            <div class="action-bar">
                <button type="submit" class="btn"><i class="fa-regular fa-paper-plane"></i> 发布公告</button>
                <a href="index.php" class="btn btn-secondary"><i class="fa-regular fa-eye"></i> 返回查看页面</a>
            </div>
        </form>

        <div class="note">
            <i class="fa-regular fa-circle-info"></i> 公告日期自动使用今天。未公开的公告不会在用户页面显示。
        </div>
    </div>
</div>
</body>
</html>