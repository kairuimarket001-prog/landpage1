<?php

namespace App\Utils;

use Psr\Log\LoggerInterface;

class BotDetectionEngine
{
    private LoggerInterface $logger;
    private SupabaseClient $supabase;

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
        $this->supabase = new SupabaseClient($logger);
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

        if ($this->isKnownProxyIP($ipAddress)) {
            $score -= 8;
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

        if ($this->hasRegularIntervals($sessionId)) {
            $score -= 8;
        }

        if ($this->lacksTypicalUserBehavior($sessionId)) {
            $score -= 5;
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

        if (!$this->isFingerprintConsistent($userData)) {
            $score -= 12;
        }

        if ($this->isCommonBotFingerprint($fingerprintHash)) {
            $score -= 15;
        }

        if ($this->hasSuspiciousFingerprint($userData)) {
            $score -= 8;
        }

        return max(0, $score);
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

        if (!$this->hasMouseMovements($behaviors)) {
            $score -= 10;
        }

        if (!$this->hasNaturalClickPatterns($behaviors)) {
            $score -= 8;
        }

        if (!$this->hasScrollActivity($behaviors)) {
            $score -= 7;
        }

        if ($this->hasRoboticBehavior($behaviors)) {
            $score -= 12;
        }

        return max(0, $score);
    }

    private function analyzeSource(array $userData): int
    {
        $score = self::WEIGHTS['source'];
        $referer = $userData['referer'] ?? '';

        if (empty($referer)) {
            $score -= 3;
        } elseif ($this->isFromSearchEngine($referer)) {
            if (!$this->isValidSearchEngineReferer($userData)) {
                $score -= 8;
            }
        } elseif ($this->isSuspiciousReferer($referer)) {
            $score -= 5;
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
        try {
            $result = $this->supabase->from('ip_whitelist')
                ->select('id')
                ->eq('ip_address', $ip)
                ->or('expires_at.is.null,expires_at.gt.' . date('Y-m-d H:i:s'))
                ->maybeSingle();

            return $result !== null;
        } catch (\Exception $e) {
            $this->logger->error('Error checking IP whitelist', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function isBlacklistedIP(string $ip): bool
    {
        try {
            $result = $this->supabase->from('ip_blacklist')
                ->select('id')
                ->eq('ip_address', $ip)
                ->or('expires_at.is.null,expires_at.gt.' . date('Y-m-d H:i:s'))
                ->maybeSingle();

            return $result !== null;
        } catch (\Exception $e) {
            $this->logger->error('Error checking IP blacklist', ['error' => $e->getMessage()]);
            return false;
        }
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

    private function isKnownProxyIP(string $ip): bool
    {
        return false;
    }

    private function hasHighRequestFrequency(string $ip): bool
    {
        try {
            $fiveMinutesAgo = date('Y-m-d H:i:s', strtotime('-5 minutes'));
            $result = $this->supabase->from('user_profiles')
                ->select('id', ['count' => 'exact'])
                ->eq('ip_address', $ip)
                ->gte('created_at', $fiveMinutesAgo)
                ->execute();

            return ($result['count'] ?? 0) > 50;
        } catch (\Exception $e) {
            return false;
        }
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
        try {
            $result = $this->supabase->from('user_behaviors')
                ->select('id', ['count' => 'exact'])
                ->eq('session_id', $sessionId)
                ->execute();

            return $result['count'] ?? 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function getSessionTimeSpan(string $sessionId): int
    {
        try {
            $result = $this->supabase->from('user_behaviors')
                ->select('created_at')
                ->eq('session_id', $sessionId)
                ->order('created_at', ['ascending' => true])
                ->limit(1)
                ->maybeSingle();

            if ($result) {
                $firstVisit = strtotime($result['created_at']);
                return time() - $firstVisit;
            }
            return 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function hasRegularIntervals(string $sessionId): bool
    {
        return false;
    }

    private function lacksTypicalUserBehavior(string $sessionId): bool
    {
        return false;
    }

    private function isWhitelistedFingerprint(string $fingerprintHash): bool
    {
        try {
            $result = $this->supabase->from('fingerprint_whitelist')
                ->select('id')
                ->eq('fingerprint_hash', $fingerprintHash)
                ->or('expires_at.is.null,expires_at.gt.' . date('Y-m-d H:i:s'))
                ->maybeSingle();

            return $result !== null;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function isFingerprintConsistent(array $userData): bool
    {
        return true;
    }

    private function isCommonBotFingerprint(string $fingerprintHash): bool
    {
        return false;
    }

    private function hasSuspiciousFingerprint(array $userData): bool
    {
        return false;
    }

    private function getUserBehaviors(string $sessionId): array
    {
        try {
            $result = $this->supabase->from('user_behaviors')
                ->select('*')
                ->eq('session_id', $sessionId)
                ->execute();

            return $result['data'] ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }

    private function hasMouseMovements(array $behaviors): bool
    {
        foreach ($behaviors as $behavior) {
            $movements = $behavior['mouse_movements'] ?? [];
            if (!empty($movements)) {
                return true;
            }
        }
        return false;
    }

    private function hasNaturalClickPatterns(array $behaviors): bool
    {
        foreach ($behaviors as $behavior) {
            $clicks = $behavior['click_events'] ?? [];
            if (!empty($clicks)) {
                return true;
            }
        }
        return false;
    }

    private function hasScrollActivity(array $behaviors): bool
    {
        foreach ($behaviors as $behavior) {
            $scrolls = $behavior['scroll_events'] ?? [];
            if (!empty($scrolls)) {
                return true;
            }
        }
        return false;
    }

    private function hasRoboticBehavior(array $behaviors): bool
    {
        return false;
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

    private function isValidSearchEngineReferer(array $userData): bool
    {
        return true;
    }

    private function isSuspiciousReferer(string $referer): bool
    {
        return false;
    }
}
