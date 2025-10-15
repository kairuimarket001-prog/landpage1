<?php

namespace App\Utils;

use Psr\Log\LoggerInterface;

class BotDetectionEngine
{
    private LoggerInterface $logger;
    private string $dataDir;

    private const WEIGHTS = [
        'ip' => 20,
        'user_agent' => 15,
        'request_pattern' => 15,
        'fingerprint' => 20,
        'behavior' => 20,
        'source' => 10
    ];

    private const KNOWN_BOT_PATTERNS = [
        'bot', 'crawler', 'spider', 'scraper', 'curl', 'wget', 'python-requests',
        'java/', 'go-http-client', 'http_request', 'axios', 'okhttp', 'httpclient'
    ];

    private const DATACENTER_IP_RANGES = [
        '52.', '54.', '18.', '3.', '13.', '34.',
        '35.', '104.', '130.', '142.', '146.'
    ];

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->dataDir = __DIR__ . '/../../data';
    }

    public function analyzeUser(array $userData): array
    {
        $scores = [
            'ip_score' => $this->analyzeIP($userData),
            'user_agent_score' => $this->analyzeUserAgent($userData),
            'request_pattern_score' => $this->analyzeRequestPattern($userData),
            'fingerprint_score' => $this->analyzeFingerprint($userData),
            'behavior_score' => $this->analyzeBehavior($userData),
            'source_score' => $this->analyzeSource($userData)
        ];

        $totalScore = array_sum($scores);
        $confidence = $this->calculateConfidence($scores, $userData);
        $userType = $this->determineUserType($totalScore, $confidence);

        $detectionDetails = [
            'timestamp' => date('Y-m-d H:i:s'),
            'scores_breakdown' => $scores,
            'flags' => $this->getDetectionFlags($userData, $scores),
            'risk_level' => $this->getRiskLevel($totalScore)
        ];

        return [
            'total_score' => $totalScore,
            'scores' => $scores,
            'confidence' => $confidence,
            'user_type' => $userType,
            'detection_details' => $detectionDetails
        ];
    }

    private function analyzeIP(array $userData): int
    {
        $score = self::WEIGHTS['ip'];
        $ipAddress = $userData['ip'] ?? '';

        if (empty($ipAddress)) {
            return 0;
        }

        if ($this->isWhitelistedIP($ipAddress)) {
            return $score;
        }

        if ($this->isBlacklistedIP($ipAddress)) {
            return 0;
        }

        if ($this->isDatacenterIP($ipAddress)) {
            $score -= 10;
        }

        if ($this->hasHighRequestFrequency($ipAddress)) {
            $score -= 5;
        }

        return max(0, $score);
    }

    private function analyzeUserAgent(array $userData): int
    {
        $score = self::WEIGHTS['user_agent'];
        $userAgent = strtolower($userData['user_agent'] ?? '');

        if (empty($userAgent)) {
            return 0;
        }

        foreach (self::KNOWN_BOT_PATTERNS as $pattern) {
            if (strpos($userAgent, $pattern) !== false) {
                return 0;
            }
        }

        if (!$this->hasValidBrowserSignature($userAgent)) {
            $score -= 8;
        }

        if ($this->isOutdatedUserAgent($userAgent)) {
            $score -= 5;
        }

        if ($this->isSuspiciousUserAgent($userAgent)) {
            $score -= 7;
        }

        return max(0, $score);
    }

    private function analyzeRequestPattern(array $userData): int
    {
        $score = self::WEIGHTS['request_pattern'];
        $sessionId = $userData['session_id'] ?? '';

        if (empty($sessionId)) {
            return max(0, $score - 5);
        }

        $requestCount = $this->getSessionRequestCount($sessionId);
        $timeSpan = $this->getSessionTimeSpan($sessionId);

        if ($requestCount > 10 && $timeSpan < 5) {
            $score -= 10;
        }

        return max(0, $score);
    }

    private function analyzeFingerprint(array $userData): int
    {
        $score = self::WEIGHTS['fingerprint'];
        $fingerprintHash = $userData['fingerprint_hash'] ?? '';

        if (empty($fingerprintHash)) {
            return max(0, $score - 10);
        }

        if ($this->isWhitelistedFingerprint($fingerprintHash)) {
            return $score;
        }

        return $score;
    }

    private function analyzeBehavior(array $userData): int
    {
        $score = self::WEIGHTS['behavior'];
        $sessionId = $userData['session_id'] ?? '';

        if (empty($sessionId)) {
            return max(0, $score - 10);
        }

        $behaviors = $this->getUserBehaviors($sessionId);

        if (empty($behaviors)) {
            return max(0, $score - 15);
        }

        $hasMouseMovements = false;
        $hasClicks = false;
        $hasScrolls = false;

        foreach ($behaviors as $behavior) {
            if (!empty($behavior['mouse_movements'])) {
                $hasMouseMovements = true;
            }
            if (!empty($behavior['click_events'])) {
                $hasClicks = true;
            }
            if (!empty($behavior['scroll_events'])) {
                $hasScrolls = true;
            }
        }

        if (!$hasMouseMovements) $score -= 10;
        if (!$hasClicks) $score -= 8;
        if (!$hasScrolls) $score -= 7;

        return max(0, $score);
    }

    private function analyzeSource(array $userData): int
    {
        $score = self::WEIGHTS['source'];
        $referer = $userData['referer'] ?? '';

        if (empty($referer)) {
            $score -= 3;
        } elseif ($this->isFromSearchEngine($referer)) {
            $score += 0;
        }

        return max(0, $score);
    }

    private function calculateConfidence(array $scores, array $userData): float
    {
        $hasFingerprint = !empty($userData['fingerprint_hash']);
        $hasBehavior = !empty($userData['session_id']);
        $dataCompleteness = 0;

        $dataCompleteness += $hasFingerprint ? 0.3 : 0;
        $dataCompleteness += $hasBehavior ? 0.3 : 0;
        $dataCompleteness += !empty($userData['ip']) ? 0.2 : 0;
        $dataCompleteness += !empty($userData['user_agent']) ? 0.2 : 0;

        $scoreVariance = $this->calculateScoreVariance($scores);
        $consistencyFactor = 1 - ($scoreVariance / 100);

        $confidence = $dataCompleteness * $consistencyFactor;

        return round($confidence, 2);
    }

    private function calculateScoreVariance(array $scores): float
    {
        if (empty($scores)) {
            return 0;
        }

        $normalizedScores = [];
        foreach ($scores as $key => $score) {
            $weight = self::WEIGHTS[str_replace('_score', '', $key)] ?? 1;
            $normalizedScores[] = ($score / $weight) * 100;
        }

        $mean = array_sum($normalizedScores) / count($normalizedScores);
        $variance = 0;

        foreach ($normalizedScores as $value) {
            $variance += pow($value - $mean, 2);
        }

        return sqrt($variance / count($normalizedScores));
    }

    private function determineUserType(int $totalScore, float $confidence): string
    {
        if ($totalScore >= 80 && $confidence >= 0.7) {
            return 'human';
        } elseif ($totalScore >= 60 && $confidence >= 0.5) {
            return 'suspicious';
        } elseif ($totalScore >= 40) {
            return 'suspicious';
        } elseif ($totalScore >= 20) {
            return 'bot';
        } else {
            return 'high_risk';
        }
    }

    private function getDetectionFlags(array $userData, array $scores): array
    {
        $flags = [];

        if ($scores['ip_score'] < 10) {
            $flags[] = 'suspicious_ip';
        }
        if ($scores['user_agent_score'] < 8) {
            $flags[] = 'suspicious_user_agent';
        }
        if ($scores['fingerprint_score'] < 10) {
            $flags[] = 'suspicious_fingerprint';
        }
        if ($scores['behavior_score'] < 10) {
            $flags[] = 'no_human_behavior';
        }
        if ($scores['request_pattern_score'] < 8) {
            $flags[] = 'automated_pattern';
        }

        return $flags;
    }

    private function getRiskLevel(int $totalScore): string
    {
        if ($totalScore >= 80) return 'low';
        if ($totalScore >= 60) return 'medium';
        if ($totalScore >= 40) return 'high';
        return 'critical';
    }

    private function isWhitelistedIP(string $ip): bool
    {
        $whitelist = $this->loadBlacklist('ip_whitelist.json');
        foreach ($whitelist as $entry) {
            if ($entry['ip'] === $ip) {
                if (empty($entry['expires_at']) || strtotime($entry['expires_at']) > time()) {
                    return true;
                }
            }
        }
        return false;
    }

    private function isBlacklistedIP(string $ip): bool
    {
        $blacklist = $this->loadBlacklist('ip_blacklist.json');
        foreach ($blacklist as $entry) {
            if ($entry['ip'] === $ip) {
                if (empty($entry['expires_at']) || strtotime($entry['expires_at']) > time()) {
                    return true;
                }
            }
        }
        return false;
    }

    private function isWhitelistedFingerprint(string $fingerprintHash): bool
    {
        $whitelist = $this->loadBlacklist('fingerprint_whitelist.json');
        foreach ($whitelist as $entry) {
            if ($entry['fingerprint_hash'] === $fingerprintHash) {
                if (empty($entry['expires_at']) || strtotime($entry['expires_at']) > time()) {
                    return true;
                }
            }
        }
        return false;
    }

    private function loadBlacklist(string $filename): array
    {
        $file = $this->dataDir . '/' . $filename;
        if (!file_exists($file)) {
            return [];
        }
        $content = file_get_contents($file);
        return json_decode($content, true) ?? [];
    }

    private function isDatacenterIP(string $ip): bool
    {
        foreach (self::DATACENTER_IP_RANGES as $range) {
            if (strpos($ip, $range) === 0) {
                return true;
            }
        }
        return false;
    }

    private function hasHighRequestFrequency(string $ip): bool
    {
        $file = $this->dataDir . '/user_profiles.jsonl';
        if (!file_exists($file)) {
            return false;
        }

        $fiveMinutesAgo = time() - 300;
        $count = 0;

        $handle = fopen($file, 'r');
        while (($line = fgets($handle)) !== false) {
            $data = json_decode($line, true);
            if ($data && $data['ip'] === $ip) {
                $createdAt = strtotime($data['created_at'] ?? '');
                if ($createdAt > $fiveMinutesAgo) {
                    $count++;
                }
            }
        }
        fclose($handle);

        return $count > 50;
    }

    private function hasValidBrowserSignature(string $userAgent): bool
    {
        $validBrowsers = ['chrome', 'firefox', 'safari', 'edge', 'opera'];
        foreach ($validBrowsers as $browser) {
            if (strpos($userAgent, $browser) !== false) {
                return true;
            }
        }
        return false;
    }

    private function isOutdatedUserAgent(string $userAgent): bool
    {
        if (preg_match('/chrome\/(\d+)/i', $userAgent, $matches)) {
            return intval($matches[1]) < 90;
        }
        return false;
    }

    private function isSuspiciousUserAgent(string $userAgent): bool
    {
        if (strlen($userAgent) < 20) {
            return true;
        }
        if (!preg_match('/mozilla|gecko|webkit/i', $userAgent)) {
            return true;
        }
        return false;
    }

    private function getSessionRequestCount(string $sessionId): int
    {
        $file = $this->dataDir . '/user_behaviors.jsonl';
        if (!file_exists($file)) {
            return 0;
        }

        $count = 0;
        $handle = fopen($file, 'r');
        while (($line = fgets($handle)) !== false) {
            $data = json_decode($line, true);
            if ($data && ($data['session_id'] ?? '') === $sessionId) {
                $count++;
            }
        }
        fclose($handle);

        return $count;
    }

    private function getSessionTimeSpan(string $sessionId): int
    {
        $file = $this->dataDir . '/user_behaviors.jsonl';
        if (!file_exists($file)) {
            return 0;
        }

        $firstTimestamp = null;
        $handle = fopen($file, 'r');
        while (($line = fgets($handle)) !== false) {
            $data = json_decode($line, true);
            if ($data && ($data['session_id'] ?? '') === $sessionId) {
                $timestamp = strtotime($data['created_at'] ?? '');
                if ($firstTimestamp === null) {
                    $firstTimestamp = $timestamp;
                }
            }
        }
        fclose($handle);

        return $firstTimestamp ? (time() - $firstTimestamp) : 0;
    }

    private function getUserBehaviors(string $sessionId): array
    {
        $file = $this->dataDir . '/user_behaviors.jsonl';
        if (!file_exists($file)) {
            return [];
        }

        $behaviors = [];
        $handle = fopen($file, 'r');
        while (($line = fgets($handle)) !== false) {
            $data = json_decode($line, true);
            if ($data && ($data['session_id'] ?? '') === $sessionId) {
                $behaviors[] = $data;
            }
        }
        fclose($handle);

        return $behaviors;
    }

    private function isFromSearchEngine(string $referer): bool
    {
        $searchEngines = ['google.com', 'bing.com', 'yahoo.com', 'baidu.com', 'duckduckgo.com'];
        foreach ($searchEngines as $engine) {
            if (strpos($referer, $engine) !== false) {
                return true;
            }
        }
        return false;
    }
}
