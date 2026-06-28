<?php
/**
 * 姿势/动作词库 API
 * GET /api/pose-dict.php?q=站
 *   无 q → 返回全部分类
 *   有 q → 模糊过滤（中英文）
 */
require_once __DIR__ . '/../../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $q = $_GET['q'] ?? '';
    if ($q !== '') {
        $data = \NaiStudio\PoseDict::search($q);
    } else {
        $data = \NaiStudio\PoseDict::all();
    }

    // 统计总数
    $total = 0;
    foreach ($data as $items) {
        $total += count($items);
    }

    echo json_encode([
        'ok'      => true,
        'query'   => $q,
        'total'   => $total,
        'categories' => $data,
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
