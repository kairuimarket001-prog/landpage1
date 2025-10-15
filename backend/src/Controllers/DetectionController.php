<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class DetectionController
{
    private LoggerInterface $logger;
    private string $dataDir;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->dataDir = __DIR__ . '/../../data';

        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }
    }

    public function analyzeUserBehavior(string $sessionId, array $behaviors): array
    {
        $score = 100;
        $reasons = [];

        if (empty($behaviors)) {
            return [
                'session_id' => $sessionId,
                'score' => 0,
                'result' => 'unknown',
                'reasons' => ['无用户行为数据']
            ];
        }

        $timestamps = array_map(function($b) {
            return strtotime($b['timestamp'] ?? $b['created_at'] ?? 'now');
        }, $behaviors);

        $intervals = [];
        for ($i = 1; $i < count($timestamps); $i++) {
            $interval = $timestamps[$i] - $timestamps[$i - 1];
            if ($interval > 0) {
                $intervals[] = $interval;
            }
        }

        if (!empty($intervals)) {
            $avgInterval = array_sum($intervals) / count($intervals);
            $variance = 0;
            foreach ($intervals as $interval) {
                $variance += pow($interval - $avgInterval, 2);
            }
            $stdDev = count($intervals) > 1 ? sqrt($variance / count($intervals)) : 0;

            if ($stdDev < 0.5 && $avgInterval < 2) {
                $score -= 25;
                $reasons[] = '行为时间间隔过于规律，疑似自动化脚本';
            }

            if ($avgInterval < 0.5) {
                $score -= 20;
                $reasons[] = '操作速度异常快，不符合人类操作习惯';
            }
        }

        $sessionDuration = !empty($timestamps) ? max($timestamps) - min($timestamps) : 0;
        if ($sessionDuration < 3 && count($behaviors) > 5) {
            $score -= 15;
            $reasons[] = '短时间内大量操作，疑似机器人行为';
        }

        if ($sessionDuration > 3600 && count($behaviors) < 3) {
            $score -= 10;
            $reasons[] = '长时间会话但操作极少，可能为挂机脚本';
        }

        $actionTypes = array_column($behaviors, 'action_type');
        $uniqueActions = count(array_unique($actionTypes));
        if ($uniqueActions === 1 && count($behaviors) > 3) {
            $score -= 15;
            $reasons[] = '行为模式单一，缺乏人类操作的多样性';
        }

        $ips = array_unique(array_column($behaviors, 'ip'));
        $userAgents = array_unique(array_column($behaviors, 'user_agent'));

        if (count($ips) > 3) {
            $score -= 20;
            $reasons[] = '同一会话使用多个IP地址，疑似代理池';
        }

        if (count($userAgents) > 2) {
            $score -= 15;
            $reasons[] = 'User Agent频繁变更，不符合正常浏览行为';
        }

        $pageLoads = array_filter($actionTypes, function($type) {
            return $type === 'page_load';
        });

        if (count($pageLoads) === count($behaviors)) {
            $score -= 10;
            $reasons[] = '仅有页面加载记录，缺少实际交互行为';
        }

        $conversionExists = in_array('conversion', $actionTypes);
        $popupExists = in_array('popup_triggered', $actionTypes);

        if ($conversionExists && count($behaviors) < 3) {
            $score -= 15;
            $reasons[] = '转化过快，未经过正常浏览流程';
        }

        if (empty($reasons)) {
            $reasons[] = '用户行为模式正常，符合真人操作特征';
        }

        $score = max(0, min(100, $score));

        if ($score >= 70) {
            $result = 'human';
            $resultText = '真人';
        } elseif ($score >= 40) {
            $result = 'ai';
            $resultText = '疑似AI';
        } else {
            $result = 'bot';
            $resultText = '机器人';
        }

        return [
            'session_id' => $sessionId,
            'score' => $score,
            'result' => $result,
            'result_text' => $resultText,
            'reasons' => $reasons,
            'behavior_count' => count($behaviors),
            'session_duration' => $sessionDuration,
            'unique_actions' => $uniqueActions,
            'analyzed_at' => date('Y-m-d H:i:s')
        ];
    }

    public function analyzeAllSessions(): array
    {
        $behaviorFile = $this->dataDir . '/user_behaviors.jsonl';
        if (!file_exists($behaviorFile)) {
            return [];
        }

        $lines = file($behaviorFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $sessionBehaviors = [];

        foreach ($lines as $line) {
            $behavior = json_decode($line, true);
            if ($behavior && isset($behavior['session_id'])) {
                $sessionId = $behavior['session_id'];
                if (!isset($sessionBehaviors[$sessionId])) {
                    $sessionBehaviors[$sessionId] = [];
                }
                $sessionBehaviors[$sessionId][] = $behavior;
            }
        }

        $detectionResults = [];
        foreach ($sessionBehaviors as $sessionId => $behaviors) {
            $analysis = $this->analyzeUserBehavior($sessionId, $behaviors);

            $firstBehavior = $behaviors[0];
            $analysis['user_data'] = [
                'ip' => $firstBehavior['ip'] ?? 'unknown',
                'user_agent' => $firstBehavior['user_agent'] ?? '',
                'timezone' => $firstBehavior['timezone'] ?? '',
                'language' => $firstBehavior['language'] ?? '',
                'referer' => $firstBehavior['referer'] ?? '',
                'stock_name' => $firstBehavior['stock_name'] ?? '',
                'stock_code' => $firstBehavior['stock_code'] ?? ''
            ];

            $detectionResults[] = $analysis;
        }

        usort($detectionResults, function($a, $b) {
            return strtotime($b['analyzed_at']) - strtotime($a['analyzed_at']);
        });

        return $detectionResults;
    }

    public function saveDetectionResult(array $result): void
    {
        $file = $this->dataDir . '/user_detection_scores.jsonl';
        $line = json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    public function getDetectionResults(int $page = 1, int $perPage = 10, string $filterType = 'all'): array
    {
        $results = $this->analyzeAllSessions();

        if ($filterType !== 'all') {
            $results = array_filter($results, function($r) use ($filterType) {
                return $r['result'] === $filterType;
            });
            $results = array_values($results);
        }

        $total = count($results);
        $offset = ($page - 1) * $perPage;
        $paginatedResults = array_slice($results, $offset, $perPage);

        $stats = [
            'total' => $total,
            'human' => count(array_filter($results, fn($r) => $r['result'] === 'human')),
            'ai' => count(array_filter($results, fn($r) => $r['result'] === 'ai')),
            'bot' => count(array_filter($results, fn($r) => $r['result'] === 'bot'))
        ];

        return [
            'data' => $paginatedResults,
            'stats' => $stats,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => (int)ceil($total / $perPage)
        ];
    }
}
