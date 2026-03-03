<?php
/**
 * 分块上传 + 唯一下载链接 + MD5搜索 (单文件版)
 * 
 * 功能：
 * - 分块上传压缩包（自定义块大小，断点续传）
 * - 上传完成后生成唯一编号，并计算文件MD5
 * - 通过唯一编号下载文件
 * - 通过MD5值搜索文件，列出所有匹配的下载链接
 * 
 * 文件存储：
 * - 临时块：/temp/
 * - 最终文件：/upF/ (以编号命名)
 * - 映射文件：/upF/files.json
 */

// ---------- 配置 ----------
define('MAX_FILE_SIZE', 500 * 1024 * 1024);      // 最大文件 500MB
define('MIN_CHUNK_SIZE', 1 * 1024 * 1024);       // 最小块 1MB
define('MAX_CHUNK_SIZE', 50 * 1024 * 1024);      // 最大块 50MB
define('ALLOWED_EXTENSIONS', ['zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'tgz']);

$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : __DIR__;
define('UPLOAD_DIR', rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . 'upF' . DIRECTORY_SEPARATOR);
define('TEMP_DIR', rtrim($baseDir, '/upF\\') . DIRECTORY_SEPARATOR . '/upF/temp' . DIRECTORY_SEPARATOR);
define('FILES_MAP', UPLOAD_DIR . 'files.json');

// ---------- 初始化目录 ----------
foreach ([UPLOAD_DIR, TEMP_DIR] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        die("无法创建目录: " . htmlspecialchars($dir));
    }
    if (!is_writable($dir)) {
        die("目录不可写: " . htmlspecialchars($dir));
    }
}

// 初始化映射文件
if (!file_exists(FILES_MAP)) {
    file_put_contents(FILES_MAP, json_encode([]));
}

// ---------- 辅助函数 ----------
function sanitizeFilename($filename) {
    $filename = basename($filename);
    return preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
}

function getFileInfoPath($fileId) {
    return TEMP_DIR . $fileId . '_info.json';
}

function getChunkFilePath($fileId, $chunkIndex) {
    return TEMP_DIR . $fileId . '_chunk_' . $chunkIndex . '.part';
}

function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// 生成唯一ID（检查冲突）
function generateUniqueId($length = 16) {
    do {
        $id = bin2hex(random_bytes($length));
        $map = json_decode(file_get_contents(FILES_MAP), true);
        $exists = isset($map[$id]);
    } while ($exists);
    return $id;
}

// 记录文件映射（增加MD5字段）
function saveFileMapping($id, $originalName, $filePath, $size, $md5) {
    $map = json_decode(file_get_contents(FILES_MAP), true);
    $map[$id] = [
        'original' => $originalName,
        'path' => $filePath,
        'size' => $size,
        'md5' => $md5,
        'time' => time()
    ];
    file_put_contents(FILES_MAP, json_encode($map, JSON_PRETTY_PRINT), LOCK_EX);
}

// ---------- 路由处理 ----------
$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

// ========== 下载处理 ==========
if ($action === 'download') {
    $id = isset($_GET['id']) ? preg_replace('/[^a-f0-9]/', '', $_GET['id']) : '';
    if (strlen($id) !== 32) {
        http_response_code(400);
        die('无效的文件编号');
    }
    $map = json_decode(file_get_contents(FILES_MAP), true);
    if (!isset($map[$id])) {
        http_response_code(404);
        die('文件不存在');
    }
    $record = $map[$id];
    $filePath = UPLOAD_DIR . $record['path'];
    if (!file_exists($filePath)) {
        http_response_code(404);
        die('物理文件不存在');
    }
    // 发送下载头
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . rawurlencode($record['original']) . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    readfile($filePath);
    exit;
}

// ========== 搜索处理（支持POST/GET） ==========
if ($action === 'search' || isset($_POST['md5']) || isset($_GET['md5'])) {
    $md5 = isset($_POST['md5']) ? $_POST['md5'] : (isset($_GET['md5']) ? $_GET['md5'] : '');
    $md5 = preg_replace('/[^a-f0-9]/', '', strtolower($md5));
    $results = [];
    if (strlen($md5) === 32) {
        $map = json_decode(file_get_contents(FILES_MAP), true);
        foreach ($map as $id => $info) {
            if ($info['md5'] === $md5) {
                $results[] = [
                    'id' => $id,
                    'original' => $info['original'],
                    'size' => $info['size'],
                    'time' => date('Y-m-d H:i:s', $info['time']),
                    'url' => '?action=download&id=' . $id
                ];
            }
        }
    }
    // 如果是AJAX请求则返回JSON，否则显示HTML结果页面
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        jsonResponse($results);
    } else {
        // 显示搜索页面（嵌入到主页面中）
        // 将在HTML部分处理
    }
}

