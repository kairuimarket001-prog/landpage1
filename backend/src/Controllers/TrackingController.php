<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class TrackingController
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

    public function pageTrack(Request $request, Response $response): Response
    {
        $data = json_decode($request->getBody()->getContents(), true);

        $sessionId = $data['session_id'] ?? '';
        $actionType = $data['action_type'] ?? 'page_load';
        $stockName = $data['stock_name'] ?? '';
        $stockCode = $data['stock_code'] ?? '';

        if (empty($sessionId)) {
            $sessionId = $this->generateSessionId($request);
        }

        $trackingData = [
            'session_id' => $sessionId,
            'action_type' => $actionType,
            'stock_name' => $stockName,
            'stock_code' => $stockCode,
            'url' => $data['url'] ?? '',
            'timestamp' => date('Y-m-d H:i:s'),
            'user_agent' => $request->getHeaderLine('User-Agent'),
            'ip' => $this->getClientIp($request),
            'timezone' => $request->getHeaderLine('timezone'),
            'language' => $request->getHeaderLine('language'),
            'referer' => $request->getHeaderLine('Referer')
        ];

        $this->logger->info('User behavior tracking', $trackingData);
        $this->saveUserBehavior($trackingData);

        $response->getBody()->write(json_encode([
            'status' => 'success',
            'message' => 'Tracking data recorded',
            'session_id' => $sessionId
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function upPageTrack(Request $request, Response $response): Response
    {
        $data = json_decode($request->getBody()->getContents(), true);

        $trackingData = [
            'id' => $data['id'] ?? 0,
            'timestamp' => date('c'),
            'user_agent' => $request->getHeaderLine('User-Agent'),
            'ip' => $this->getClientIp($request),
        ];

        $this->logger->info('Up page tracking', $trackingData);

        $this->saveTrackingData('uppage_track', $trackingData);

        $response->getBody()->write(json_encode(['status' => 'success', 'message' => 'Up page tracking recorded']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function logError(Request $request, Response $response): Response
    {
        $data = json_decode($request->getBody()->getContents(), true);
        
        $errorData = [
            'message' => $data['message'] ?? '',
            'stack' => $data['stack'] ?? '',
            'phase' => $data['phase'] ?? 'unknown',
            'btnText' => $data['btnText'] ?? '',
            'click_type' => $data['click_type'] ?? 0,
            'stockcode' => $data['stockcode'] ?? '',
            'href' => $data['href'] ?? '',
            'ref' => $data['ref'] ?? '',
            'ts' => $data['ts'] ?? time(),
            'user_agent' => $request->getHeaderLine('User-Agent'),
            'ip' => $this->getClientIp($request),
        ];

        $this->logger->error('Frontend error', $errorData);

        $this->saveTrackingData('error_log', $errorData);

        $response->getBody()->write(json_encode(['status' => 'success', 'message' => 'Error logged']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function getClientIp(Request $request): string
    {
        $serverParams = $request->getServerParams();
        
        if (!empty($serverParams['HTTP_X_FORWARDED_FOR'])) {
            return explode(',', $serverParams['HTTP_X_FORWARDED_FOR'])[0];
        }
        
        if (!empty($serverParams['HTTP_X_REAL_IP'])) {
            return $serverParams['HTTP_X_REAL_IP'];
        }
        
        return $serverParams['REMOTE_ADDR'] ?? 'unknown';
    }

    private function saveTrackingData(string $type, array $data): void
    {
        $logFile = __DIR__ . '/../../logs/tracking.log';
        $logEntry = date('Y-m-d H:i:s') . " [{$type}] " . json_encode($data) . PHP_EOL;

        if (!is_dir(dirname($logFile))) {
            mkdir(dirname($logFile), 0755, true);
        }

        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    private function saveUserBehavior(array $behaviorData): void
    {
        $file = $this->dataDir . '/user_behaviors.jsonl';
        $line = json_encode($behaviorData, JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    private function generateSessionId(Request $request): string
    {
        $ip = $this->getClientIp($request);
        $userAgent = $request->getHeaderLine('User-Agent');
        $timestamp = microtime(true);
        return 'sess_' . md5($ip . $userAgent . $timestamp);
    }

    public function getUserBehaviors(): array
    {
        $file = $this->dataDir . '/user_behaviors.jsonl';
        if (!file_exists($file)) {
            return [];
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $behaviors = [];

        foreach ($lines as $line) {
            $data = json_decode($line, true);
            if ($data) {
                $behaviors[] = $data;
            }
        }

        $groupedBySession = [];
        foreach ($behaviors as $behavior) {
            $sessionId = $behavior['session_id'];
            if (!isset($groupedBySession[$sessionId])) {
                $groupedBySession[$sessionId] = [];
            }
            $groupedBySession[$sessionId][] = $behavior;
        }

        return $groupedBySession;
    }

    public function getUserBehaviorsPaginated(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $page = isset($params['page']) ? max(1, (int)$params['page']) : 1;
        $perPage = isset($params['per_page']) ? max(1, min(50, (int)$params['per_page'])) : 10;

        $file = $this->dataDir . '/user_behaviors.jsonl';
        $behaviors = [];

        if (file_exists($file)) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $lines = array_reverse($lines);

            foreach ($lines as $line) {
                $data = json_decode($line, true);
                if ($data) {
                    $behaviors[] = $data;
                }
            }
        }

        $total = count($behaviors);
        $offset = ($page - 1) * $perPage;
        $paginatedData = array_slice($behaviors, $offset, $perPage);

        $result = [
            'data' => $paginatedData,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => (int)ceil($total / $perPage)
        ];

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getPageTrackingPaginated(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $page = isset($params['page']) ? max(1, (int)$params['page']) : 1;
        $perPage = isset($params['per_page']) ? max(1, min(50, (int)$params['per_page'])) : 10;

        $logFile = __DIR__ . '/../../logs/tracking.log';
        $trackingData = [];

        if (file_exists($logFile)) {
            $lines = file($logFile, FILE_IGNORE_NEW_LINES);
            $lines = array_reverse($lines);

            foreach ($lines as $line) {
                if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) \[uppage_track\] (.+)$/', $line, $matches)) {
                    $data = json_decode($matches[2], true);
                    if ($data) {
                        $data['created_at'] = $matches[1];
                        $trackingData[] = $data;
                    }
                }
            }
        }

        $total = count($trackingData);
        $offset = ($page - 1) * $perPage;
        $paginatedData = array_slice($trackingData, $offset, $perPage);

        $result = [
            'data' => $paginatedData,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => (int)ceil($total / $perPage)
        ];

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getAssignmentsPaginated(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $page = isset($params['page']) ? max(1, (int)$params['page']) : 1;
        $perPage = isset($params['per_page']) ? max(1, min(50, (int)$params['per_page'])) : 10;

        $file = $this->dataDir . '/assignments.jsonl';
        $assignments = [];

        if (file_exists($file)) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $lines = array_reverse($lines);

            foreach ($lines as $line) {
                $data = json_decode($line, true);
                if ($data) {
                    $assignments[] = $data;
                }
            }
        }

        $total = count($assignments);
        $offset = ($page - 1) * $perPage;
        $paginatedData = array_slice($assignments, $offset, $perPage);

        $result = [
            'data' => $paginatedData,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => (int)ceil($total / $perPage)
        ];

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }
}