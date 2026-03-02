<?php
// index.php - 用户查看公告列表（仅显示 visible = true 的公告）
header('Content-Type: text/html; charset=utf-8');

function readData() {
    $file = 'AnData.json';
    if (!file_exists($file)) {
        // 若文件不存在，返回空数据（publish.php 会创建）
        return ['tags' => [], 'announcements' => []];
    }
    $content = file_get_contents($file);
    $data = json_decode($content, true);
    if (!$data || !isset($data['announcements'])) {
        return ['tags' => [], 'announcements' => []];
    }
    // 确保 visible 字段存在（默认为 false）
    foreach ($data['announcements'] as &$ann) {
        if (!isset($ann['visible'])) {
            $ann['visible'] = false;
        }
    }
    return $data;
}

$data = readData();
// 仅筛选 visible = true 的公告
$allAnnouncements = array_filter($data['announcements'], function($ann) {
    return $ann['visible'] === true;
});
// 按 ID 降序排序（最新在前）
usort($allAnnouncements, function($a, $b) {
    return $b['id'] - $a['id'];
});

// 检查是否刚刚发布成功（发布后重定向带来）
$justPublished = isset($_GET['published']) && $_GET['published'] == '1';
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NTC - An</title>
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
            flex-direction: column;
            align-items: center;
        }
        .wrapper {
            max-width: 860px;
            width: 100%;
        }
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 2.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .header h1 {
            font-size: 2.2rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }
        .header h1 i {
            color: #2563eb;
            background: rgba(37,99,235,0.1);
            width: 52px;
            height: 52px;
            border-radius: 40% 60% 50% 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        /* 不再显示发布按钮 */
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
        .announcement-list {
            display: flex;
            flex-direction: column;
            gap: 1.3rem;
        }
        .card {
            background: #ffffff;
            border-radius: 28px;
            padding: 1.5rem 1.8rem;
            box-shadow: 0 12px 28px -12px rgba(0,32,64,0.08);
            border: 1px solid #eaf0f8;
            transition: 0.2s;
        }
        .card:hover {
            border-color: #cbdff5;
            box-shadow: 0 18px 32px -12px #1e3f5e1a;
        }
        .card-title {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: #0b1e32;
        }
        .card-summary {
            color: #475569;
            margin-bottom: 1rem;
            font-size: 0.95rem;
            word-break: break-word;
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
            background: #f1f6fc;
            padding: 0.3rem 1.2rem 0.3rem 0.9rem;
            border-radius: 40px;
            font-size: 0.85rem;
            color: #425c7a;
            border: 1px solid #deecfb;
        }
        .tag-group {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .tag {
            display: inline-block;
            background: #ecf1f7;
            color: #254358;
            padding: 0.25rem 1rem;
            border-radius: 40px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.02em;
            border: 1px solid #d7e2ee;
        }
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: #f8fbfe;
            border-radius: 48px;
            color: #62748c;
        }
        hr {
            border: 1px dashed #d2e0f0;
            margin: 2.5rem 0 1rem;
        }
        .footer-note {
            text-align: center;
            color: #8a99b0;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="header">
        <h1>
            <i class="fa-regular fa-bell"></i> 公告中心
        </h1>
        <!-- 发布链接已移除，用户无法进入发布页 -->
    </div>

    <?php if ($justPublished): ?>
        <div class="success-message">
            <i class="fa-regular fa-circle-check"></i> 公告已成功发布！（若未公开则用户暂时不可见）
        </div>
    <?php endif; ?>

    <?php if (empty($allAnnouncements)): ?>
        <div class="empty-state">
            <i class="fa-regular fa-message" style="font-size: 3rem; opacity: 0.4; margin-bottom: 1rem;"></i>
            <p>暂无公开公告，请稍后访问。</p>
        </div>
    <?php else: ?>
        <div class="announcement-list">
            <?php foreach ($allAnnouncements as $ann): ?>
                <div class="card">
                    <div class="card-title"><?= htmlspecialchars($ann['title']) ?></div>
                    <div class="card-summary"><?= nl2br(htmlspecialchars($ann['summary'])) ?></div>
                    <div class="meta-row">
                        <span class="date"><i class="fa-regular fa-calendar"></i> <?= htmlspecialchars($ann['date']) ?></span>
                        <?php if (!empty($ann['tags'])): ?>
                            <div class="tag-group">
                                <?php foreach ($ann['tags'] as $tag): ?>
                                    <span class="tag"><?= htmlspecialchars($tag) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <hr>
    <div class="footer-note">
        <i class="fa-regular fa-clock"></i> 仅显示已公开的公告
    </div>
</div>
</body>
</html>