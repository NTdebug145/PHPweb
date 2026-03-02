<?php
ob_start(); // 启用输出缓冲

// -------------------------------------------------------------------
// 后端处理逻辑（文件创建）- 优化版
// -------------------------------------------------------------------
define('DOCS_DIR', __DIR__ . '/docs/'); // 使用绝对路径

$error = null;
$success = null;
$postedFilename = '';
$postedContent = '';

// 如果 docs 目录不存在，尝试创建
if (!is_dir(DOCS_DIR)) {
    if (!mkdir(DOCS_DIR, 0755, true)) {
        $error = '无法创建 docs/ 目录，请手动创建并赋予写入权限。';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    // 获取表单数据
    $postedFilename = trim($_POST['filename'] ?? '');
    $postedContent = $_POST['content'] ?? '';
    $overwrite = isset($_POST['overwrite']) && $_POST['overwrite'] === 'yes';

    // 1. 基本非空检查
    if ($postedFilename === '') {
        $error = '文件名不能为空。';
    } elseif ($postedContent === '') {
        $error = '内容不能为空。';
    } else {
        // 2. 安全格式检查（与 index.php 保持一致：字母、数字、下划线、横线、点，支持Unicode）
        if (!preg_match('/^[\p{L}\p{N}_\-.]+$/u', $postedFilename)) {
            $error = '文件名只能包含字母、数字、下划线(_)、横线(-)和点(.)。';
        } elseif ($postedFilename === '.' || $postedFilename === '..' || strpos($postedFilename, '.') === 0) {
            $error = '文件名不能以点开头，也不能为"."或".."。';
        } elseif (strlen($postedFilename) > 100) {
            $error = '文件名过长，请限制在100字符以内。';
        } else {
            $filePath = DOCS_DIR . $postedFilename . '.md';

            // 3. 检查目录是否可写
            if (!is_writable(DOCS_DIR)) {
                $error = '文档目录不可写，请检查权限。';
            } else {
                // 4. 检查文件是否已存在
                if (file_exists($filePath) && !$overwrite) {
                    $error = '文件已存在，如需覆盖请勾选“覆盖现有文件”。';
                } else {
                    // 5. 写入文件
                    $result = file_put_contents($filePath, $postedContent);
                    if ($result === false) {
                        $error = '文件写入失败，请检查目录权限。';
                    } else {
                        $success = '文档创建成功！';
                        // 可选：清空表单已防止重复提交（这里保留内容以便继续编辑）
                    }
                }
            }
        }
    }
}

// 以下为HTML输出
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>新建文章 - NTC wiki</title>
    <style>
        /* 样式与 index.php 保持一致，并增加表单专用样式 */
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
            border-radius: 20px;
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

        /* 表单样式 */
        .form-group {
            margin-bottom: 24px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 1rem;
            color: #1e293b;
        }

        body.dark .form-group label {
            color: #e2e8f0;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            font-size: 1rem;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            background-color: #ffffff;
            transition: border 0.2s, box-shadow 0.2s;
            font-family: inherit;
        }

        body.dark .form-control {
            background-color: #0f172a;
            border-color: #475569;
            color: #e2e8f0;
        }

        .form-control:focus {
            outline: none;
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.2);
        }

        textarea.form-control {
            min-height: 300px;
            resize: vertical;
            font-family: 'JetBrains Mono', 'Fira Code', monospace;
            line-height: 1.6;
        }

        .form-check {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 16px 0;
        }

        .form-check input {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .form-check label {
            cursor: pointer;
            color: #475569;
        }

        body.dark .form-check label {
            color: #cbd5e1;
        }

        .btn {
            background-color: #4f46e5;
            color: white;
            border: none;
            padding: 12px 28px;
            border-radius: 40px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s, transform 0.1s;
            box-shadow: 0 4px 8px rgba(79, 70, 229, 0.3);
            border: 1px solid rgba(255,255,255,0.1);
            text-decoration: none;
            display: inline-block;
        }

        .btn:hover {
            background-color: #6366f1;
        }

        .btn:active {
            transform: scale(0.97);
        }

        body.dark .btn {
            background-color: #818cf8;
            color: #0f172a;
            box-shadow: 0 4px 12px rgba(129, 140, 248, 0.3);
        }

        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-weight: 500;
        }

        .alert-error {
            background-color: #fee2e2;
            border-left: 6px solid #ef4444;
            color: #b91c1c;
        }

        body.dark .alert-error {
            background-color: #2d1a1a;
            border-left-color: #f87171;
            color: #fecaca;
        }

        .alert-success {
            background-color: #dcfce7;
            border-left: 6px solid #22c55e;
            color: #166534;
        }

        body.dark .alert-success {
            background-color: #1a2e1a;
            border-left-color: #4ade80;
            color: #bbf7d0;
        }

        .view-link {
            margin-left: 16px;
            font-weight: 600;
            text-decoration: underline;
            color: inherit;
        }

        .filename-hint {
            font-size: 0.85rem;
            color: #64748b;
            margin-top: 6px;
        }
        body.dark .filename-hint {
            color: #94a3b8;
        }

        .empty-docs {
            padding: 32px 16px;
            text-align: center;
            color: #94a3b8;
            font-size: 0.9rem;
        }

        .btn-group {
            display: flex;
            gap: 16px;
            align-items: center;
            flex-wrap: wrap;
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- 侧边栏：与 index.php 风格一致，但导航项静态 -->
        <div class="sidebar collapsed" id="sidebar">
            <div class="top-icons">
                <span class="icon-btn" id="menuToggle" title="展开/折叠菜单">☰</span>
                <span class="icon-btn" id="darkModeToggle" title="暗色模式">🌙</span>
                <span class="icon-btn" id="homeBtn" title="返回首页">🏠</span>
            </div>
            <ul class="doc-list" id="docList">
                <li class="doc-item" onclick="location.href='index.php';">📄 所有文档</li>
                <li class="doc-item active" style="cursor: default;">➕ 新建文档</li>
                <li class="doc-item" onclick="location.href='index.php#about';">⭐ 关于wiki</li>
            </ul>
        </div>

        <main class="content-area" id="contentArea">
            <div class="markdown-body" id="markdownRenderer">
                <h1 style="margin-top: 0.5rem;">📝 新建Markdown文档</h1>
                <p style="margin-bottom: 2rem; color: #64748b;">填写以下信息，文档将保存到 <code>docs/</code> 目录。</p>

                <?php if ($error): ?>
                    <div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        ✅ <?= htmlspecialchars($success) ?>
                        <?php if (isset($postedFilename) && $postedFilename !== ''): ?>
                            <a href="index.php#<?= urlencode($postedFilename) ?>" class="view-link" target="_blank">👉 立即查看</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="new.php">
                    <div class="form-group">
                        <label for="filename">📛 文件名 (不含 .md 扩展名)</label>
                        <input type="text" class="form-control" id="filename" name="filename"
                               value="<?= htmlspecialchars($postedFilename) ?>" required
                               placeholder="例如：my-article, 使用字母、数字、下划线、横线、点">
                        <div class="filename-hint">只能包含字母、数字、下划线(_)、横线(-)和点(.)，不能以点开头。</div>
                    </div>

                    <div class="form-group">
                        <label for="content">📄 Markdown 内容</label>
                        <textarea class="form-control" id="content" name="content" required
                                  placeholder="# 标题&#10;&#10;在此输入 Markdown 内容..."><?= htmlspecialchars($postedContent) ?></textarea>
                    </div>

                    <div class="form-check">
                        <input type="checkbox" id="overwrite" name="overwrite" value="yes" <?= $overwrite ?? false ? 'checked' : '' ?>>
                        <label for="overwrite">🔁 覆盖现有文件 (如果文件名已存在)</label>
                    </div>

                    <div class="btn-group">
                        <button type="submit" class="btn">💾 保存文档</button>
                        <a href="index.php" class="btn" style="background-color: #64748b; box-shadow: none;">🏠 返回首页</a>
                    </div>
                </form>

                <hr style="margin: 40px 0 20px; border: none; border-top: 1px solid #e2e8f0;">
                <p style="color: #64748b; font-size:0.9rem;">
                    ⚡ 文档创建后会立即出现在 <strong>所有文档</strong> 列表中，并按修改时间排序。
                </p>
            </div>
        </main>
    </div>

    <script>
        (function() {
            const sidebar = document.getElementById('sidebar');
            const menuToggle = document.getElementById('menuToggle');
            const darkToggle = document.getElementById('darkModeToggle');
            const homeBtn = document.getElementById('homeBtn');

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
                    darkToggle.textContent = '☀️';
                } else {
                    document.body.classList.remove('dark');
                    darkToggle.textContent = '🌙';
                }
                localStorage.setItem('darkMode', enable ? 'dark' : 'light');
            }

            function toggleDarkMode() {
                setDarkMode(!document.body.classList.contains('dark'));
            }

            darkToggle.addEventListener('click', toggleDarkMode);

            homeBtn.addEventListener('click', () => {
                window.location.href = 'index.php';
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

            initFromLocalStorage();
        })();
    </script>

    <?php
    // 检查 Parsedown 存在与否的提示（不影响新建功能）
    $parsedownAvailable = false;
    if (file_exists(__DIR__ . '/Parsedown.php')) {
        require_once __DIR__ . '/Parsedown.php';
        if (class_exists('Parsedown')) {
            $parsedownAvailable = true;
        }
    }
    if (!$parsedownAvailable): ?>
        <div style="position: fixed; bottom:20px; right:20px; background:#fff3cd; border-left:6px solid #ffc107; padding:12px 20px; border-radius:12px; box-shadow:0 8px 20px rgba(0,0,0,0.1); color:#856404; z-index:1000;">
            <strong>📎 提示：</strong> 未找到 Parsedown，但不影响新建文档。
            <a href="https://github.com/erusev/parsedown" target="_blank" style="color:#856404; font-weight:bold;">下载 Parsedown.php</a> 以获得完整渲染。
        </div>
    <?php endif; ?>
</body>
</html>