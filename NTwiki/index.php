<?php
ob_start(); // 启用输出缓冲，防止因BOM或空格导致的header错误

// ========== 新增：Session 支持（共享登录状态） ==========
session_set_cookie_params(['path' => '/']);
session_start();

// 用户个性化配置目录
define('USER_PS_DIR', __DIR__ . '/UserPS');
if (!file_exists(USER_PS_DIR)) {
    mkdir(USER_PS_DIR, 0755, true);
}

// 获取当前登录用户ID（来自聊天系统的session）
$currentUserId = $_SESSION['user_id'] ?? null;

// 加载用户个性化设置
$userSettings = [];
if ($currentUserId && file_exists(USER_PS_DIR . '/' . $currentUserId . '.json')) {
    $settingsContent = file_get_contents(USER_PS_DIR . '/' . $currentUserId . '.json');
    $userSettings = json_decode($settingsContent, true) ?: [];
}
// 默认设置（新增 useNoFlowWallpaper）
$defaultSettings = [
    'useWallpaper' => false,
    'useNoFlowWallpaper' => false,
    'wallpaperUrls' => [
        'https://www.loliapi.com/acg/',
        'https://bing.img.run/rand.php'
    ],
    'autoSwitch' => false
];
$userSettings = array_merge($defaultSettings, $userSettings);

