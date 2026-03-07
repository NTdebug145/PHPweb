<?php
session_set_cookie_params(['path' => '/']);
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}

define('WAREHOUSE_DIR', __DIR__ . '/data/warehouse');
if (!file_exists(WAREHOUSE_DIR)) {
    mkdir(WAREHOUSE_DIR, 0755, true);
}

// ---------- 物品信息映射 ----------
$itemInfoMap = [];
$levelColors = [
    1 => '#f0f0f0', // 淡白
    2 => '#a5d6a5', // 淡绿
    3 => '#90caf9', // 淡蓝
    4 => '#ce93d8', // 淡紫
    5 => '#ffd54f', // 淡金
    6 => '#ef9a9a', // 淡红
];

$itemInfoFile = __DIR__ . '/item/iteminfo.json';
if (file_exists($itemInfoFile)) {
    $content = file_get_contents($itemInfoFile);
    $data = json_decode($content, true);
    if (is_array($data)) {
        foreach ($data as $entry) {
            if (isset($entry['itemid'])) {
                $itemInfoMap[$entry['itemid']] = $entry;
            }
        }
    }
}
// 确保至少有一个默认物品
if (!isset($itemInfoMap['default_1x1'])) {
    $itemInfoMap['default_1x1'] = [
        'itemid' => 'default_1x1',
        'block' => '1x1',
        'img' => '',
        'level' => 1,
        'info' => '默认基础物品'
    ];
}

// ---------- 仓库数据分片存储函数 ----------
function getAllWarehouses() {
    $all = [];
    $files = glob(WAREHOUSE_DIR . '/warehouse_*.json');
    foreach ($files as $file) {
        $data = json_decode(file_get_contents($file), true);
        if (is_array($data)) {
            $all = array_merge($all, $data);
        }
    }
    return $all;
}

