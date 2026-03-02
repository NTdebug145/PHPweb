<?php
ob_start(); // 启用输出缓冲，防止因BOM或空格导致的header错误
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NTC - wiki</title>
    <style>
        /* 原有样式保持不变，仅新增太阳/月亮的显示控制 */
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

        body.dark .icon-btn {
            color: #cbd5e1;
        }

        .icon-btn:hover {
            background-color: #f1f5f9;
        }

        body.dark .icon-btn:hover {
            background-color: #334155;
        }

        /* 控制太阳/月亮的显示 */
        .sun-svg { display: none; }
        .moon-svg { display: inline-block; }
        body.dark .sun-svg { display: inline-block; }
        body.dark .moon-svg { display: none; }

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
            transition: background-color 0.2s;
        }

        body.dark .content-area {
            background-color: #0f172a;
        }

        .markdown-body {
            max-width: 900px;
            margin: 0 auto;
            background-color: white;
            padding: 32px 40px;
            border-radius: 5px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.02);
            border: 1px solid #e2e8f0;
            transition: background-color 0.2s, border-color 0.2s;
            position: relative;
        }

        body.dark .markdown-body {
            background-color: #1e293b;
            border-color: #334155;
            box-shadow: 0 4px 16px rgba(0,0,0,0.3);
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
    </style>
</head>
<body>
    <?php
    // -------------------------------------------------------------------
    // 后端 PHP 逻辑（完全保留原时间记录功能）
    // -------------------------------------------------------------------
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

    /**
     * 更新 MdTimeData.json 文件
     * @param array $docs 当前扫描到的文档列表，每个元素包含 'name' 和 'mtime'
     */
    function updateMdTimeJson($docs) {
        $jsonFile = __DIR__ . '/MdTimeData.json';
        $oldData = [];
        if (file_exists($jsonFile)) {
            $content = file_get_contents($jsonFile);
            $oldData = json_decode($content, true);
            if (!is_array($oldData)) {
                $oldData = [];
            }
        }
        $newData = [];
        $now = time(); // 当前时间作为新文件的“创建时间”
        foreach ($docs as $doc) {
            $name = $doc['name'];
            $mtime = $doc['mtime']; // 文件系统修改时间
            if (isset($oldData[$name])) {
                // 已有记录：保留原有的创建时间，更新修改时间
                $ctime = $oldData[$name]['ctime'];
            } else {
                // 新文件：使用当前时间作为创建时间
                $ctime = $now;
            }
            $newData[$name] = [
                'ctime' => $ctime,
                'mtime' => $mtime
            ];
        }
        // 写入 JSON（抑制警告，避免权限问题导致页面中断）
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

        // 从 MdTimeData.json 读取创建时间和最后修改时间（若存在）
        $ctime = null;
        $mtime = null;
        $jsonFile = __DIR__ . '/MdTimeData.json';
        if (file_exists($jsonFile)) {
            $jsonData = json_decode(file_get_contents($jsonFile), true);
            if (isset($jsonData[$docParam])) {
                $ctime = $jsonData[$docParam]['ctime'];
                $mtime = $jsonData[$docParam]['mtime'];
            }
        }
        // 如果 JSON 中不存在，则回退到文件系统（并尝试更新 JSON，但一般不会发生）
        if ($ctime === null) {
            $ctime = filectime($realFile);
        }
        if ($mtime === null) {
            $mtime = filemtime($realFile);
        }

        // 格式化时间
        $timeStrCreate = formatFileTime($ctime);
        $timeStrMod   = formatFileTime($mtime);

        // 渲染文档内容
        if ($parsedownAvailable && $Parsedown) {
            $bodyHtml = $Parsedown->text($content);
        } else {
            $bodyHtml = simpleMarkdown($content);
        }

        // 页脚显示创建时间和最后修改时间
        $footerHtml = '<div class="doc-footer-time"><svg class="icon" style="width: 1em;height: 1em;vertical-align: middle;fill: currentColor;overflow: hidden;" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="4749"><path d="M914.181742 251.621027L672.174208 10.295205A34.085568 34.085568 0 0 0 645.587465 0.069535H134.303944a34.085568 34.085568 0 0 0-34.085569 34.085568v954.395906a34.085568 34.085568 0 0 0 34.085569 34.085568h755.336188a34.085568 34.085568 0 0 0 34.085569-34.085568V272.754079a34.085568 34.085568 0 0 0-9.543959-21.133052z m-92.712746 3.408557H666.720517V100.962816zM168.389512 954.465441V68.240671h430.159869v220.874481a34.085568 34.085568 0 0 0 34.085568 34.085568h222.919615V954.465441z" fill="currentColor" p-id="4750"></path><path d="M713.758601 545.438624H548.10274V379.782763a34.085568 34.085568 0 0 0-68.171136 0V545.438624H304.731784a34.085568 34.085568 0 0 0-34.085568 34.085568 33.403857 33.403857 0 0 0 4.771979 16.361073 34.085568 34.085568 0 0 0 31.358723 21.133052h170.427841v170.42784a34.085568 34.085568 0 1 0 68.171136 0V618.38174h170.42784a34.085568 34.085568 0 0 0 34.085568-34.085568 33.403857 33.403857 0 0 0-4.771979-16.361073A34.085568 34.085568 0 0 0 713.758601 545.438624z" fill="currentColor" p-id="4751"></path></svg> 创建时间：' . $timeStrCreate . '  <svg class="icon" style="width: 1em;height: 1em;vertical-align: middle;fill: currentColor;overflow: hidden;" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="5477"><path d="M775.84 392.768l-155.2-172.352L160.768 643.264l-38.368 187.936 190.56-12.832zM929.952 229.952l-131.2-150.944-0.288-0.32a16 16 0 0 0-22.592-0.96l-131.168 120.576 155.168 172.352 128.832-118.464a15.936 15.936 0 0 0 1.248-22.24zM96 896h832v64H96z" p-id="5478"></path></svg> 最后编辑：' . $timeStrMod . '</div>';
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
                    'mtime'   => $mtime,          // 保留修改时间用于排序和 JSON
                    'file'    => $basename . '.md'
                ];
            }
        }
        // 按最后修改时间倒序排序
        usort($docs, function($a, $b) {
            return $b['mtime'] - $a['mtime'];
        });

        // 更新 MdTimeData.json
        updateMdTimeJson($docs);
    }
    ?>

    <div class="app-container">
        <div class="sidebar collapsed" id="sidebar">
            <div class="top-icons">
                <span class="icon-btn" id="menuToggle" title="展开/折叠菜单">☰</span>
                <!-- 暗色模式切换按钮：内嵌两个SVG，由CSS控制显示 -->
                <span class="icon-btn" id="darkModeToggle" title="暗色模式">
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
                </span>
                <span class="icon-btn" id="homeBtn" title="返回首页"><svg class="icon" style="width: 1em;height: 1em;vertical-align: middle;fill: currentColor;overflow: hidden;" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="2891"><path d="M908.266667 515.786667a5.333333 5.333333 0 0 0 0-7.52l-348.8-348.8-49.066667-49.066667a5.333333 5.333333 0 0 0-7.466667 0L105.066667 508.266667a5.333333 5.333333 0 0 0 0 7.52l45.28 45.28a5.333333 5.333333 0 0 0 7.52 0l1.76-1.813334a5.333333 5.333333 0 0 1 9.066666 3.786667V912a5.333333 5.333333 0 0 0 5.333334 5.333333h665.28a5.333333 5.333333 0 0 0 5.333333-5.333333v-349.013333a5.333333 5.333333 0 0 1 9.066667-3.733334l1.76 1.813334a5.333333 5.333333 0 0 0 7.52 0zM764.64 842.666667H248.693333a5.333333 5.333333 0 0 1-5.333333-5.333334V477.706667a5.333333 5.333333 0 0 1 1.6-3.733334L502.933333 216a5.333333 5.333333 0 0 1 7.466667 0l257.973333 257.973333a5.333333 5.333333 0 0 1 1.6 3.733334V837.333333a5.333333 5.333333 0 0 1-5.333333 5.333334zM405.333333 863.36h202.666667v-222.933333a5.6 5.6 0 0 0-5.333333-5.866667H410.666667a5.6 5.6 0 0 0-5.333334 5.866667z" p-id="2892"></path></svg></span>
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

    <script>
        (function() {
            const sidebar = document.getElementById('sidebar');
            const menuToggle = document.getElementById('menuToggle');
            const darkToggle = document.getElementById('darkModeToggle');
            const homeBtn = document.getElementById('homeBtn');
            const docList = document.getElementById('docList');
            const markdownDiv = document.getElementById('markdownRenderer');

            function updateMenuIcon() {
                const isCollapsed = sidebar.classList.contains('collapsed');
                menuToggle.textContent = isCollapsed ? '☰' : '✕';
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
                // 不再手动设置按钮文本，因为SVG通过CSS自动切换
            }

            function toggleDarkMode() {
                setDarkMode(!document.body.classList.contains('dark'));
            }

            darkToggle.addEventListener('click', toggleDarkMode);

            homeBtn.addEventListener('click', () => {
                window.location.href = '/';
            });

// 为所有代码块添加复制按钮
function addCopyButtonsToCodeBlocks() {
    const pres = document.querySelectorAll('.markdown-body pre');
    pres.forEach(pre => {
        if (pre.querySelector('.copy-code-btn')) return;
        const codeEl = pre.querySelector('code');
        const codeText = codeEl ? codeEl.innerText : pre.innerText;
        const btn = document.createElement('button');
        btn.className = 'copy-code-btn';
        btn.setAttribute('title', '复制代码');

        // 创建复制图标（📋 的 SVG）
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
                // 保存原始内容（仅保存子节点，因为按钮可能包含多个元素）
                const originalChildren = Array.from(btn.childNodes);
                // 清空按钮
                btn.innerHTML = '';
                // 创建成功图标（✅ 的 SVG）
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
                    // 恢复原始内容
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

            async function loadDocument(docName) {
                if (!docName) return;
                markdownDiv.innerHTML = '<div class="loader"><svg class="icon" style="width: 1em;height: 1em;vertical-align: middle;fill: currentColor;overflow: hidden;" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="7922"><path d="M674.133333 878.933333l-98.133333-102.4c128-17.066667 230.4-123.733333 230.4-251.733333 0-123.733333-93.866667-230.4-217.6-251.733333l-89.6-89.6h38.4c196.266667 0 354.133333 153.6 354.133333 341.333333 0 128-76.8 243.2-183.466666 298.666667v85.333333l-34.133334-29.866667z m-93.866666-17.066666c-12.8 0-29.866667 4.266667-46.933334 4.266666-196.266667 0-354.133333-153.6-354.133333-341.333333 0-128 76.8-243.2 183.466667-298.666667V128l55.466666 55.466667 85.333334 85.333333c-132.266667 12.8-234.666667 123.733333-234.666667 256 0 128 98.133333 234.666667 226.133333 251.733333l85.333334 85.333334z" fill="currentColor" p-id="7923"></path></svg> 加载中...</div>';
                try {
                    const response = await fetch(`?action=get&doc=${encodeURIComponent(docName)}`);
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }
                    const html = await response.text();
                    markdownDiv.innerHTML = html;
                    addCopyButtonsToCodeBlocks();
                    window.location.hash = docName;
                    document.querySelectorAll('.doc-item').forEach(item => {
                        const dataDoc = item.getAttribute('data-doc');
                        item.classList.toggle('active', dataDoc === docName);
                    });
                } catch (e) {
                    markdownDiv.innerHTML = `<div class="error" style="color:#b91c1c; padding: 2rem;"><svg class="icon" style="width: 1em;height: 1em;vertical-align: middle;fill: currentColor;overflow: hidden;" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="7302"><path d="M512 981.333333C252.8 981.333333 42.666667 771.2 42.666667 512S252.8 42.666667 512 42.666667s469.333333 210.133333 469.333333 469.333333-210.133333 469.333333-469.333333 469.333333z m44.245333-469.333333l159.914667-159.914667a31.274667 31.274667 0 1 0-44.245333-44.245333L512 467.754667 352.085333 307.84a31.274667 31.274667 0 1 0-44.245333 44.245333L467.754667 512l-159.914667 159.914667a31.274667 31.274667 0 1 0 44.245333 44.245333L512 556.245333l159.914667 159.914667a31.274667 31.274667 0 1 0 44.245333-44.245333L556.245333 512z" fill="#F5222D" p-id="7303"></path></svg> 加载失败: ${e.message}</div>`;
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