// ========== 新增：处理保存个性化设置的请求 ==========
if (isset($_GET['action']) && $_GET['action'] === 'saveSettings') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$currentUserId) {
        echo json_encode(['success' => false, 'error' => '未登录']);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        echo json_encode(['success' => false, 'error' => '无效的请求数据']);
        exit;
    }
    $useWallpaper = isset($input['useWallpaper']) ? (bool)$input['useWallpaper'] : false;
    $useNoFlowWallpaper = isset($input['useNoFlowWallpaper']) ? (bool)$input['useNoFlowWallpaper'] : false;
    $wallpaperUrls = isset($input['wallpaperUrls']) && is_array($input['wallpaperUrls']) ? $input['wallpaperUrls'] : [];
    $autoSwitch = isset($input['autoSwitch']) ? (bool)$input['autoSwitch'] : false;

    // 互斥校验：如果两者同时为 true，强制关闭无流量壁纸（可自行调整策略）
    if ($useWallpaper && $useNoFlowWallpaper) {
        $useNoFlowWallpaper = false; // 或返回错误，这里选择自动纠正
    }

    // 过滤 URL：只保留有效的 http/https 链接
    $filteredUrls = [];
    foreach ($wallpaperUrls as $url) {
        $url = trim($url);
        if (filter_var($url, FILTER_VALIDATE_URL) && preg_match('/^https?:\/\//i', $url)) {
            $filteredUrls[] = $url;
        }
    }
    if (empty($filteredUrls)) {
        $filteredUrls = $defaultSettings['wallpaperUrls'];
    }

    $newSettings = [
        'useWallpaper' => $useWallpaper,
        'useNoFlowWallpaper' => $useNoFlowWallpaper,
        'wallpaperUrls' => $filteredUrls,
        'autoSwitch' => $autoSwitch
    ];

    file_put_contents(USER_PS_DIR . '/' . $currentUserId . '.json', json_encode($newSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo json_encode(['success' => true, 'settings' => $newSettings]);
    exit;
}

// ========== 原有逻辑（文档列表扫描等）保持不变 ==========
define('DOCS_DIR', 'docs/');

$parsedownAvailable = false;
$Parsedown = null;
if (file_exists('Parsedown.php')) {
    require_once 'Parsedown.php';
    if (class_exists('Parsedown')) {
        $Parsedown = new Parsedown();
        $parsedownAvailable = true;
    }
}

function simpleMarkdown($text) {
    $safe = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    return '<div class="fallback-warning">⚠️ 未检测到 Parsedown 库，正在显示原始 Markdown 文本。请将 <a href="https://github.com/erusev/parsedown" target="_blank" style="text-decoration:underline;">Parsedown.php</a> 放入同一目录以获得渲染效果。</div>'
           . '<pre class="raw-markdown">' . $safe . '</pre>';
}

function formatFileTime($timestamp) {
    return date('Y-m-d H:i:s', $timestamp);
}

function updateMdTimeJson($docs) {
    $jsonFile = __DIR__ . '/MdData.json';  // 修改文件名
    $oldData = [];
    if (file_exists($jsonFile)) {
        $content = file_get_contents($jsonFile);
        $oldData = json_decode($content, true);
        if (!is_array($oldData)) {
            $oldData = [];
        }
    }
    $newData = [];
    $now = time();
    foreach ($docs as $doc) {
        $name = $doc['name'];
        $mtime = $doc['mtime'];
        if (isset($oldData[$name])) {
            $ctime = $oldData[$name]['ctime'] ?? $now;
            $ocd   = $oldData[$name]['ocd'] ?? false;   // 保留原有ocd或默认false
            $ocwd   = $oldData[$name]['ocwd'] ?? false;   // 保留原有ocd或默认false
        } else {
            $ctime = $now;
            $ocd   = false;                              // 新文档默认false
            $ocwd   = false;                              // 新文档默认false
        }
        $newData[$name] = [
            'ctime' => $ctime,
            'mtime' => $mtime,
            'ocd'   => $ocd,                             // 写入ocd字段
            'ocwd'   => $ocwd,                             // 写入ocd字段
        ];
    }
    @file_put_contents($jsonFile, json_encode($newData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// 处理文档获取请求
if (isset($_GET['action']) && $_GET['action'] === 'get' && isset($_GET['doc'])) {
    header('Content-Type: text/html; charset=utf-8');

    $docParam = trim($_GET['doc']);
    if (!preg_match('/^[\p{L}\p{N}_\-.]+$/u', $docParam)) {
        http_response_code(400);
        echo '<div class="error">无效的文档名称</div>';
        exit;
    }

    $filename = $docParam . '.md';
    $filePath = DOCS_DIR . $filename;

    $realBase = realpath(DOCS_DIR);
    if ($realBase === false) {
        http_response_code(500);
        echo '<div class="error">文档目录不存在</div>';
        exit;
    }
    $realFile = realpath($filePath);
    if ($realFile === false || strpos($realFile, $realBase) !== 0 || !file_exists($realFile)) {
        http_response_code(404);
        echo '<div class="error">文档未找到</div>';
        exit;
    }

    $content = file_get_contents($realFile);
    if ($content === false) {
        http_response_code(500);
        echo '<div class="error">无法读取文档</div>';
        exit;
    }

    $ctime = null;
    $mtime = null;
    $jsonFile = __DIR__ . '/MdData.json';
    if (file_exists($jsonFile)) {
        $jsonData = json_decode(file_get_contents($jsonFile), true);
        if (isset($jsonData[$docParam])) {
            $ctime = $jsonData[$docParam]['ctime'];
            $mtime = $jsonData[$docParam]['mtime'];
            $ocd   = $jsonData[$docParam]['ocd'] ?? false;   // 读取ocd字段
            $ocwd   = $jsonData[$docParam]['ocwd'] ?? false;   // 读取ocd字段
        }
    }
    if ($ctime === null) {
        $ctime = filectime($realFile);
    }
    if ($mtime === null) {
        $mtime = filemtime($realFile);
    }

    $timeStrCreate = formatFileTime($ctime);
    $timeStrMod   = formatFileTime($mtime);

    if ($parsedownAvailable && $Parsedown) {
        $bodyHtml = $Parsedown->text($content);
    } else {
        $bodyHtml = simpleMarkdown($content);
    }

    $footerHtml = '<div class="doc-footer-time"><svg class="icon" style="width: 1em;height: 1em;vertical-align: middle;fill: currentColor;overflow: hidden;" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="4749"><path d="M914.181742 251.621027L672.174208 10.295205A34.085568 34.085568 0 0 0 645.587465 0.069535H134.303944a34.085568 34.085568 0 0 0-34.085569 34.085568v954.395906a34.085568 34.085568 0 0 0 34.085569 34.085568h755.336188a34.085568 34.085568 0 0 0 34.085569-34.085568V272.754079a34.085568 34.085568 0 0 0-9.543959-21.133052z m-92.712746 3.408557H666.720517V100.962816zM168.389512 954.465441V68.240671h430.159869v220.874481a34.085568 34.085568 0 0 0 34.085568 34.085568h222.919615V954.465441z" fill="currentColor" p-id="4750"></path><path d="M713.758601 545.438624H548.10274V379.782763a34.085568 34.085568 0 0 0-68.171136 0V545.438624H304.731784a34.085568 34.085568 0 0 0-34.085568 34.085568 33.403857 33.403857 0 0 0 4.771979 16.361073 34.085568 34.085568 0 0 0 31.358723 21.133052h170.427841v170.42784a34.085568 34.085568 0 1 0 68.171136 0V618.38174h170.42784a34.085568 34.085568 0 0 0 34.085568-34.085568 33.403857 33.403857 0 0 0-4.771979-16.361073A34.085568 34.085568 0 0 0 713.758601 545.438624z" fill="currentColor" p-id="4751"></path></svg> 创建时间：' . $timeStrCreate . '  <svg class="icon" style="width: 1em;height: 1em;vertical-align: middle;fill: currentColor;overflow: hidden;" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="5477"><path d="M775.84 392.768l-155.2-172.352L160.768 643.264l-38.368 187.936 190.56-12.832zM929.952 229.952l-131.2-150.944-0.288-0.32a16 16 0 0 0-22.592-0.96l-131.168 120.576 155.168 172.352 128.832-118.464a15.936 15.936 0 0 0 1.248-22.24zM96 896h832v64H96z" p-id="5478"></path></svg> 最后编辑：' . $timeStrMod . '</div>';
    if ($ocd) {
        $footerHtml .= '<div class="doc-ocd">' .
            '<svg class="icon" style="width:1em;height:1em;vertical-align:middle;fill:currentColor;overflow:hidden;" viewBox="0 0 1024 1024">' .
            '<path d="M512 64L128 192v384c0 256 384 384 384 384s384-128 384-384V192L512 64z m0 128c70.4 0 128 57.6 128 128s-57.6 128-128 128-128-57.6-128-128 57.6-128 128-128z m0 512c-85.333333 0-160-42.666667-213.333333-106.666667 0-70.666667 128-106.666667 213.333333-106.666666s213.333333 36 213.333333 106.666666c-53.333333 64-128 106.666667-213.333333 106.666667z" fill="currentColor"></path>' .
            '</svg> 文档经过官方认证</div>';
    }
    if ($ocwd) {
        $footerHtml .= '<div class="doc-ocd">' .
            '<svg class="icon" style="width:1em;height:1em;vertical-align:middle;fill:currentColor;overflow:hidden;" viewBox="0 0 1024 1024">' .
            '<path d="M512 64L128 192v384c0 256 384 384 384 384s384-128 384-384V192L512 64z m0 128c70.4 0 128 57.6 128 128s-57.6 128-128 128-128-57.6-128-128 57.6-128 128-128z m0 512c-85.333333 0-160-42.666667-213.333333-106.666667 0-70.666667 128-106.666667 213.333333-106.666666s213.333333 36 213.333333 106.666666c-53.333333 64-128 106.666667-213.333333 106.666667z" fill="currentColor"></path>' .
            '</svg> 官方认证废物文档</div>';
    }
    echo $bodyHtml . $footerHtml;
    exit;
}

// 扫描 docs 目录，构建文档列表
$docs = [];
if (is_dir(DOCS_DIR)) {
    $files = glob(DOCS_DIR . '*.md');
    foreach ($files as $file) {
        $basename = basename($file, '.md');
        if ($basename !== '' && $basename[0] !== '.') {
            $mtime = filemtime($file);
            $docs[] = [
                'name'    => $basename,
                'display' => $basename,
                'mtime'   => $mtime,
                'file'    => $basename . '.md'
            ];
        }
    }
    usort($docs, function($a, $b) {
        return $b['mtime'] - $a['mtime'];
    });
    updateMdTimeJson($docs);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>NTC - wiki</title>
    <style>
        /* 原有样式保持不变，仅新增设置按钮及模态框样式，并添加动画 */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
        }

        body {
            height: 100vh;
            overflow: hidden;
            background-color: #f5f7fa;
            color: #1e293b;
            transition: background-color 0.2s, color 0.2s;
        }

        body.dark {
            background-color: #0f172a;
            color: #e2e8f0;
        }

        .app-container {
            display: flex;
            height: 100%;
            width: 100%;
        }

        .sidebar {
            width: 260px;
            background-color: white;
            border-right: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            transition: width 0.25s ease, background-color 0.2s;
            overflow-x: hidden;
            box-shadow: 1px 0 8px rgba(0,0,0,0.02);
            flex-shrink: 0;
        }

        body.dark .sidebar {
            background-color: #1e293b;
            border-right-color: #334155;
            box-shadow: 1px 0 8px rgba(0,0,0,0.3);
        }

        .sidebar.collapsed {
            width: 70px;
        }

        .top-icons {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 16px;
            border-bottom: 1px solid #e2e8f0;
            transition: border-color 0.2s;
        }

        body.dark .top-icons {
            border-bottom-color: #334155;
        }

        .sidebar.collapsed .top-icons {
            justify-content: center;
            flex-direction: column;
            gap: 20px;
            padding: 20px 0;
        }

        .icon-btn {
            font-size: 24px;
            cursor: pointer;
            user-select: none;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            transition: background-color 0.2s;
            color: #475569;
        }

        .icon-btns {
            font-size: 20px;
            cursor: pointer;
            user-select: none;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            transition: background-color 0.2s;
            color: #475569;
        }

        body.dark .icon-btn {
            color: #cbd5e1;
        }

        body.dark .icon-btns {
            color: #cbd5e1;
        }

        .icon-btn:hover {
            background-color: #f1f5f9;
        }

        body.dark .icon-btn:hover {
            background-color: #334155;
        }

        /* 太阳/月亮显示控制 */
        .sun-svg { display: none; }
        .moon-svg { display: inline-block; }
        body.dark .sun-svg { display: inline-block; }
        body.dark .moon-svg { display: none; }

        /* 设置按钮：仅在侧边栏折叠时显示 */
        .settings-icon {
            display: none;
        }
        .sidebar.collapsed .settings-icon {
            display: flex;
        }

        .doc-list {
            list-style: none;
            padding: 16px 8px;
            overflow-y: auto;
            flex: 1;
            transition: opacity 0.15s;
        }

        .sidebar.collapsed .doc-list {
            display: none;
        }

        .doc-item {
            position: relative;           /* 为伪元素定位 */
            padding: 10px 16px;
            margin: 4px 0;
            border-radius: 0.9px;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 500;
            color: #334155;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            transition: background-color 0.15s, color 0.15s;
            border-left: 3px solid transparent;
        }

        body.dark .doc-item {
            color: #cbd5e1;
        }

        .doc-item:hover {
            background-color: #f1f5f9;
        }

        body.dark .doc-item:hover {
            background-color: #2d3a4f;
        }

/* 蓝色竖线伪元素 */
.doc-item::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    width: 3px;
    height: 100%;
    background-color: #4f46e5;        /* 蓝色 */
    transform: scaleY(0);              /* 默认收起（高度为0） */
    transform-origin: center;          /* 从中心缩放 */
    transition: transform 0.2s ease;
    z-index: 1;
}

body.dark .doc-item::before {
    background-color: #818cf8;
}

/* 激活状态：竖线展开，字体变蓝 */
.doc-item.active {
    color: #4f46e5;
    font-weight: 600;
}

body.dark .doc-item.active {
    color: #a5b4fc;
}

.doc-item.active::before {
    transform: scaleY(1);              /* 展开 */
}

        .doc-item.active {
            background-color: #eef2ff;
            border-left-color: #4f46e5;
            color: #4f46e5;
            font-weight: 600;
        }

        body.dark .doc-item.active {
            background-color: #2d3a4f;
            border-left-color: #818cf8;
            color: #a5b4fc;
        }

        .content-area {
            flex: 1;
            padding: 30px 40px;
            overflow-y: auto;
            background-color: #f8fafc;
            transition: background-color 0.2s, background-image 0.3s ease;
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            position: relative; /* 为伪元素定位 */
            z-index: 1;
        }

        body.dark .content-area {
            background-color: #0f172a;
        }

        /* 磨砂玻璃伪元素 - 仅在启用外部壁纸时显示 */
        .content-area.has-wallpaper::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
            z-index: -1;
            pointer-events: none;
        }
        body.dark .content-area.has-wallpaper::before {
            background-color: rgba(0, 0, 0, 0.3);
        }

        /* 无流量壁纸样式（纯CSS渐变动画） */
        .content-area.noflow-wallpaper {
            background: linear-gradient(-45deg, #ee7752, #e73c7e, #23a6d5, #23d5ab);
            background-size: 400% 400%;
            animation: gradient 15s ease infinite;
        }
        body.dark .content-area.noflow-wallpaper {
            background: linear-gradient(-45deg, #1a1a2e, #16213e, #0f3460, #1a1a2e);
            background-size: 400% 400%;
        }
        @keyframes gradient {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        .markdown-body {
            max-width: 900px;
            margin: 0 auto;
            background-color: rgba(255, 255, 255, 0.8);
            padding: 32px 40px;
            border-radius: 5px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
            border: 1px solid rgba(226, 232, 240, 0.5);
            transition: background-color 0.2s, border-color 0.2s;
            position: relative;
        }

        body.dark .markdown-body {
            background-color: rgba(30, 41, 59, 0.8);
            border-color: rgba(51, 65, 85, 0.5);
        }

        .loader, .welcome-message {
            text-align: center;
            padding: 60px;
            color: #64748b;
            font-style: italic;
            font-size: 1.2rem;
        }

        .doc-footer-time {
            margin-top: 40px;
            padding-top: 20px;
            border-top: none;
            font-size: 0.85rem;
            color: #64748b;
            text-align: right;
            font-style: italic;
        }

        body.dark .doc-footer-time {
            color: #94a3b8;
        }

        /* 代码块复制按钮样式 */
        .markdown-body pre {
            position: relative;
        }

        .copy-code-btn {
            position: absolute;
            top: 8px;
            right: 8px;
            background-color: rgba(255, 255, 255, 0.8);
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 4px 8px;
            font-size: 14px;
            cursor: pointer;
            color: #1e293b;
            transition: background-color 0.2s;
            z-index: 10;
            line-height: 1;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        body.dark .copy-code-btn {
            background-color: #2d3a4f;
            border-color: #475569;
            color: #e2e8f0;
        }

        .copy-code-btn:hover {
            background-color: #e2e8f0;
        }

        body.dark .copy-code-btn:hover {
            background-color: #3f4a60;
        }

        .markdown-body h1,
        .markdown-body h2,
        .markdown-body h3,
        .markdown-body h4,
        .markdown-body h5,
        .markdown-body h6 {
            color: #0f172a;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 0.3em;
            margin-top: 1.5em;
            margin-bottom: 0.8em;
        }

        body.dark .markdown-body h1,
        body.dark .markdown-body h2,
        body.dark .markdown-body h3,
        body.dark .markdown-body h4,
        body.dark .markdown-body h5,
        body.dark .markdown-body h6 {
            color: #f1f5f9;
            border-bottom-color: #334155;
        }

        .markdown-body p {
            line-height: 1.7;
            margin: 1.2em 0;
            color: #1e293b;
        }

        body.dark .markdown-body p {
            color: #e2e8f0;
        }

        .markdown-body code {
            background-color: #f1f5f9;
            padding: 0.2em 0.4em;
            border-radius: 6px;
            font-family: 'JetBrains Mono', 'Fira Code', monospace;
            font-size: 0.9em;
            color: #dc2626;
        }

        body.dark .markdown-body code {
            background-color: #2d3a4f;
            color: #f87171;
        }

        .markdown-body pre {
            background-color: #f1f5f9;
            padding: 16px;
            border-radius: 12px;
            overflow-x: auto;
            border: 1px solid #e2e8f0;
        }

        body.dark .markdown-body pre {
            background-color: #1e293b;
            border-color: #334155;
        }

        .markdown-body pre code {
            background-color: transparent;
            color: inherit;
            padding: 0;
        }

        .markdown-body a {
            color: #4f46e5;
            text-decoration: none;
        }

        body.dark .markdown-body a {
            color: #818cf8;
        }

        .markdown-body blockquote {
            border-left: 4px solid #cbd5e1;
            padding-left: 1em;
            color: #475569;
            margin: 1em 0;
        }

        body.dark .markdown-body blockquote {
            border-left-color: #475569;
            color: #94a3b8;
        }

        .markdown-body table {
            border-collapse: collapse;
            width: 100%;
            overflow-x: auto;
            display: block;
        }

        .markdown-body th,
        .markdown-body td {
            border: 1px solid #e2e8f0;
            padding: 8px 12px;
        }

        body.dark .markdown-body th,
        body.dark .markdown-body td {
            border-color: #334155;
        }

        .empty-docs {
            padding: 32px 16px;
            text-align: center;
            color: #94a3b8;
            font-size: 0.9rem;
        }

        .markdown-body pre.raw-markdown {
            background-color: #1e1e2f;
            color: #f8f8f2;
            padding: 20px;
            border-radius: 12px;
            overflow-x: auto;
            font-family: 'Fira Code', monospace;
            font-size: 0.9rem;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        body.dark .markdown-body pre.raw-markdown {
            background-color: #0d1117;
        }
        .fallback-warning {
            background: #fff3cd;
            border-left: 6px solid #ffc107;
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            color: #856404;
        }
        body.dark .fallback-warning {
            background: #332d1a;
            border-left-color: #ffb347;
            color: #ffd966;
        }

        /* ----------------- 响应式适配：手机 & 低分辨率设备 ----------------- */
        @media (max-width: 768px) {
            .content-area {
                padding: 20px;
            }
            .markdown-body {
                padding: 20px;
            }
            .sidebar {
                width: 220px;
            }
            .sidebar.collapsed {
                width: 60px;
            }
            .icon-btn {
                width: 28px;
                height: 28px;
                font-size: 22px;
            }
            .doc-item {
                font-size: 0.9rem;
                padding: 10px 12px;
            }
            .loader, .welcome-message {
                padding: 40px 20px;
                font-size: 1rem;
            }
            .markdown-body table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
        }

        @media (max-width: 480px) {
            .content-area {
                padding: 12px;
            }
            .markdown-body {
                padding: 12px;
            }
            .sidebar {
                width: 200px;
                max-width: 70vw;
            }
            .sidebar.collapsed {
                width: 50px;
            }
            .sidebar.collapsed .top-icons {
                padding: 16px 0;
                gap: 16px;
            }
            .icon-btn {
                width: 26px;
                height: 26px;
                font-size: 20px;
            }
            .doc-item {
                font-size: 0.85rem;
                padding: 10px 8px;
            }
            .copy-code-btn {
                font-size: 12px;
                padding: 2px 6px;
                top: 4px;
                right: 4px;
            }
            .loader, .welcome-message {
                padding: 30px 12px;
                font-size: 0.95rem;
            }
            .doc-footer-time {
                font-size: 0.75rem;
            }
            .icon-btn svg {
                width: 1.2em;
                height: 1.2em;
            }
        }

        @media (max-width: 360px) {
            .sidebar.collapsed {
                width: 44px;
            }
            .sidebar.collapsed .icon-btn {
                width: 24px;
                height: 24px;
                font-size: 18px;
            }
            .content-area {
                padding: 8px;
            }
            .markdown-body {
                padding: 8px;
            }
        }

        @media (max-width: 500px) {
            .sidebar:not(.collapsed) {
                width: 180px;
            }
        }
        body.dark .sidebar:not(.collapsed) {
            box-shadow: 2px 0 10px rgba(0,0,0,0.5);
        }

        /* ---------- 新增：设置模态框样式（带动画，修复文字对齐） ---------- */
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
            animation: fadeIn 0.2s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background: var(--modal-bg, white);
            padding: 25px;
            border-radius: 12px;
            min-width: 400px;
            max-width: 600px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            color: var(--text-color, #1e293b);
            animation: slideUp 0.2s ease;
        }

        @keyframes slideUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        body.dark .modal-content {
            background: #1e293b;
            color: #e2e8f0;
        }

        .modal-content h3 {
            margin-bottom: 20px;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .modal-content label {
            display: block;
            margin: 15px 0 5px;
            font-weight: 500;
        }

        .modal-content input[type="text"],
        .modal-content textarea {
            width: 100%;
            padding: 8px 12px;
            margin: 5px 0 10px;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            font-size: 14px;
            background: #fff;
            color: #1e293b;
        }

        body.dark .modal-content input[type="text"],
        body.dark .modal-content textarea {
            background: #0f172a;
            border-color: #334155;
            color: #e2e8f0;
        }

        .modal-content textarea {
            min-height: 80px;
            resize: vertical;
        }

        /* 修复复选框与文字对齐 */
        .modal-content .checkbox-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 10px 0;
        }

        .modal-content .checkbox-row input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin: 0; /* 移除默认边距 */
            flex-shrink: 0;
        }

        .modal-content .checkbox-row label {
            margin: 0; /* 移除默认边距 */
            font-weight: normal;
            line-height: 1.4;
        }

        .modal-content .hint {
            font-size: 0.85rem;
            color: #64748b;
            margin-top: -5px;
            margin-bottom: 10px;
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
            background: #4f46e5;
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
            color: #dc2626;
            font-size: 0.9em;
            margin-top: 5px;
        }

        .success {
            color: #16a34a;
        }

/* 汉堡图标容器 */
.hamburger {
    display: inline-block;
    width: 0.9em;          /* 与图标按钮字体大小协调 */
    height: 1.2em;
    position: relative;
}

/* 三条线的公共样式 */
.hamburger::before,
.hamburger::after,
.hamburger span {
    content: '';
    position: absolute;
    width: 100%;
    height: 2px;
    background-color: currentColor; /* 跟随文字颜色 */
    left: 0;
    transition: transform 0.25s ease, opacity 0.25s ease;
}

/* 上横线 */
.hamburger::before {
    top: 0.2em;             /* 顶部距离，可根据实际微调 */
}

/* 中间横线（用 span 元素） */
.hamburger span {
    top: 50%;
    transform: translateY(-50%);
}

/* 下横线 */
.hamburger::after {
    bottom: 0.2em;
}

/* 侧边栏展开时（collapsed 类不存在），汉堡变为叉号 */
.sidebar:not(.collapsed) .hamburger::before {
    transform: rotate(45deg) translate(0.25em, 0.25em);
}
.sidebar:not(.collapsed) .hamburger span {
    opacity: 0;              /* 中间线消失 */
}
.sidebar:not(.collapsed) .hamburger::after {
    transform: rotate(-45deg) translate(0.25em, -0.25em);
}

/* 设置图标旋转动画 */
.settings-icon svg.rotate {
    animation: rotate90 0.8s ease;
}

@keyframes rotate90 {
    from { transform: rotate(0deg); }
    to { transform: rotate(180deg); }
}

/* 内容区域淡入淡出动画 */
.content-area.fade-out .markdown-body {
    opacity: 0;
    transition: opacity 0.2s ease;
}
.content-area .markdown-body {
    transition: opacity 0.3s ease;
}

/* 文档项点击反馈（瞬间缩放） */
.doc-item:active {
    transform: scale(0.98);
    transition: transform 0.1s;
}

/* 官方认证标记样式 */
.doc-ocd {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px dashed #ccc;
    font-size: 0.9rem;
    color: #4f46e5;
    display: flex;
    align-items: center;
    gap: 5px;
}
body.dark .doc-ocd {
    color: #a5b4fc;
    border-top-color: #334155;
}
    </style>
</head>
<body>
    <?php
    // 后端 PHP 逻辑（完全保留原时间记录功能）
    // 已在上方处理
    ?>

    <div class="app-container">
        <div class="sidebar collapsed" id="sidebar">
            <div class="top-icons">
                <span class="icon-btn" id="menuToggle" title="展开/折叠菜单"><span class="hamburger"><span></span></span></span>
                <span class="icon-btn" id="darkModeToggle" title="暗色模式">
                    <svg class="sun-svg" style="width: 1em;height: 1em;vertical-align: middle;fill: currentColor;overflow: hidden;" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="787">
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
                </span>
                <span class="icon-btn" id="homeBtn" title="返回首页">
                    <svg class="icon" style="width: 1em;height: 1em;vertical-align: middle;fill: currentColor;overflow: hidden;" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="7257"><path d="M424.319032 885.693004 424.319032 620.123556 601.364307 620.123556 601.364307 885.693004 822.671669 885.693004 822.671669 531.60143 955.455881 531.60143 512.841158 133.24777 70.226434 531.60143 203.01167 531.60143 203.01167 885.693004Z" fill="currentColor" p-id="7258"></path></svg>
                </span>
                <!-- 新增：设置按钮，仅在侧边栏折叠时显示 -->
                <span class="icon-btns settings-icon" id="settingsBtn" title="个性化设置">
                    <svg class="icon" style="width: 1em;height: 1em;vertical-align: middle;fill: currentColor;overflow: hidden;" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="1199"><path d="M892.375523 560.725628L895.422779 511.969527 892.375523 460.166171 999.029492 377.890251C1011.218518 368.748482 1011.218518 356.559457 1005.124005 344.370432L904.564548 167.629568C898.470035 155.440543 883.233754 152.393287 874.091985 155.440543L746.107221 207.2439A310.82014 310.82014 0 0 0 657.736789 158.487799L639.453251 21.361267C639.453251 9.172241 627.264226 0.030473 615.075201 0.030473H410.909031a30.472563 30.472563 0 0 0-27.425307 21.330794L365.200186 158.487799a295.583859 295.583859 0 0 0-85.323175 48.756101L151.892247 155.440543C139.703222 152.393287 127.514197 155.440543 121.419684 167.629568L17.812971 344.370432C11.718458 356.559457 14.765714 368.748482 23.907483 377.890251l109.701226 82.27592-6.094512 51.803356 6.094512 48.756101-109.701226 85.323176C14.765714 652.143316 11.718458 667.379598 17.812971 679.568623l103.606713 176.740864c6.094513 9.141769 18.283538 15.236281 30.472563 9.141769L279.877011 816.695155a390.048804 390.048804 0 0 0 85.323175 48.756101l18.283538 137.126532A27.425307 27.425307 0 0 0 410.909031 1023.908582h204.16617C627.264226 1023.908582 636.405995 1014.766813 639.453251 1002.577788L657.736789 865.451256A411.379598 411.379598 0 0 0 746.107221 816.695155l127.984764 48.756101c9.141769 6.094513 24.37805 0 30.472563-9.141769L1005.124005 679.568623C1011.218518 667.379598 1008.171261 652.143316 999.029492 646.048804L892.375523 560.725628zM511.468488 691.757648A179.78812 179.78812 0 0 1 334.727624 511.969527a176.740864 176.740864 0 1 1 356.528984 0 182.835377 182.835377 0 0 1-179.78812 179.788121z" p-id="1200"></path></svg>
                </span>
            </div>
            <ul class="doc-list" id="docList">
                <?php if (empty($docs)): ?>
                    <div class="empty-docs">📂 NTwiki/docs 中没有 .md 文档</div>
                <?php else: ?>
                    <?php foreach ($docs as $doc): ?>
                        <li class="doc-item" data-doc="<?= htmlspecialchars($doc['name']) ?>">
                            <?= htmlspecialchars($doc['display']) ?>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>

        <main class="content-area" id="contentArea">
            <div class="markdown-body" id="markdownRenderer">
                <div class="welcome-message"><svg class="icon" style="width: 1em;height: 1em;vertical-align: middle;fill: currentColor;overflow: hidden;" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="6159"><path d="M469.333333 768c-166.4 0-298.666667-132.266667-298.666666-298.666667s132.266667-298.666667 298.666666-298.666666 298.666667 132.266667 298.666667 298.666666-132.266667 298.666667-298.666667 298.666667z m0-85.333333c119.466667 0 213.333333-93.866667 213.333334-213.333334s-93.866667-213.333333-213.333334-213.333333-213.333333 93.866667-213.333333 213.333333 93.866667 213.333333 213.333333 213.333334z m251.733334 0l119.466666 119.466666-59.733333 59.733334-119.466667-119.466667 59.733334-59.733333z" fill="currentColor" p-id="6160"></path></svg> 快去打开一份文档查看罢</div>
            </div>
        </main>
    </div>

    <!-- 新增：设置模态框（包含互斥的壁纸选项） -->
    <div class="modal" id="settingsModal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('settingsModal')">&times;</span>
            <h3><svg class="icon" style="width: 1em;height: 1em;vertical-align: middle;fill: currentColor;overflow: hidden;" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="1199"><path d="M892.375523 560.725628L895.422779 511.969527 892.375523 460.166171 999.029492 377.890251C1011.218518 368.748482 1011.218518 356.559457 1005.124005 344.370432L904.564548 167.629568C898.470035 155.440543 883.233754 152.393287 874.091985 155.440543L746.107221 207.2439A310.82014 310.82014 0 0 0 657.736789 158.487799L639.453251 21.361267C639.453251 9.172241 627.264226 0.030473 615.075201 0.030473H410.909031a30.472563 30.472563 0 0 0-27.425307 21.330794L365.200186 158.487799a295.583859 295.583859 0 0 0-85.323175 48.756101L151.892247 155.440543C139.703222 152.393287 127.514197 155.440543 121.419684 167.629568L17.812971 344.370432C11.718458 356.559457 14.765714 368.748482 23.907483 377.890251l109.701226 82.27592-6.094512 51.803356 6.094512 48.756101-109.701226 85.323176C14.765714 652.143316 11.718458 667.379598 17.812971 679.568623l103.606713 176.740864c6.094513 9.141769 18.283538 15.236281 30.472563 9.141769L279.877011 816.695155a390.048804 390.048804 0 0 0 85.323175 48.756101l18.283538 137.126532A27.425307 27.425307 0 0 0 410.909031 1023.908582h204.16617C627.264226 1023.908582 636.405995 1014.766813 639.453251 1002.577788L657.736789 865.451256A411.379598 411.379598 0 0 0 746.107221 816.695155l127.984764 48.756101c9.141769 6.094513 24.37805 0 30.472563-9.141769L1005.124005 679.568623C1011.218518 667.379598 1008.171261 652.143316 999.029492 646.048804L892.375523 560.725628zM511.468488 691.757648A179.78812 179.78812 0 0 1 334.727624 511.969527a176.740864 176.740864 0 1 1 356.528984 0 182.835377 182.835377 0 0 1-179.78812 179.788121z" p-id="1200"></path></svg> 设置</h3>

            <label>文档背景</label>
            <!-- 启用外部壁纸 -->
            <div class="checkbox-row">
                <input type="checkbox" id="useWallpaper" <?= $userSettings['useWallpaper'] ? 'checked' : '' ?>>
                <label for="useWallpaper">启用壁纸（加载外部图片）</label>
            </div>

            <!-- 启用无流量壁纸 -->
            <div class="checkbox-row">
                <input type="checkbox" id="useNoFlowWallpaper" <?= $userSettings['useNoFlowWallpaper'] ? 'checked' : '' ?>>
                <label for="useNoFlowWallpaper">启用无流量壁纸（纯CSS渐变，零带宽）</label>
            </div>

            <label>壁纸URL列表（每行一个）</label>
            <textarea id="wallpaperUrls" placeholder="https://..."><?= htmlspecialchars(implode("\n", $userSettings['wallpaperUrls'])) ?></textarea>
            <div class="hint">内置推荐：https://www.loliapi.com/acg/ 和 https://bing.img.run/rand.php<br>⚠️ 不理解API接口和URL请勿随意更改</div>

            <div class="checkbox-row">
                <input type="checkbox" id="autoSwitch" <?= $userSettings['autoSwitch'] ? 'checked' : '' ?>>
                <label for="autoSwitch">每分钟自动切换（仅外部壁纸有效）</label>
            </div>

            <div id="settingsMessage" style="margin: 10px 0;"></div>

            <button class="primary" onclick="saveSettings()">保存设置</button>
            <button class="secondary" onclick="closeModal('settingsModal')">取消</button>
        </div>
    </div>

    <script>
        (function() {
            const sidebar = document.getElementById('sidebar');
            const menuToggle = document.getElementById('menuToggle');
            const darkToggle = document.getElementById('darkModeToggle');
            const homeBtn = document.getElementById('homeBtn');
            const settingsBtn = document.getElementById('settingsBtn');
            const docList = document.getElementById('docList');
            const contentArea = document.getElementById('contentArea');

            // 从PHP传递的用户设置
            const userSettings = <?php echo json_encode($userSettings); ?>;
            const isLoggedIn = <?php echo $currentUserId ? 'true' : 'false'; ?>;

            let autoSwitchInterval = null;
            let currentWallpaperUrl = null;

let rotateTimer = null; // 用于设置图标旋转动画的定时器

            // 应用背景壁纸到 content-area
            function applyWallpaper(url) {
                if (!url) return;
                // 清除其他背景类
                contentArea.classList.remove('noflow-wallpaper');
                contentArea.classList.add('has-wallpaper');
                contentArea.style.backgroundImage = `url('${url}')`;
                currentWallpaperUrl = url;
            }

            // 应用无流量壁纸
            function applyNoFlowWallpaper() {
                contentArea.classList.remove('has-wallpaper');
                contentArea.classList.add('noflow-wallpaper');
                contentArea.style.backgroundImage = ''; // 清除外部背景
                currentWallpaperUrl = null;
            }

            // 清除所有壁纸效果
            function clearAllWallpaper() {
                contentArea.classList.remove('has-wallpaper', 'noflow-wallpaper');
                contentArea.style.backgroundImage = '';
                currentWallpaperUrl = null;
            }

            // 从URL列表中随机选择一个
            function getRandomUrl(urls) {
                if (!urls || urls.length === 0) return null;
                return urls[Math.floor(Math.random() * urls.length)];
            }

            // 根据设置更新壁纸
            function updateWallpaperFromSettings(settings) {
                // 停止自动切换
                if (autoSwitchInterval) {
                    clearInterval(autoSwitchInterval);
                    autoSwitchInterval = null;
                }

                if (settings.useNoFlowWallpaper) {
                    applyNoFlowWallpaper();
                    return;
                }

                if (settings.useWallpaper) {
                    const urls = settings.wallpaperUrls.filter(u => u.trim() !== '');
                    if (urls.length === 0) {
                        clearAllWallpaper();
                        return;
                    }

                    const newUrl = getRandomUrl(urls);
                    if (newUrl) {
                        // 测试图片是否可加载
                        const img = new Image();
                        img.onload = function() {
                            applyWallpaper(newUrl);
                        };
                        img.onerror = function() {
                            console.warn('图片加载失败:', newUrl);
                            // 失败时尝试下一个？或者清空
                            clearAllWallpaper();
                        };
                        img.src = newUrl;
                    }

                    // 自动切换
                    if (settings.autoSwitch) {
                        autoSwitchInterval = setInterval(() => {
                            const nextUrl = getRandomUrl(urls);
                            if (nextUrl) {
                                const img = new Image();
                                img.onload = () => applyWallpaper(nextUrl);
                                img.onerror = () => {};
                                img.src = nextUrl;
                            }
                        }, 60000); // 每分钟
                    }
                } else {
                    clearAllWallpaper();
                }
            }

            // 初始化背景
            updateWallpaperFromSettings(userSettings);

            // 更新菜单图标（折叠/展开）
            function updateMenuIcon() {
                //const isCollapsed = sidebar.classList.contains('collapsed');
                //menuToggle.textContent = isCollapsed ? '☰' : '✕';
            }

            function toggleSidebar() {
                sidebar.classList.toggle('collapsed');
                updateMenuIcon();
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed') ? 'true' : 'false');
            }

            menuToggle.addEventListener('click', toggleSidebar);

            function setDarkMode(enable) {
                if (enable) {
                    document.body.classList.add('dark');
                } else {
                    document.body.classList.remove('dark');
                }
                localStorage.setItem('darkMode', enable ? 'dark' : 'light');
            }

            function toggleDarkMode() {
                setDarkMode(!document.body.classList.contains('dark'));
            }

            darkToggle.addEventListener('click', toggleDarkMode);

            homeBtn.addEventListener('click', () => {
                window.location.href = '/';
            });

            // 设置按钮点击
settingsBtn.addEventListener('click', () => {
    if (!isLoggedIn) {
        alert('请先登录以使用个性化设置');
        return;
    }

    // 触发旋转动画
    const iconSvg = settingsBtn.querySelector('svg');
    if (iconSvg) {
        // 清除之前的定时器，避免动画被提前中断
        if (rotateTimer) clearTimeout(rotateTimer);
        iconSvg.classList.add('rotate');
        rotateTimer = setTimeout(() => {
            iconSvg.classList.remove('rotate');
            rotateTimer = null;
        }, 800); // 与动画时长一致
    }

    // 填充当前设置到表单
    document.getElementById('useWallpaper').checked = userSettings.useWallpaper;
    document.getElementById('useNoFlowWallpaper').checked = userSettings.useNoFlowWallpaper;
    document.getElementById('wallpaperUrls').value = userSettings.wallpaperUrls.join('\n');
    document.getElementById('autoSwitch').checked = userSettings.autoSwitch;
    document.getElementById('settingsModal').style.display = 'flex';
});

            // 关闭模态框
            window.closeModal = function(id) {
                document.getElementById(id).style.display = 'none';
            };

            // 保存设置
            window.saveSettings = async function() {
                const useWallpaper = document.getElementById('useWallpaper').checked;
                const useNoFlowWallpaper = document.getElementById('useNoFlowWallpaper').checked;
                const wallpaperUrlsText = document.getElementById('wallpaperUrls').value;
                const autoSwitch = document.getElementById('autoSwitch').checked;
                const urls = wallpaperUrlsText.split('\n').map(u => u.trim()).filter(u => u !== '');

                // 简单验证 URL 格式
                const invalidUrls = urls.filter(u => !/^https?:\/\//i.test(u));
                if (invalidUrls.length > 0) {
                    document.getElementById('settingsMessage').innerHTML = '<span class="error">包含非HTTP/HTTPS链接：' + invalidUrls.join(', ') + '</span>';
                    return;
                }

                // 互斥处理：如果同时勾选，自动取消无流量壁纸
                if (useWallpaper && useNoFlowWallpaper) {
                    document.getElementById('useNoFlowWallpaper').checked = false;
                    // 重新获取值
                    const finalUseNoFlow = false;
                    // 可选：提示用户
                    document.getElementById('settingsMessage').innerHTML = '<span class="error">不能同时启用两种壁纸，已自动关闭无流量壁纸</span>';
                    // 稍后执行保存
                }

                // 重新获取最终值
                const finalUseWallpaper = document.getElementById('useWallpaper').checked;
                const finalUseNoFlow = document.getElementById('useNoFlowWallpaper').checked;

                // 测试图片加载（如果启用外部壁纸且有URL）
                if (finalUseWallpaper && urls.length > 0) {
                    const testImg = new Image();
                    testImg.onload = function() {
                        performSave(finalUseWallpaper, finalUseNoFlow, urls, autoSwitch);
                    };
                    testImg.onerror = function() {
                        document.getElementById('settingsMessage').innerHTML = '<span class="error">图片加载失败，请检查URL是否有效</span>';
                    };
                    testImg.src = urls[0];
                } else {
                    performSave(finalUseWallpaper, finalUseNoFlow, urls, autoSwitch);
                }
            };

            async function performSave(useWallpaper, useNoFlowWallpaper, urls, autoSwitch) {
                const settings = {
                    useWallpaper,
                    useNoFlowWallpaper,
                    wallpaperUrls: urls,
                    autoSwitch
                };

                try {
                    const response = await fetch('?action=saveSettings', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(settings)
                    });
                    const data = await response.json();
                    if (data.success) {
                        document.getElementById('settingsMessage').innerHTML = '<span class="success">保存成功</span>';
                        // 更新本地 userSettings
                        Object.assign(userSettings, data.settings);
                        // 应用新设置
                        updateWallpaperFromSettings(userSettings);
                        setTimeout(() => closeModal('settingsModal'), 1000);
                    } else {
                        document.getElementById('settingsMessage').innerHTML = '<span class="error">保存失败：' + (data.error || '未知错误') + '</span>';
                    }
                } catch (e) {
                    document.getElementById('settingsMessage').innerHTML = '<span class="error">请求异常：' + e.message + '</span>';
                }
            }

            // 为所有代码块添加复制按钮（原有函数）
            function addCopyButtonsToCodeBlocks() {
                const markdownBody = document.querySelector('.markdown-body');
                if (!markdownBody) return;
                const pres = markdownBody.querySelectorAll('pre');
                pres.forEach(pre => {
                    if (pre.querySelector('.copy-code-btn')) return;
                    const codeEl = pre.querySelector('code');
                    const codeText = codeEl ? codeEl.innerText : pre.innerText;
                    const btn = document.createElement('button');
                    btn.className = 'copy-code-btn';
                    btn.setAttribute('title', '复制代码');

                    const copyIcon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                    copyIcon.setAttribute('class', 'icon');
                    copyIcon.setAttribute('style', 'width: 1em; height: 1em; vertical-align: middle; fill: currentColor; overflow: hidden;');
                    copyIcon.setAttribute('viewBox', '0 0 1024 1024');
                    copyIcon.setAttribute('version', '1.1');
                    copyIcon.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
                    copyIcon.setAttribute('p-id', '8717');
                    copyIcon.innerHTML = '<path d="M682.666667 341.333333h128v469.333334H341.333333v-128H213.333333V213.333333h469.333334v128z m0 85.333334v256h-256v42.666666h298.666666v-298.666666h-42.666666zM298.666667 298.666667v298.666666h298.666666V298.666667H298.666667z" fill="currentColor" p-id="8718"></path>';
                    btn.appendChild(copyIcon);

                    btn.addEventListener('click', async (e) => {
                        e.stopPropagation();
                        try {
                            await navigator.clipboard.writeText(codeText);
                            const originalChildren = Array.from(btn.childNodes);
                            btn.innerHTML = '';
                            const successIcon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                            successIcon.setAttribute('class', 'icon');
                            successIcon.setAttribute('style', 'width: 1em; height: 1em; vertical-align: middle; fill: currentColor; overflow: hidden;');
                            successIcon.setAttribute('viewBox', '0 0 1024 1024');
                            successIcon.setAttribute('version', '1.1');
                            successIcon.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
                            successIcon.setAttribute('p-id', '9647');
                            successIcon.innerHTML = '<path d="M511.434623 63.564711c-247.428276 0-448.010617 200.582341-448.010617 448.01164 0 247.430322 200.581318 448.01164 448.010617 448.01164 247.432369 0 448.012663-200.581318 448.012663-448.01164C959.447287 264.147052 758.865969 63.564711 511.434623 63.564711zM773.519714 382.576325 447.871959 704.039781 245.771031 507.044128l63.996546-68.093864 138.15964 138.15964 261.086343-261.087367L773.519714 382.576325z" fill="currentColor" p-id="9648"></path>';
                            btn.appendChild(successIcon);
                            setTimeout(() => {
                                btn.innerHTML = '';
                                originalChildren.forEach(child => btn.appendChild(child));
                            }, 1500);
                        } catch (err) {
                            alert('复制失败，请手动复制');
                        }
                    });
                    pre.appendChild(btn);
                });
            }

              //  markdownDiv.innerHTML = '<div class="loader"><svg class="icon" style="width: 1em;height: 1em;vertical-align: middle;fill: currentColor;overflow: hidden;" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="7922"><path d="M674.133333 878.933333l-98.133333-102.4c128-17.066667 230.4-123.733333 230.4-251.733333 0-123.733333-93.866667-230.4-217.6-251.733333l-89.6-89.6h38.4c196.266667 0 354.133333 153.6 354.133333 341.333333 0 128-76.8 243.2-183.466666 298.666667v85.333333l-34.133334-29.866667z m-93.866666-17.066666c-12.8 0-29.866667 4.266667-46.933334 4.266666-196.266667 0-354.133333-153.6-354.133333-341.333333 0-128 76.8-243.2 183.466667-298.666667V128l55.466666 55.466667 85.333334 85.333333c-132.266667 12.8-234.666667 123.733333-234.666667 256 0 128 98.133333 234.666667 226.133333 251.733333l85.333334 85.333334z" fill="currentColor" p-id="7923"></path></svg> 加载中...</div>';


let currentAbortController = null; // 用于取消前一个请求

async function loadDocument(docName) {
    if (!docName) return;

    // 如果已经有正在进行的请求，取消它
    if (currentAbortController) {
        currentAbortController.abort();
    }

    const markdownDiv = document.getElementById('markdownRenderer');
    const contentArea = document.getElementById('contentArea');

    // 开始淡出
    contentArea.classList.add('fade-out');

    // 创建新的 AbortController
    currentAbortController = new AbortController();
    const { signal } = currentAbortController;

    try {
        const response = await fetch(`?action=get&doc=${encodeURIComponent(docName)}`, { signal });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const html = await response.text();

        // 更新内容
        markdownDiv.innerHTML = html;
        addCopyButtonsToCodeBlocks();
        window.location.hash = docName;

        // 高亮当前文档项
        document.querySelectorAll('.doc-item').forEach(item => {
            const dataDoc = item.getAttribute('data-doc');
            item.classList.toggle('active', dataDoc === docName);
        });

        // 淡入新内容
        contentArea.classList.remove('fade-out');
    } catch (err) {
        // 如果是手动取消的请求，不显示错误
        if (err.name === 'AbortError') return;

        contentArea.classList.remove('fade-out');
        markdownDiv.innerHTML = `<div class="error">加载失败: ${err.message}</div>`;
    } finally {
        currentAbortController = null; // 请求完成或取消后清空
    }
}

            docList.addEventListener('click', (e) => {
                const target = e.target.closest('.doc-item');
                if (!target) return;
                const docName = target.getAttribute('data-doc');
                if (docName) loadDocument(docName);
            });

            function initFromLocalStorage() {
                const savedDark = localStorage.getItem('darkMode');
                setDarkMode(savedDark === 'dark');
                const savedCollapsed = localStorage.getItem('sidebarCollapsed');
                if (savedCollapsed === 'false') {
                    sidebar.classList.remove('collapsed');
                } else {
                    sidebar.classList.add('collapsed');
                }
                updateMenuIcon();
            }

            function loadInitialDoc() {
                const markdownDiv = document.getElementById('markdownRenderer');
                if (window.location.hash.length > 1) {
                    const hashName = decodeURIComponent(window.location.hash.substring(1));
                    const items = document.querySelectorAll('.doc-item');
                    const matched = Array.from(items).find(item => item.getAttribute('data-doc') === hashName);
                    if (matched) {
                        loadDocument(hashName);
                    } else {
                        markdownDiv.innerHTML = '<div class="welcome-message"><svg class="icon" style="width: 1em;height: 1em;vertical-align: middle;fill: currentColor;overflow: hidden;" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="6159"><path d="M469.333333 768c-166.4 0-298.666667-132.266667-298.666666-298.666667s132.266667-298.666667 298.666666-298.666666 298.666667 132.266667 298.666667 298.666666-132.266667 298.666667-298.666667 298.666667z m0-85.333333c119.466667 0 213.333333-93.866667 213.333334-213.333334s-93.866667-213.333333-213.333334-213.333333-213.333333 93.866667-213.333333 213.333333 93.866667 213.333333 213.333333 213.333334z m251.733334 0l119.466666 119.466666-59.733333 59.733334-119.466667-119.466667 59.733334-59.733333z" fill="currentColor" p-id="6160"></path></svg> 快去打开一份文档查看罢</div>';
                    }
                } else {
                    markdownDiv.innerHTML = '<div class="welcome-message"><svg class="icon" style="width: 1em;height: 1em;vertical-align: middle;fill: currentColor;overflow: hidden;" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="6159"><path d="M469.333333 768c-166.4 0-298.666667-132.266667-298.666666-298.666667s132.266667-298.666667 298.666666-298.666666 298.666667 132.266667 298.666667 298.666666-132.266667 298.666667-298.666667 298.666667z m0-85.333333c119.466667 0 213.333333-93.866667 213.333334-213.333334s-93.866667-213.333333-213.333334-213.333333-213.333333 93.866667-213.333333 213.333333 93.866667 213.333333 213.333333 213.333334z m251.733334 0l119.466666 119.466666-59.733333 59.733334-119.466667-119.466667 59.733334-59.733333z" fill="currentColor" p-id="6160"></path></svg> 快去打开一份文档查看罢</div>';
                }
            }

            window.addEventListener('hashchange', () => {
                const markdownDiv = document.getElementById('markdownRenderer');
                if (window.location.hash.length > 1) {
                    const docName = decodeURIComponent(window.location.hash.substring(1));
                    loadDocument(docName);
                } else {
                    markdownDiv.innerHTML = '<div class="welcome-message"><svg class="icon" style="width: 1em;height: 1em;vertical-align: middle;fill: currentColor;overflow: hidden;" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="6159"><path d="M469.333333 768c-166.4 0-298.666667-132.266667-298.666666-298.666667s132.266667-298.666667 298.666666-298.666666 298.666667 132.266667 298.666667 298.666666-132.266667 298.666667-298.666667 298.666667z m0-85.333333c119.466667 0 213.333333-93.866667 213.333334-213.333334s-93.866667-213.333333-213.333334-213.333333-213.333333 93.866667-213.333333 213.333333 93.866667 213.333333 213.333333 213.333334z m251.733334 0l119.466666 119.466666-59.733333 59.733334-119.466667-119.466667 59.733334-59.733333z" fill="currentColor" p-id="6160"></path></svg> 快去打开一份文档查看罢</div>';
                }
            });

            initFromLocalStorage();
            loadInitialDoc();
        })();
    </script>

    <?php if (!$parsedownAvailable): ?>
        <div style="position: fixed; bottom:20px; right:20px; background:#fff3cd; border-left:6px solid #ffc107; padding:12px 20px; border-radius:12px; box-shadow:0 8px 20px rgba(0,0,0,0.1); color:#856404; z-index:1000;">
            <strong>📎 提示：</strong> 未找到 Parsedown，正在使用纯文本回退。
            <a href="https://github.com/erusev/parsedown" target="_blank" style="color:#856404; font-weight:bold;">下载 Parsedown.php</a> 以获得完整渲染。
        </div>
    <?php endif; ?>
</body>
</html>