function saveAllWarehouses($warehouses) {
    array_map('unlink', glob(WAREHOUSE_DIR . '/warehouse_*.json'));
    $chunks = array_chunk($warehouses, 50);
    foreach ($chunks as $i => $chunk) {
        file_put_contents(WAREHOUSE_DIR . '/warehouse_' . ($i+1) . '.json', json_encode($chunk, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}

function getWarehouseByUserId($userId) {
    global $itemInfoMap, $levelColors;
    $all = getAllWarehouses();
    foreach ($all as $wh) {
        if ($wh['user_id'] == $userId) {
            $items = $wh['items'];
            // 补充详细信息并验证尺寸
            foreach ($items as &$item) {
                $itemid = $item['itemid'] ?? null;
                if ($itemid && isset($itemInfoMap[$itemid])) {
                    $info = $itemInfoMap[$itemid];
                    $item['name'] = $info['block'] ?? $itemid;
                    $item['details'] = $info['info'] ?? '';
                    $level = $info['level'] ?? 1;
                    $item['color'] = $levelColors[$level] ?? $levelColors[1];
                    $item['img'] = !empty($info['img']) ? ('item/img/' . $info['img']) : '';

                    // 解析原始尺寸
                    $parts = explode('x', $info['block']);
                    $origW = (int)($parts[0] ?? 1);
                    $origH = (int)($parts[1] ?? 1);

                    // 获取存储的尺寸
                    $storedW = isset($item['w']) ? (int)$item['w'] : $origW;
                    $storedH = isset($item['h']) ? (int)$item['h'] : $origH;

                    // 验证面积是否一致（允许旋转）
                    if ($storedW * $storedH != $origW * $origH) {
                        // 面积不匹配，可能是错误数据，修正为原始尺寸
                        $item['w'] = $origW;
                        $item['h'] = $origH;
                    } else {
                        // 面积一致，保留存储的尺寸（可能是旋转后的状态）
                        $item['w'] = $storedW;
                        $item['h'] = $storedH;
                    }

                    // 记录原始尺寸，用于前端判断图片旋转
                    $item['origW'] = $origW;
                    $item['origH'] = $origH;
                    $item['canRotate'] = ($origW != $origH);
                } else {
                    // 未知 itemid，使用默认属性
                    $item['name'] = $item['name'] ?? '未知';
                    $item['color'] = $item['color'] ?? $levelColors[1];
                    $item['canRotate'] = $item['canRotate'] ?? false;
                    $item['details'] = $item['details'] ?? '';
                    $item['img'] = $item['img'] ?? '';
                    $item['origW'] = $item['w'] ?? 1;
                    $item['origH'] = $item['h'] ?? 1;
                }
            }
            return $items;
        }
    }
    // 用户无仓库，创建默认物品
    $defaultId = 'default_1x1';
    $info = $itemInfoMap[$defaultId];
    $parts = explode('x', $info['block']);
    $origW = (int)($parts[0] ?? 1);
    $origH = (int)($parts[1] ?? 1);
    $defaultItems = [[
        'itemid' => $defaultId,
        'x' => 0, 'y' => 0,
        'w' => $origW, 'h' => $origH,
        'name' => $info['block'],
        'color' => $levelColors[$info['level']] ?? $levelColors[1],
        'canRotate' => ($origW != $origH),
        'details' => $info['info'] ?? '',
        'img' => !empty($info['img']) ? ('item/img/' . $info['img']) : '',
        'origW' => $origW,
        'origH' => $origH
    ]];
    $all[] = ['user_id' => $userId, 'items' => $defaultItems];
    saveAllWarehouses($all);
    return $defaultItems;
}

function saveWarehouseForUser($userId, $items) {
    // 仅存储必要字段：itemid, x, y, w, h
    $storedItems = [];
    foreach ($items as $item) {
        $storedItems[] = [
            'itemid' => $item['itemid'] ?? 'unknown',
            'x' => $item['x'],
            'y' => $item['y'],
            'w' => $item['w'],
            'h' => $item['h']
        ];
    }
    $all = getAllWarehouses();
    $found = false;
    foreach ($all as &$wh) {
        if ($wh['user_id'] == $userId) {
            $wh['items'] = $storedItems;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $all[] = ['user_id' => $userId, 'items' => $storedItems];
    }
    saveAllWarehouses($all);
}

// ---------- API 处理 ----------
$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    try {
        if ($action === 'getWarehouse') {
            echo json_encode(['success' => true, 'warehouse' => getWarehouseByUserId($_SESSION['user_id'])], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($action === 'saveWarehouse') {
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input || !isset($input['_csrf']) || $input['_csrf'] !== $_SESSION['csrf_token']) {
                throw new Exception('CSRF 验证失败');
            }
            if (!isset($input['items'])) {
                throw new Exception('数据格式错误');
            }
            saveWarehouseForUser($_SESSION['user_id'], $input['items']);
            echo json_encode(['success' => true]);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// 非 API 请求：输出画布页面
$csrfToken = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrfToken;
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <title>我的仓库 · 带贴图</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            user-select: none;
        }
        body {
            background: #1a2a2f;
            min-height: 100vh;
            display: flex;
            justify-content: flex-end;
            align-items: flex-start;
            font-family: 'Segoe UI', Roboto, sans-serif;
        }
        .canvas-wrapper {
            width: 50%;
            max-width: 900px;
            margin: 20px;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        canvas {
            display: block;
            width: 100%;
            background: #243238;
            border-radius: 8px;
            box-shadow: 0 0 0 1px #3c5a64;
            cursor: grab;
        }
        canvas.dragging {
            cursor: grabbing;
        }
        .info-panel {
            background: #1e3138;
            padding: 12px 18px;
            border-radius: 40px;
            border: 1px solid #3f5f6b;
            color: #a3bec9;
            font-size: 14px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
        }
        .item-badge {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 15px;
        }
        .badge {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .color-sample {
            width: 18px;
            height: 18px;
            border-radius: 4px;
            border: 1px solid #263b3b;
        }
        .rule-hint {
            background: #27424b;
            padding: 6px 14px;
            border-radius: 30px;
            color: #c7e2ec;
            font-size: 13px;
            border-left: 3px solid #f0b87b;
        }
        .footer-note {
            text-align: center;
            color: #5d7e8a;
            font-size: 13px;
        }
        button {
            background: #3e5f6b;
            border: none;
            color: white;
            padding: 6px 18px;
            border-radius: 30px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            box-shadow: 0 4px 0 #1b2f36;
            transition: 0.07s ease;
            border: 1px solid #6a8f9c;
        }
        button:active {
            transform: translateY(4px);
            box-shadow: none;
        }
        .modal {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            justify-content: center;
            align-items: center;
            z-index: 2000;
        }
        .modal-content {
            background: #2d2d2d;
            color: #eee;
            padding: 25px;
            border-radius: 12px;
            min-width: 300px;
            max-width: 400px;
        }
        .modal-content .close {
            float: right;
            font-size: 24px;
            cursor: pointer;
            color: #aaa;
        }
        .modal-content .close:hover {
            color: #fff;
        }
        .item-detail-content p {
            margin: 10px 0;
            line-height: 1.5;
        }
    </style>
</head>
<body>
<div class="canvas-wrapper">
    <canvas id="warehouseCanvas"></canvas>

    <div class="info-panel">
        <div class="item-badge" id="colorLegend"></div>
        <div class="rule-hint">
            ⚡ 拖拽旋转 · 绿色边框可交换 · 颜色=等级 · 图片拉伸填充
        </div>
        <button id="reloadBtn">↻ 重新加载</button>
    </div>
    <div class="footer-note">
        ⚡ 智能贴合 + 重叠自动旋转 + 智能互换 + 固定移动物品
    </div>
</div>

<!-- 物品详情模态框 -->
<div class="modal" id="itemDetailModal">
    <div class="modal-content">
        <span class="close" onclick="document.getElementById('itemDetailModal').style.display='none'">&times;</span>
        <h3 style="margin-bottom:15px;">📦 物品详情</h3>
        <div id="itemDetailContent" class="item-detail-content"></div>
    </div>
</div>

<script>
    const CSRF_TOKEN = '<?php echo $csrfToken; ?>';
</script>

<script>
    (function() {
        const COLS = 9;
        const ROWS = 32;

        let items = [];
        let grid = Array.from({ length: ROWS }, () => Array(COLS).fill(null));

        // 图片缓存
        const imageCache = new Map();

        // 拖拽状态
        let dragging = false;
        let dragItem = null;
        let dragItemOriginal = { x:0, y:0, w:0, h:0 };
        let dragOffset = { x:0, y:0 };
        let preview = { x:0, y:0, w:0, h:0 };
        let validPreview = false;
        let possibleSwap = false;
        let isReversed = false;
        let hasMoved = false;

        const canvas = document.getElementById('warehouseCanvas');
        const ctx = canvas.getContext('2d');

        // ---------- 网格操作 ----------
        function buildGridFromItems() {
            for (let y = 0; y < ROWS; y++) {
                for (let x = 0; x < COLS; x++) {
                    grid[y][x] = null;
                }
            }
            items.forEach(item => {
                for (let dy = 0; dy < item.h; dy++) {
                    for (let dx = 0; dx < item.w; dx++) {
                        const ny = item.y + dy;
                        const nx = item.x + dx;
                        if (ny < ROWS && nx < COLS) grid[ny][nx] = item.id;
                    }
                }
            });
        }

        function clearItemFromGrid(itemId) {
            for (let y = 0; y < ROWS; y++) {
                for (let x = 0; x < COLS; x++) {
                    if (grid[y][x] === itemId) grid[y][x] = null;
                }
            }
        }

        function fillItemToGrid(item) {
            for (let dy = 0; dy < item.h; dy++) {
                for (let dx = 0; dx < item.w; dx++) {
                    const ny = item.y + dy;
                    const nx = item.x + dx;
                    if (ny < ROWS && nx < COLS) grid[ny][nx] = item.id;
                }
            }
        }

        // ---------- 碰撞检测 ----------
        function canPlaceExcluding(posX, posY, w, h, excludeIdSet) {
            if (posX < 0 || posY < 0 || posX + w > COLS || posY + h > ROWS) return false;
            for (let dy = 0; dy < h; dy++) {
                for (let dx = 0; dx < w; dx++) {
                    const cellId = grid[posY + dy][posX + dx];
                    if (cellId !== null && !excludeIdSet.has(cellId)) return false;
                }
            }
            return true;
        }

        function canPlace(item, posX, posY, w, h) {
            return canPlaceExcluding(posX, posY, w, h, new Set([item.id]));
        }

        function isItemOverlappingAny(item) {
            for (let other of items) {
                if (other.id === item.id) continue;
                if (!(item.x + item.w <= other.x || item.x >= other.x + other.w ||
                      item.y + item.h <= other.y || item.y >= other.y + other.h)) {
                    return true;
                }
            }
            return false;
        }

        function findFirstEmptySpot(item) {
            for (let y = 0; y <= ROWS - item.h; y++) {
                for (let x = 0; x <= COLS - item.w; x++) {
                    if (canPlaceExcluding(x, y, item.w, item.h, new Set([item.id]))) {
                        return { x, y };
                    }
                }
            }
            return null;
        }

        function resolveOverlapsForItems(targetItems, fixedItemId) {
            const MAX_ITER = 20;
            let moved = true;
            let iter = 0;
            while (moved && iter < MAX_ITER) {
                moved = false;
                for (let item of targetItems) {
                    if (item.id === fixedItemId) continue;
                    if (isItemOverlappingAny(item)) {
                        const pos = findFirstEmptySpot(item);
                        if (pos) {
                            clearItemFromGrid(item.id);
                            item.x = pos.x;
                            item.y = pos.y;
                            fillItemToGrid(item);
                            moved = true;
                        }
                    }
                }
                iter++;
            }
        }

        // ---------- 交换检测 ----------
        function checkPossibleSwap() {
            if (!dragItem) return false;
            const itemsInRect = [];
            for (let item of items) {
                if (item.id === dragItem.id) continue;
                if (item.x >= preview.x && item.y >= preview.y && 
                    item.x + item.w <= preview.x + preview.w && 
                    item.y + item.h <= preview.y + preview.h) {
                    itemsInRect.push(item);
                }
            }
            if (itemsInRect.length === 0) return false;

            if (itemsInRect.length === 1) {
                const other = itemsInRect[0];
                const newDragX = preview.x;
                const newDragY = preview.y;
                const newDragW = isReversed ? dragItem.h : dragItem.w;
                const newDragH = isReversed ? dragItem.w : dragItem.h;
                const newOtherX = dragItemOriginal.x;
                const newOtherY = dragItemOriginal.y;
                const newOtherW = other.w;
                const newOtherH = other.h;

                if (newDragX < 0 || newDragY < 0 || newDragX + newDragW > COLS || newDragY + newDragH > ROWS) return false;
                if (newOtherX < 0 || newOtherY < 0 || newOtherX + newOtherW > COLS || newOtherY + newOtherH > ROWS) return false;
                if (!(newDragX + newDragW <= newOtherX || newDragX >= newOtherX + newOtherW ||
                      newDragY + newDragH <= newOtherY || newDragY >= newOtherY + newOtherH)) {
                    return false;
                }

                const movingIds = new Set([dragItem.id, other.id]);
                if (!canPlaceExcluding(newDragX, newDragY, newDragW, newDragH, movingIds)) return false;
                if (!canPlaceExcluding(newOtherX, newOtherY, newOtherW, newOtherH, movingIds)) return false;
                return true;
            }

            // 多物品整体平移
            let minX = Infinity, minY = Infinity;
            for (let item of itemsInRect) {
                minX = Math.min(minX, item.x);
                minY = Math.min(minY, item.y);
            }
            const deltaX = dragItemOriginal.x - minX;
            const deltaY = dragItemOriginal.y - minY;
            const movingIds = new Set(itemsInRect.map(i => i.id));
            movingIds.add(dragItem.id);

            for (let item of itemsInRect) {
                const newX = item.x + deltaX;
                const newY = item.y + deltaY;
                if (newX < dragItemOriginal.x || newY < dragItemOriginal.y ||
                    newX + item.w > dragItemOriginal.x + dragItemOriginal.w ||
                    newY + item.h > dragItemOriginal.y + dragItemOriginal.h) {
                    return false;
                }
                if (!canPlaceExcluding(newX, newY, item.w, item.h, movingIds)) {
                    return false;
                }
            }

            const newDragX = preview.x;
            const newDragY = preview.y;
            const newDragW = isReversed ? dragItem.h : dragItem.w;
            const newDragH = isReversed ? dragItem.w : dragItem.h;
            if (!canPlaceExcluding(newDragX, newDragY, newDragW, newDragH, movingIds)) {
                return false;
            }
            return true;
        }

        function performOneToOneSwap(other) {
            const newDragX = preview.x;
            const newDragY = preview.y;
            const newDragW = isReversed ? dragItem.h : dragItem.w;
            const newDragH = isReversed ? dragItem.w : dragItem.h;
            const newOtherX = dragItemOriginal.x;
            const newOtherY = dragItemOriginal.y;

            clearItemFromGrid(dragItem.id);
            clearItemFromGrid(other.id);

            dragItem.x = newDragX;
            dragItem.y = newDragY;
            dragItem.w = newDragW;
            dragItem.h = newDragH;
            other.x = newOtherX;
            other.y = newOtherY;

            fillItemToGrid(dragItem);
            fillItemToGrid(other);

            resolveOverlapsForItems([dragItem, other], dragItem.id);
        }

        function performMultiSwap(itemsInRect) {
            let minX = Infinity, minY = Infinity;
            for (let item of itemsInRect) {
                minX = Math.min(minX, item.x);
                minY = Math.min(minY, item.y);
            }
            const deltaX = dragItemOriginal.x - minX;
            const deltaY = dragItemOriginal.y - minY;
            const movingIds = new Set(itemsInRect.map(i => i.id));
            movingIds.add(dragItem.id);

            for (let item of itemsInRect) {
                clearItemFromGrid(item.id);
            }
            clearItemFromGrid(dragItem.id);

            dragItem.x = preview.x;
            dragItem.y = preview.y;
            if (isReversed) {
                [dragItem.w, dragItem.h] = [dragItem.h, dragItem.w];
            }
            fillItemToGrid(dragItem);

            for (let item of itemsInRect) {
                item.x += deltaX;
                item.y += deltaY;
                fillItemToGrid(item);
            }

            resolveOverlapsForItems([dragItem, ...itemsInRect], dragItem.id);
        }

        // ---------- 贴合与旋转决策 ----------
        function getContactInfo(rectX, rectY, rectW, rectH, other) {
            if (rectY + rectH === other.y && rectX < other.x + other.w && rectX + rectW > other.x) {
                return { dir: 'bottom', targetLen: other.w };
            }
            if (rectY === other.y + other.h && rectX < other.x + other.w && rectX + rectW > other.x) {
                return { dir: 'top', targetLen: other.w };
            }
            if (rectX + rectW === other.x && rectY < other.y + other.h && rectY + rectH > other.y) {
                return { dir: 'right', targetLen: other.h };
            }
            if (rectX === other.x + other.w && rectY < other.y + other.h && rectY + rectH > other.y) {
                return { dir: 'left', targetLen: other.h };
            }
            return null;
        }

        function isAdjacentToAny(rectX, rectY, rectW, rectH, excludeId) {
            for (let item of items) {
                if (item.id === excludeId) continue;
                if (getContactInfo(rectX, rectY, rectW, rectH, item)) return true;
            }
            return false;
        }

        function checkMaxEdgeFit(baseX, baseY, baseW, baseH, revX, revY, revW, revH, excludeId) {
            for (let item of items) {
                if (item.id === excludeId) continue;

                const baseContact = getContactInfo(baseX, baseY, baseW, baseH, item);
                if (baseContact) {
                    const movingEdgeLen = (baseContact.dir === 'top' || baseContact.dir === 'bottom') ? baseW : baseH;
                    const movingLong = Math.max(baseW, baseH);
                    if (baseContact.targetLen <= movingEdgeLen) {
                        if (movingEdgeLen === movingLong) {
                            return false;
                        } else {
                            const revContact = getContactInfo(revX, revY, revW, revH, item);
                            if (revContact) {
                                const revEdgeLen = (revContact.dir === 'top' || revContact.dir === 'bottom') ? revW : revH;
                                if (revContact.targetLen <= revEdgeLen && revEdgeLen === Math.max(revW, revH)) {
                                    return true;
                                }
                            }
                        }
                    }
                }

                const revContact = getContactInfo(revX, revY, revW, revH, item);
                if (revContact) {
                    const movingEdgeLen = (revContact.dir === 'top' || revContact.dir === 'bottom') ? revW : revH;
                    const movingLong = Math.max(revW, revH);
                    if (revContact.targetLen <= movingEdgeLen) {
                        if (movingEdgeLen === movingLong) {
                            return true;
                        } else {
                            const baseContact2 = getContactInfo(baseX, baseY, baseW, baseH, item);
                            if (baseContact2) {
                                const baseEdgeLen = (baseContact2.dir === 'top' || baseContact2.dir === 'bottom') ? baseW : baseH;
                                if (baseContact2.targetLen <= baseEdgeLen && baseEdgeLen === Math.max(baseW, baseH)) {
                                    return false;
                                }
                            }
                        }
                    }
                }
            }
            return null;
        }

        // ---------- 绘制（带图片，拉伸填充）----------
        function draw() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            const cellW = canvas.width / COLS;
            const cellH = canvas.height / ROWS;

            // 网格线
            ctx.strokeStyle = '#3d5f6b';
            ctx.lineWidth = 1;
            for (let i = 0; i <= COLS; i++) {
                ctx.beginPath(); ctx.moveTo(i*cellW, 0); ctx.lineTo(i*cellW, canvas.height); ctx.stroke();
            }
            for (let i = 0; i <= ROWS; i++) {
                ctx.beginPath(); ctx.moveTo(0, i*cellH); ctx.lineTo(canvas.width, i*cellH); ctx.stroke();
            }

            // 绘制所有物品（除拖拽中的）
            items.forEach(item => {
                if (dragging && dragItem && dragItem.id === item.id) return;
                drawItem(item, cellW, cellH);
            });

            // 绘制预览
            if (dragging && dragItem) {
                drawPreview(cellW, cellH);
            }
        }

        function drawItem(item, cellW, cellH) {
            const x = item.x * cellW;
            const y = item.y * cellH;
            const w = item.w * cellW;
            const h = item.h * cellH;

            // 背景色
            ctx.fillStyle = item.color;
            ctx.shadowColor = 'rgba(0,0,0,0.5)';
            ctx.shadowBlur = 4;
            ctx.fillRect(x, y, w, h);
            ctx.shadowBlur = 0;

            // 边框
            ctx.strokeStyle = '#fff';
            ctx.lineWidth = 2;
            ctx.strokeRect(x, y, w, h);

            // 绘制图片（如果已缓存）
            if (item.img && imageCache.has(item.img)) {
                const img = imageCache.get(item.img);
                if (img.complete && img.naturalWidth > 0) {
                    drawImageInRect(img, x, y, w, h, item);
                }
            } else {
                // 没有图片或未加载，显示文字尺寸
                ctx.fillStyle = '#f0f6f7';
                const fontSize = Math.max(12, Math.floor(cellH * 0.4));
                ctx.font = `bold ${fontSize}px "Segoe UI", monospace`;
                ctx.shadowColor = '#000';
                ctx.shadowBlur = 6;
                ctx.fillText(`${item.w}x${item.h}`, x + 4, y + fontSize + 2);
                ctx.shadowBlur = 0;
            }
        }

        function drawPreview(cellW, cellH) {
            const x = preview.x * cellW;
            const y = preview.y * cellH;
            const w = preview.w * cellW;
            const h = preview.h * cellH;

            ctx.globalAlpha = 0.7;
            ctx.fillStyle = dragItem.color;
            ctx.fillRect(x, y, w, h);
            ctx.globalAlpha = 1.0;
            ctx.strokeStyle = (validPreview || possibleSwap) ? '#a5d6a5' : '#f08080';
            ctx.lineWidth = 4;
            ctx.strokeRect(x, y, w, h);

            // 预览时也尝试显示图片（若已缓存）
            if (dragItem.img && imageCache.has(dragItem.img)) {
                const img = imageCache.get(dragItem.img);
                if (img.complete && img.naturalWidth > 0) {
                    drawImageInRect(img, x, y, w, h, dragItem);
                }
            } else {
                ctx.fillStyle = '#fff';
                const fontSize = Math.max(12, Math.floor(cellH * 0.4));
                ctx.font = `bold ${fontSize}px "Segoe UI"`;
                ctx.shadowColor = '#000';
                ctx.shadowBlur = 5;
                ctx.fillText(`${preview.w}x${preview.h}`, x + 5, y + fontSize + 4);
                ctx.shadowBlur = 0;
            }
        }

// 在指定矩形内绘制图片，拉伸填充，并根据物品方向自动旋转
function drawImageInRect(img, x, y, w, h, item) {
    const pad = 2;
    const availW = w - pad * 2;
    const availH = h - pad * 2;
    if (availW <= 0 || availH <= 0) return;

    // 判断是否需要基于方向旋转（存储尺寸与原始尺寸互换）
    const needRotate = (item.w !== item.h) && (item.w === item.origH && item.h === item.origW);
    
    // 判断是否需要二次旋转（除3x4和不可旋转物品外）
    const needSecondRotate = !(item.w === 3 && item.h === 4) && item.canRotate;

    // 基础旋转次数（每次90°）
    let rotationCount = 0;
    if (needRotate) rotationCount++;
    if (needSecondRotate) rotationCount++;

    // 额外无条件增加180°旋转，排除正方形物品（宽高相等）
    if (item.w !== item.h) {
        rotationCount += 2; // 加180°（2个90°）
    }

    // 特定物品旋转规则
    const isVertical2x3 = (item.w === 2 && item.h === 3); // 竖向2x3
    const isVertical1x2 = (item.w === 1 && item.h === 2); // 竖向1x2
    const isVertical3x1 = (item.w === 3 && item.h === 1); // 竖向3x1
    const isHorizontal3x1 = (item.w === 1 && item.h === 3); // 横向3x1
    const isVertical3x4 = (item.w === 3 && item.h === 4); // 竖向3x4
    const isHorizontal3x4 = (item.w === 4 && item.h === 3); // 横向3x4

    // 竖向2x3、1x2旋转180度
    if (isVertical2x3 || isVertical1x2) {
        rotationCount += 2; // 180度
    }
    
    // 竖向3x1左旋转90度
    if (isVertical3x1) {
        rotationCount += 3; // 左旋转90度 = 270度顺时针
    }
    
    // 横向3x1右旋转90度
    if (isHorizontal3x1) {
        rotationCount += 1; // 右旋转90度
    }

    // 竖向3x4旋转180度
    if (isVertical3x4) {
        rotationCount += 2; // 180度
    }

    // 横向3x4右旋转90度
    if (isHorizontal3x4) {
        rotationCount += 1; // 右旋转90度
    }

    // 动力电池特殊处理（itemid="动力电池" 的横向3x4左旋转90度）
    if (item.itemid === "动力电池" && isHorizontal3x4) {
        rotationCount -= 0; // 从右旋转90度改为左旋转90度
        rotationCount += 3; // 左旋转90度 = 270度顺时针
    }

    const rotationAngle = rotationCount * Math.PI / 2; // 总旋转角度

    ctx.save();
    ctx.translate(x + w / 2, y + h / 2);
    ctx.rotate(rotationAngle);

    // 若旋转奇数次，绘制区域宽高互换
    if (rotationCount % 2 === 1) {
        ctx.drawImage(img, -availH / 2, -availW / 2, availH, availW);
    } else {
        ctx.drawImage(img, -availW / 2, -availH / 2, availW, availH);
    }

    ctx.restore();
}

        // ---------- 坐标转换 ----------
        function getGridCoord(e) {
            const rect = canvas.getBoundingClientRect();
            const scaleX = canvas.width / rect.width;
            const scaleY = canvas.height / rect.height;
            const canvasX = (e.clientX - rect.left) * scaleX;
            const canvasY = (e.clientY - rect.top) * scaleY;
            const cellW = canvas.width / COLS;
            const cellH = canvas.height / ROWS;
            return {
                x: Math.min(COLS-1, Math.max(0, Math.floor(canvasX / cellW))),
                y: Math.min(ROWS-1, Math.max(0, Math.floor(canvasY / cellH)))
            };
        }

        function getItemAt(gx, gy) {
            for (let i = items.length-1; i >= 0; i--) {
                const it = items[i];
                if (gx >= it.x && gx < it.x + it.w && gy >= it.y && gy < it.y + it.h) return it;
            }
            return null;
        }

        // ---------- 事件处理 ----------
        function handleMouseDown(e) {
            e.preventDefault();
            if (dragging) return;
            const g = getGridCoord(e);
            const item = getItemAt(g.x, g.y);
            if (!item) return;

            dragItem = item;
            dragItemOriginal = { x: item.x, y: item.y, w: item.w, h: item.h };
            dragOffset = { x: g.x - item.x, y: g.y - item.y };
            isReversed = false;
            preview = { x: item.x, y: item.y, w: item.w, h: item.h };
            validPreview = true;
            possibleSwap = false;
            hasMoved = false;

            clearItemFromGrid(dragItem.id);
            dragging = true;
            canvas.classList.add('dragging');
            draw();
        }

        function handleMouseMove(e) {
            if (!dragging || !dragItem) return;
            e.preventDefault();

            const g = getGridCoord(e);
            if (!hasMoved && (g.x !== dragItemOriginal.x + dragOffset.x || g.y !== dragItemOriginal.y + dragOffset.y)) {
                hasMoved = true;
            }

            let baseX = g.x - dragOffset.x;
            let baseY = g.y - dragOffset.y;
            baseX = Math.max(0, Math.min(baseX, COLS - dragItem.w));
            baseY = Math.max(0, Math.min(baseY, ROWS - dragItem.h));

            let revX, revY, revW, revH;
            if (dragItem.canRotate) {
                revW = dragItem.h;
                revH = dragItem.w;
                let revOffsetX = dragOffset.y;
                let revOffsetY = dragOffset.x;
                revX = g.x - revOffsetX;
                revY = g.y - revOffsetY;
                revX = Math.max(0, Math.min(revX, COLS - revW));
                revY = Math.max(0, Math.min(revY, ROWS - revH));
            } else {
                revX = baseX; revY = baseY; revW = dragItem.w; revH = dragItem.h;
            }

            let useRev = null;
            if (dragItem.canRotate) {
                useRev = checkMaxEdgeFit(baseX, baseY, dragItem.w, dragItem.h, revX, revY, revW, revH, dragItem.id);
            }

            if (useRev !== null) {
                isReversed = useRev;
            } else {
                if (dragItem.canRotate) {
                    const revValid = canPlace(dragItem, revX, revY, revW, revH);
                    const revAdjacent = revValid && isAdjacentToAny(revX, revY, revW, revH, dragItem.id);
                    const baseValid = canPlace(dragItem, baseX, baseY, dragItem.w, dragItem.h);
                    const baseAdjacent = baseValid && isAdjacentToAny(baseX, baseY, dragItem.w, dragItem.h, dragItem.id);

                    if (revAdjacent) {
                        isReversed = true;
                    } else if (baseAdjacent) {
                        isReversed = false;
                    } else {
                        isReversed = revValid && !baseValid ? true : false;
                    }
                } else {
                    isReversed = false;
                }
            }

            if (isReversed) {
                preview.x = revX; preview.y = revY; preview.w = revW; preview.h = revH;
            } else {
                preview.x = baseX; preview.y = baseY; preview.w = dragItem.w; preview.h = dragItem.h;
            }

            validPreview = canPlace(dragItem, preview.x, preview.y, preview.w, preview.h);

            if (!validPreview && dragItem.canRotate) {
                if (isReversed) {
                    if (canPlace(dragItem, baseX, baseY, dragItem.w, dragItem.h)) {
                        isReversed = false;
                        preview.x = baseX; preview.y = baseY; preview.w = dragItem.w; preview.h = dragItem.h;
                        validPreview = true;
                    }
                } else {
                    if (canPlace(dragItem, revX, revY, revW, revH)) {
                        isReversed = true;
                        preview.x = revX; preview.y = revY; preview.w = revW; preview.h = revH;
                        validPreview = true;
                    }
                }
            }

            if (!validPreview) {
                possibleSwap = checkPossibleSwap();
            } else {
                possibleSwap = false;
            }

            draw();
        }

        function handleMouseUp(e) {
            if (!dragging || !dragItem) {
                canvas.classList.remove('dragging');
                return;
            }
            e.preventDefault();

            let swapped = false;

            if (validPreview) {
                dragItem.x = preview.x;
                dragItem.y = preview.y;
                if (isReversed) {
                    [dragItem.w, dragItem.h] = [dragItem.h, dragItem.w];
                }
                fillItemToGrid(dragItem);
                swapped = true;
            } else if (possibleSwap) {
                const itemsInRect = [];
                for (let item of items) {
                    if (item.id === dragItem.id) continue;
                    if (item.x >= preview.x && item.y >= preview.y && 
                        item.x + item.w <= preview.x + preview.w && 
                        item.y + item.h <= preview.y + preview.h) {
                        itemsInRect.push(item);
                    }
                }
                if (itemsInRect.length === 1) {
                    performOneToOneSwap(itemsInRect[0]);
                    swapped = true;
                } else if (itemsInRect.length > 1) {
                    performMultiSwap(itemsInRect);
                    swapped = true;
                }
            }

            if (!swapped) {
                dragItem.x = dragItemOriginal.x;
                dragItem.y = dragItemOriginal.y;
                fillItemToGrid(dragItem);
            }

            saveItems(); // 自动保存

            dragging = false;
            dragItem = null;
            canvas.classList.remove('dragging');
            draw();
        }

        function handleClick(e) {
            if (hasMoved) {
                hasMoved = false;
                return;
            }
            const g = getGridCoord(e);
            const item = getItemAt(g.x, g.y);
            if (item) {
                const content = document.getElementById('itemDetailContent');
                content.innerHTML = `
                    <p><span class="color-sample" style="background:${item.color};"></span> <strong>名称:</strong> ${item.name || '未知'}</p>
                    <p><strong>尺寸:</strong> ${item.w} x ${item.h}</p>
                    <p><strong>位置:</strong> (${item.x}, ${item.y})</p>
                    <p><strong>详情:</strong> ${item.details || '无'}</p>
                `;
                document.getElementById('itemDetailModal').style.display = 'flex';
            }
        }

        // ---------- 数据加载与保存 ----------
        async function loadWarehouse() {
            try {
                const res = await fetch('?action=getWarehouse');
                const data = await res.json();
                if (data.success) {
                    items = data.warehouse || [];
                    // 为每个物品添加 id 字段（使用 itemid）
                    items.forEach(item => item.id = item.itemid);
                    buildGridFromItems();
                    // 预加载所有图片
                    await preloadImages(items);
                    updateColorLegend();
                    resizeAndDraw();
                }
            } catch (e) { console.error('加载失败', e); }
        }

        async function preloadImages(items) {
            const promises = [];
            for (let item of items) {
                if (item.img && !imageCache.has(item.img)) {
                    const img = new Image();
                    img.src = item.img;
                    imageCache.set(item.img, img);
                    promises.push(new Promise((resolve) => {
                        img.onload = () => { 
                            draw(); // 图片加载后重绘
                            resolve(); 
                        };
                        img.onerror = () => {
                            console.warn('图片加载失败:', item.img);
                            resolve(); // 即使失败也继续
                        };
                    }));
                }
            }
            await Promise.all(promises);
        }

        async function saveItems() {
            try {
                await fetch('?action=saveWarehouse', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ items, _csrf: CSRF_TOKEN })
                });
            } catch (e) { console.error('保存失败', e); }
        }

        function updateColorLegend() {
            const legendDiv = document.getElementById('colorLegend');
            const uniqueNames = [...new Set(items.map(i => i.name))];
            legendDiv.innerHTML = uniqueNames.map(name => {
                const item = items.find(i => i.name === name);
                return `<div class="badge"><span class="color-sample" style="background:${item.color};"></span> ${name}</div>`;
            }).join('');
        }

        // ---------- 自适应画布 ----------
        function resizeAndDraw() {
            const rect = canvas.getBoundingClientRect();
            if (rect.width <= 0) return;
            const physicalWidth = Math.floor(rect.width);
            const physicalHeight = Math.floor(physicalWidth / COLS * ROWS);
            canvas.width = physicalWidth;
            canvas.height = physicalHeight;
            draw();
        }

        // ---------- 重新加载 ----------
        function reloadFromServer() {
            loadWarehouse();
        }

        // ---------- 初始化 ----------
        const resizeObserver = new ResizeObserver(resizeAndDraw);
        resizeObserver.observe(canvas);

        canvas.addEventListener('mousedown', handleMouseDown);
        canvas.addEventListener('click', handleClick);
        canvas.addEventListener('dragstart', (e) => e.preventDefault());
        window.addEventListener('mousemove', handleMouseMove);
        window.addEventListener('mouseup', handleMouseUp);

        document.getElementById('reloadBtn').addEventListener('click', reloadFromServer);

        window.onclick = function(e) {
            const modal = document.getElementById('itemDetailModal');
            if (e.target === modal) modal.style.display = 'none';
        };

        loadWarehouse();
    })();
</script>
</body>
</html>