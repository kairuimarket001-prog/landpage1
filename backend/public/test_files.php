<?php
header('Content-Type: application/json; charset=utf-8');

$dataDir = __DIR__ . '/../data';
$logsDir = __DIR__ . '/../logs';

$result = [
    'data_dir' => [
        'path' => $dataDir,
        'exists' => is_dir($dataDir),
        'writable' => is_writable($dataDir),
        'files' => []
    ],
    'logs_dir' => [
        'path' => $logsDir,
        'exists' => is_dir($logsDir),
        'writable' => is_writable($logsDir),
        'files' => []
    ],
    'user_behaviors' => [
        'path' => $dataDir . '/user_behaviors.jsonl',
        'exists' => file_exists($dataDir . '/user_behaviors.jsonl'),
        'readable' => is_readable($dataDir . '/user_behaviors.jsonl'),
        'size' => file_exists($dataDir . '/user_behaviors.jsonl') ? filesize($dataDir . '/user_behaviors.jsonl') : 0,
        'lines' => 0,
        'sample' => []
    ]
];

// 列出data目录的文件
if (is_dir($dataDir)) {
    $files = scandir($dataDir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $filePath = $dataDir . '/' . $file;
            $result['data_dir']['files'][] = [
                'name' => $file,
                'size' => filesize($filePath),
                'modified' => date('Y-m-d H:i:s', filemtime($filePath))
            ];
        }
    }
}

// 列出logs目录的文件
if (is_dir($logsDir)) {
    $files = scandir($logsDir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $filePath = $logsDir . '/' . $file;
            $result['logs_dir']['files'][] = [
                'name' => $file,
                'size' => filesize($filePath),
                'modified' => date('Y-m-d H:i:s', filemtime($filePath))
            ];
        }
    }
}

// 读取user_behaviors.jsonl的内容
$behaviorFile = $dataDir . '/user_behaviors.jsonl';
if (file_exists($behaviorFile) && is_readable($behaviorFile)) {
    $lines = file($behaviorFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $result['user_behaviors']['lines'] = count($lines);

    // 获取最后5行作为示例
    $sampleLines = array_slice($lines, -5);
    foreach ($sampleLines as $line) {
        $data = json_decode($line, true);
        if ($data) {
            $result['user_behaviors']['sample'][] = $data;
        }
    }
}

// 测试写入权限
try {
    $testFile = $dataDir . '/test_write.txt';
    file_put_contents($testFile, 'test');
    $result['write_test'] = [
        'success' => true,
        'message' => 'Write test successful'
    ];
    @unlink($testFile);
} catch (Exception $e) {
    $result['write_test'] = [
        'success' => false,
        'message' => $e->getMessage()
    ];
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