// ========== 上传API路由 ==========
if ($action && in_array($action, ['checkStatus', 'uploadChunk', 'merge'])) {
    // 所有上传API要求提供 fileId
    $fileId = isset($_REQUEST['fileId']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_REQUEST['fileId']) : '';
    if (!$fileId) {
        jsonResponse(['error' => '缺少 fileId 参数'], 400);
    }

    // 1. 查询已上传块列表
    if ($action === 'checkStatus') {
        $uploadedChunks = [];
        $infoPath = getFileInfoPath($fileId);
        if (file_exists($infoPath)) {
            $info = json_decode(file_get_contents($infoPath), true);
            $uploadedChunks = $info['uploadedChunks'] ?? [];
        } else {
            $files = glob(TEMP_DIR . $fileId . '_chunk_*.part');
            foreach ($files as $f) {
                if (preg_match('/_chunk_(\d+)\.part$/', $f, $m)) {
                    $uploadedChunks[] = (int)$m[1];
                }
            }
            sort($uploadedChunks);
        }
        jsonResponse(['uploadedChunks' => $uploadedChunks]);
    }

    // 2. 上传分块
    if ($action === 'uploadChunk') {
        $totalChunks = isset($_POST['totalChunks']) ? intval($_POST['totalChunks']) : 0;
        $chunkIndex  = isset($_POST['chunkIndex']) ? intval($_POST['chunkIndex']) : -1;
        $fileName    = isset($_POST['fileName']) ? $_POST['fileName'] : '';
        $fileSize    = isset($_POST['fileSize']) ? intval($_POST['fileSize']) : 0;
        $chunkSize   = isset($_POST['chunkSize']) ? intval($_POST['chunkSize']) : MAX_CHUNK_SIZE;

        if ($totalChunks <= 0 || $chunkIndex < 0 || $chunkIndex >= $totalChunks) {
            jsonResponse(['error' => '无效的块索引或总块数'], 400);
        }
        if (!$fileName) {
            jsonResponse(['error' => '缺少文件名'], 400);
        }
        if ($fileSize > MAX_FILE_SIZE) {
            jsonResponse(['error' => '文件超过最大允许大小'], 400);
        }
        if ($chunkSize < MIN_CHUNK_SIZE || $chunkSize > MAX_CHUNK_SIZE) {
            jsonResponse(['error' => '块大小超出允许范围'], 400);
        }

        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_EXTENSIONS)) {
            jsonResponse(['error' => '不支持的文件类型，仅允许：' . implode(', ', ALLOWED_EXTENSIONS)], 400);
        }

        if (!isset($_FILES['chunkFile']) || $_FILES['chunkFile']['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(['error' => '分块文件上传失败'], 400);
        }
        if ($_FILES['chunkFile']['size'] > $chunkSize) {
            jsonResponse(['error' => '上传的块大小超过声明值'], 400);
        }

        $chunkTmp = $_FILES['chunkFile']['tmp_name'];
        $chunkPath = getChunkFilePath($fileId, $chunkIndex);

        if (!move_uploaded_file($chunkTmp, $chunkPath)) {
            jsonResponse(['error' => '保存分块失败'], 500);
        }

        $infoPath = getFileInfoPath($fileId);
        $info = [];
        if (file_exists($infoPath)) {
            $info = json_decode(file_get_contents($infoPath), true);
        }
        $info['uploadedChunks'] = array_unique(array_merge($info['uploadedChunks'] ?? [], [$chunkIndex]));
        sort($info['uploadedChunks']);
        $info['totalChunks'] = $totalChunks;
        $info['fileName'] = $fileName;
        $info['fileSize'] = $fileSize;
        $info['chunkSize'] = $chunkSize;
        file_put_contents($infoPath, json_encode($info), LOCK_EX);

        jsonResponse(['success' => true, 'chunkIndex' => $chunkIndex]);
    }

    // 3. 合并分块
    if ($action === 'merge') {
        $fileName = isset($_POST['fileName']) ? $_POST['fileName'] : '';
        $totalChunks = isset($_POST['totalChunks']) ? intval($_POST['totalChunks']) : 0;

        if (!$fileName || $totalChunks <= 0) {
            jsonResponse(['error' => '参数不足'], 400);
        }

        $infoPath = getFileInfoPath($fileId);
        if (!file_exists($infoPath)) {
            jsonResponse(['error' => '未找到上传信息，可能尚未开始上传'], 404);
        }

        $info = json_decode(file_get_contents($infoPath), true);
        $uploaded = $info['uploadedChunks'] ?? [];
        if (count($uploaded) != $totalChunks || min($uploaded) != 0 || max($uploaded) != $totalChunks - 1) {
            jsonResponse(['error' => '分块不完整，无法合并'], 400);
        }

        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $uniqueId = generateUniqueId();
        $finalName = $uniqueId . '.' . $ext;
        $finalPath = UPLOAD_DIR . $finalName;

        $fp = fopen($finalPath, 'wb');
        if (!$fp) {
            jsonResponse(['error' => '无法创建最终文件'], 500);
        }

        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkPath = getChunkFilePath($fileId, $i);
            if (!file_exists($chunkPath)) {
                fclose($fp);
                unlink($finalPath);
                jsonResponse(['error' => "缺少分块 $i"], 400);
            }
            fwrite($fp, file_get_contents($chunkPath));
        }
        fclose($fp);

        // 计算MD5
        $fileMd5 = md5_file($finalPath);

        // 可选的MIME检查
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $finalPath);
            finfo_close($finfo);
            $allowedMime = [
                'application/zip', 'application/x-zip-compressed',
                'application/rar', 'application/x-rar-compressed',
                'application/x-7z-compressed', 'application/x-tar',
                'application/gzip', 'application/x-gzip', 'application/x-bzip2',
                'application/x-xz', 'application/x-tgz'
            ];
            if (!in_array($mime, $allowedMime)) {
                unlink($finalPath);
                jsonResponse(['error' => '合并后的文件不是有效的压缩包'], 400);
            }
        }

        saveFileMapping($uniqueId, $fileName, $finalName, $info['fileSize'], $fileMd5);

        // 清理临时文件
        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkPath = getChunkFilePath($fileId, $i);
            if (file_exists($chunkPath)) unlink($chunkPath);
        }
        if (file_exists($infoPath)) unlink($infoPath);

        $downloadLink = '?action=download&id=' . $uniqueId;
        jsonResponse([
            'success' => true,
            'fileId' => $uniqueId,
            'downloadUrl' => $downloadLink,
            'md5' => $fileMd5
        ]);
    }
}

// ========== 默认显示上传页面 + 搜索框 ==========
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NTC - upF</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            max-width: 800px;
            margin: 30px auto;
            padding: 20px;
            background: #f5f7fa;
            border-radius: 16px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        h2 {
            color: #2c3e50;
            border-bottom: 3px solid #3498db;
            padding-bottom: 8px;
        }
        .card {
            background: white;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            font-weight: 600;
            color: #34495e;
            margin-bottom: 5px;
        }
        input[type="file"], input[type="number"], input[type="text"] {
            width: 100%;
            padding: 10px;
            border: 2px solid #b0bec5;
            border-radius: 6px;
            font-size: 14px;
        }
        input[type="text"] {
            max-width: 300px;
        }
        button {
            background: #3498db;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 30px;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
            margin-right: 8px;
            transition: background 0.2s;
        }
        button:disabled {
            background: #95a5a6;
            cursor: not-allowed;
        }
        button.secondary {
            background: #2ecc71;
        }
        button.secondary:hover { background: #27ae60; }
        .progress-container {
            margin: 20px 0;
            background: #ecf0f1;
            border-radius: 30px;
            height: 25px;
            overflow: hidden;
        }
        .progress-bar {
            height: 100%;
            background: #3498db;
            width: 0%;
            transition: width 0.2s;
        }
        .progress-text {
            text-align: center;
            font-size: 14px;
            color: #2c3e50;
        }
        .alert {
            padding: 12px 15px;
            border-radius: 8px;
            margin: 15px 0;
        }
        .alert.info { background: #d1ecf1; color: #0c5460; border-left: 5px solid #17a2b8; }
        .alert.success { background: #d4edda; color: #155724; border-left: 5px solid #28a745; }
        .alert.error { background: #f8d7da; color: #721c24; border-left: 5px solid #dc3545; }
        .file-info {
            background: #ecf0f1;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
            white-space: pre-line;
        }
        .download-link {
            background: #e8f5e9;
            padding: 15px;
            border-radius: 8px;
            border-left: 5px solid #2ecc71;
            word-break: break-all;
        }
        .search-result {
            margin-top: 15px;
            border-top: 1px solid #ddd;
            padding-top: 15px;
        }
        .result-item {
            background: #f8f9fa;
            padding: 10px 15px;
            border-radius: 6px;
            margin-bottom: 8px;
            border-left: 3px solid #3498db;
        }
        .result-item a {
            color: #2980b9;
            text-decoration: none;
            font-weight: 500;
        }
        .result-item a:hover { text-decoration: underline; }
        .small-note {
            color: #7f8c8d;
            font-size: 0.9em;
            margin-top: 15px;
        }
    </style>
</head>
<body>
    <h2>压缩包上传</h2>

    <!-- 搜索区域 -->
    <div class="card">
        <h3>通过 MD5 搜索文件</h3>
        <form method="post" action="" onsubmit="searchMD5(event)">
            <div style="display: flex; gap: 10px; align-items: flex-end;">
                <div style="flex:1;">
                    <label for="md5_input">输入文件 MD5 值：</label>
                    <input type="text" id="md5_input" name="md5" placeholder="32位十六进制" pattern="[a-fA-F0-9]{32}" title="请输入32位十六进制MD5">
                </div>
                <button type="submit" class="secondary">搜索</button>
            </div>
        </form>
        <div id="searchResults" class="search-result" style="display: none;"></div>
    </div>

    <!-- 上传区域 -->
    <div class="card">
        <h3>上传新文件 (仅压缩包)</h3>
        <div class="form-group">
            <label for="fileInput">选择文件：</label>
            <input type="file" id="fileInput" accept=".zip,.rar,.7z,.tar,.gz,.bz2,.xz,.tgz">
        </div>
        <div class="form-group">
            <label for="chunkSize">块大小 (MB)：</label>
            <input type="number" id="chunkSize" min="1" max="50" value="2" step="0.1">
            <span class="small-note">(1MB ~ 50MB)</span>
        </div>
        <div>
            <button id="uploadBtn" disabled>开始上传</button>
            <button id="pauseBtn" disabled>暂停</button>
            <button id="resumeBtn" disabled>恢复</button>
        </div>

        <div class="progress-container" id="progressContainer" style="display: none;">
            <div class="progress-bar" id="progressBar"></div>
        </div>
        <div class="progress-text" id="progressText"></div>
        <div id="messageArea" class="alert" style="display: none;"></div>
        <div class="file-info" id="fileInfo" style="display: none;"></div>
        <div id="downloadArea" class="download-link" style="display: none;"></div>
    </div>

    <div class="small-note">
        * 下载链接格式：?action=download&id=编号<br>
        * 每个文件的上传时间、原始文件名和 MD5 都会被记录，可通过上方搜索框查找。<br>
        * 因为不可抗力因素导致上传可能失败所以这个页面的功能可以忽略它
    </div>

    <script>
        // ---------- 上传逻辑 (基于之前版本，略作调整) ----------
        (function() {
            const fileInput = document.getElementById('fileInput');
            const chunkSizeInput = document.getElementById('chunkSize');
            const uploadBtn = document.getElementById('uploadBtn');
            const pauseBtn = document.getElementById('pauseBtn');
            const resumeBtn = document.getElementById('resumeBtn');
            const progressContainer = document.getElementById('progressContainer');
            const progressBar = document.getElementById('progressBar');
            const progressText = document.getElementById('progressText');
            const messageArea = document.getElementById('messageArea');
            const fileInfoDiv = document.getElementById('fileInfo');
            const downloadArea = document.getElementById('downloadArea');

            let file = null;
            let fileId = '';
            let totalChunks = 0;
            let chunkSize = 2 * 1024 * 1024;
            let uploadedChunks = [];
            let paused = false;
            let uploadActive = false;
            let abortController = null;

            function showMessage(msg, type = 'info') {
                messageArea.style.display = 'block';
                messageArea.className = `alert ${type}`;
                messageArea.innerText = msg;
            }
            function clearMessage() { messageArea.style.display = 'none'; }

            function updateProgress() {
                if (totalChunks === 0) return;
                let percent = (uploadedChunks.length / totalChunks * 100).toFixed(2);
                progressBar.style.width = percent + '%';
                progressText.innerText = `已上传 ${uploadedChunks.length} / ${totalChunks} 块 (${percent}%)`;
            }

            function generateFileId(file) {
                return `${file.name}_${file.size}_${file.lastModified}`.replace(/[^a-zA-Z0-9_-]/g, '_');
            }

            async function checkUploadedChunks(fileId) {
                try {
                    const r = await fetch(`?action=checkStatus&fileId=${encodeURIComponent(fileId)}`);
                    const d = await r.json();
                    return d.uploadedChunks || [];
                } catch (e) { return []; }
            }

            async function uploadChunk(fileId, chunkIndex, totalChunks, fileName, fileSize, chunkBlob, chunkSizeBytes) {
                const formData = new FormData();
                formData.append('action', 'uploadChunk');
                formData.append('fileId', fileId);
                formData.append('chunkIndex', chunkIndex);
                formData.append('totalChunks', totalChunks);
                formData.append('fileName', fileName);
                formData.append('fileSize', fileSize);
                formData.append('chunkSize', chunkSizeBytes);
                formData.append('chunkFile', chunkBlob, `chunk_${chunkIndex}`);
                const response = await fetch('?', {
                    method: 'POST',
                    body: formData,
                    signal: abortController ? abortController.signal : undefined
                });
                if (!response.ok) {
                    const err = await response.json();
                    throw new Error(err.error || '上传失败');
                }
                return await response.json();
            }

            async function mergeFile(fileId, fileName, totalChunks) {
                const formData = new FormData();
                formData.append('action', 'merge');
                formData.append('fileId', fileId);
                formData.append('fileName', fileName);
                formData.append('totalChunks', totalChunks);
                const r = await fetch('?', { method: 'POST', body: formData });
                if (!r.ok) {
                    const err = await r.json();
                    throw new Error(err.error || '合并失败');
                }
                return await r.json();
            }

            async function startUpload(resume = false) {
                if (!file) { showMessage('请选择文件', 'error'); return; }
                let chunkSizeMB = parseFloat(chunkSizeInput.value);
                if (isNaN(chunkSizeMB) || chunkSizeMB < 1 || chunkSizeMB > 50) {
                    showMessage('块大小必须在1~50MB', 'error'); return;
                }
                chunkSize = Math.floor(chunkSizeMB * 1024 * 1024);
                fileId = generateFileId(file);
                totalChunks = Math.ceil(file.size / chunkSize);
                const fileName = file.name;
                const fileSize = file.size;

                showMessage('检查已上传块...', 'info');
                const serverChunks = await checkUploadedChunks(fileId);
                uploadedChunks = serverChunks.slice().sort((a,b)=>a-b);
                updateProgress();

                if (uploadedChunks.length === totalChunks) {
                    showMessage('所有块已存在，正在合并...', 'info');
                    try {
                        const result = await mergeFile(fileId, fileName, totalChunks);
                        showMessage('上传成功！', 'success');
                        fileInfoDiv.style.display = 'block';
                        fileInfoDiv.innerText = `原始文件名：${fileName}\n大小：${(fileSize/1024/1024).toFixed(2)} MB\nMD5：${result.md5}\n编号：${result.fileId}`;
                        downloadArea.style.display = 'block';
                        downloadArea.innerHTML = `下载链接：<a href="${result.downloadUrl}" target="_blank">${result.downloadUrl}</a> (右键另存)`;
                        resetUploadState();
                    } catch (e) { showMessage('合并失败：' + e.message, 'error'); }
                    return;
                }

                const missingChunks = [];
                for (let i = 0; i < totalChunks; i++) if (!uploadedChunks.includes(i)) missingChunks.push(i);

                uploadActive = true; paused = false;
                uploadBtn.disabled = true; pauseBtn.disabled = false; resumeBtn.disabled = true;
                progressContainer.style.display = 'block'; clearMessage();

                for (let idx of missingChunks) {
                    if (paused || !uploadActive) break;
                    const start = idx * chunkSize;
                    const end = Math.min(start + chunkSize, file.size);
                    const chunkBlob = file.slice(start, end);
                    try {
                        await uploadChunk(fileId, idx, totalChunks, fileName, fileSize, chunkBlob, chunkSize);
                        if (!uploadedChunks.includes(idx)) {
                            uploadedChunks.push(idx);
                            uploadedChunks.sort((a,b)=>a-b);
                        }
                        updateProgress();
                    } catch (e) {
                        if (e.name === 'AbortError') showMessage('上传已暂停', 'info');
                        else showMessage(`块 ${idx} 失败：${e.message}`, 'error');
                        paused = true; uploadActive = false;
                        pauseBtn.disabled = true; resumeBtn.disabled = false; uploadBtn.disabled = false;
                        return;
                    }
                }

                if (!paused && uploadActive && uploadedChunks.length === totalChunks) {
                    showMessage('所有块上传完成，合并中...', 'info');
                    try {
                        const result = await mergeFile(fileId, fileName, totalChunks);
                        showMessage('上传成功！', 'success');
                        fileInfoDiv.style.display = 'block';
                        fileInfoDiv.innerText = `原始文件名：${fileName}\n大小：${(fileSize/1024/1024).toFixed(2)} MB\nMD5：${result.md5}\n编号：${result.fileId}`;
                        downloadArea.style.display = 'block';
                        downloadArea.innerHTML = `下载链接：<a href="${result.downloadUrl}" target="_blank">${result.downloadUrl}</a> (右键另存)`;
                        resetUploadState();
                    } catch (e) { showMessage('合并失败：' + e.message, 'error'); }
                } else if (!paused && uploadActive && uploadedChunks.length < totalChunks) {
                    showMessage('上传未完成，可稍后恢复', 'warning');
                }
                if (paused) {
                    pauseBtn.disabled = true; resumeBtn.disabled = false; uploadBtn.disabled = false;
                } else resetUploadState();
            }

            function resetUploadState() {
                uploadActive = false; paused = false;
                uploadBtn.disabled = true; pauseBtn.disabled = true; resumeBtn.disabled = true;
            }
            function pauseUpload() {
                if (uploadActive) {
                    paused = true; uploadActive = false;
                    if (abortController) abortController.abort();
                    pauseBtn.disabled = true; resumeBtn.disabled = false; uploadBtn.disabled = false;
                    showMessage('上传已暂停', 'info');
                }
            }
            function resumeUpload() {
                if (!file) return;
                paused = false; uploadActive = true;
                pauseBtn.disabled = false; resumeBtn.disabled = true; uploadBtn.disabled = true;
                clearMessage();
                startUpload(true);
            }

            fileInput.addEventListener('change', function(e) {
                const f = e.target.files[0];
                if (!f) { uploadBtn.disabled = true; return; }
                const ext = f.name.split('.').pop().toLowerCase();
                if (!['zip','rar','7z','tar','gz','bz2','xz','tgz'].includes(ext)) {
                    showMessage('不支持的文件类型', 'error');
                    fileInput.value = ''; uploadBtn.disabled = true; return;
                }
                if (f.size > 500 * 1024 * 1024) {
                    showMessage('文件超过500MB', 'error');
                    fileInput.value = ''; uploadBtn.disabled = true; return;
                }
                file = f;
                uploadBtn.disabled = false;
                clearMessage();
                downloadArea.style.display = 'none';
                fileInfoDiv.style.display = 'block';
                fileInfoDiv.innerText = `文件名：${f.name}\n大小：${(f.size/1024/1024).toFixed(2)} MB`;
            });

            uploadBtn.addEventListener('click', () => startUpload(false));
            pauseBtn.addEventListener('click', pauseUpload);
            resumeBtn.addEventListener('click', resumeUpload);

            window.addEventListener('beforeunload', function(e) {
                if (uploadActive) { e.preventDefault(); e.returnValue = '上传未完成，确定离开？'; }
            });
        })();

        // ---------- 搜索功能 (AJAX) ----------
        async function searchMD5(event) {
            event.preventDefault();
            const input = document.getElementById('md5_input');
            const md5 = input.value.trim().toLowerCase();
            if (!/^[a-f0-9]{32}$/.test(md5)) {
                alert('请输入正确的32位MD5值');
                return;
            }
            const resultsDiv = document.getElementById('searchResults');
            resultsDiv.style.display = 'block';
            resultsDiv.innerHTML = '<p>搜索中...</p>';
            try {
                const response = await fetch(`?action=search&md5=${md5}`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await response.json();
                if (data.length === 0) {
                    resultsDiv.innerHTML = '<p class="alert info">未找到匹配的文件</p>';
                } else {
                    let html = '<h4>找到以下文件：</h4>';
                    data.forEach(item => {
                        html += `<div class="result-item">
                            <strong>${item.original}</strong> (${(item.size/1024/1024).toFixed(2)} MB)<br>
                            上传时间：${item.time}<br>
                            下载：<a href="${item.url}" target="_blank">${item.url}</a>
                        </div>`;
                    });
                    resultsDiv.innerHTML = html;
                }
            } catch (e) {
                resultsDiv.innerHTML = '<p class="alert error">搜索失败</p>';
            }
        }
    </script>
</body>
</html>