<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use App\Utils\SupabaseClient;
use App\Utils\BotDetectionEngine;

class BotDetectionController
{
    private LoggerInterface $logger;
    private SupabaseClient $supabase;
    private BotDetectionEngine $engine;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->supabase = new SupabaseClient($logger);
        $this->engine = new BotDetectionEngine($logger);
    }

    public function analyzeUser(Request $request, Response $response): Response
    {
        try {
            $data = json_decode($request->getBody()->getContents(), true);

            $userData = [
                'session_id' => $data['session_id'] ?? '',
                'ip' => $this->getClientIp($request),
                'user_agent' => $request->getHeaderLine('User-Agent'),
                'referer' => $request->getHeaderLine('Referer'),
                'fingerprint_hash' => $data['fingerprint_hash'] ?? ''
            ];

            $userId = $this->findOrCreateUser($userData);

            $analysis = $this->engine->analyzeUser($userData);

            $this->saveAnalysis($userId, $analysis);

            $this->updateUserType($userId, $analysis['user_type']);

            $responseData = [
                'success' => true,
                'user_id' => $userId,
                'score' => $analysis['total_score'],
                'user_type' => $analysis['user_type'],
                'confidence' => $analysis['confidence'],
                'risk_level' => $analysis['detection_details']['risk_level']
            ];

            $response->getBody()->write(json_encode($responseData));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            $this->logger->error('Bot detection analysis error', ['error' => $e->getMessage()]);

            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Analysis failed'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    public function saveFingerprint(Request $request, Response $response): Response
    {
        try {
            $data = json_decode($request->getBody()->getContents(), true);

            $userId = $data['user_id'] ?? null;
            if (!$userId) {
                throw new \Exception('User ID required');
            }

            $fingerprintData = [
                'user_id' => $userId,
                'canvas_fingerprint' => $data['canvas'] ?? '',
                'webgl_fingerprint' => $data['webgl'] ?? '',
                'audio_fingerprint' => $data['audio'] ?? '',
                'fonts' => json_encode($data['fonts'] ?? []),
                'plugins' => json_encode($data['plugins'] ?? []),
                'touch_support' => $data['touch_support'] ?? false,
                'hardware_concurrency' => $data['hardware_concurrency'] ?? 0,
                'device_memory' => $data['device_memory'] ?? 0,
                'color_depth' => $data['color_depth'] ?? 0,
                'fingerprint_hash' => $data['fingerprint_hash'] ?? ''
            ];

            $this->supabase->from('user_fingerprints')->insert($fingerprintData);

            $response->getBody()->write(json_encode(['success' => true]));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            $this->logger->error('Save fingerprint error', ['error' => $e->getMessage()]);

            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    public function saveBehavior(Request $request, Response $response): Response
    {
        try {
            $data = json_decode($request->getBody()->getContents(), true);

            $userId = $data['user_id'] ?? null;
            if (!$userId) {
                throw new \Exception('User ID required');
            }

            $behaviorData = [
                'user_id' => $userId,
                'session_id' => $data['session_id'] ?? '',
                'page_url' => $data['page_url'] ?? '',
                'mouse_movements' => json_encode($data['mouse_movements'] ?? []),
                'click_events' => json_encode($data['click_events'] ?? []),
                'scroll_events' => json_encode($data['scroll_events'] ?? []),
                'keyboard_events' => json_encode($data['keyboard_events'] ?? []),
                'time_on_page' => $data['time_on_page'] ?? 0,
                'interaction_count' => $data['interaction_count'] ?? 0
            ];

            $this->supabase->from('user_behaviors')->insert($behaviorData);

            $response->getBody()->write(json_encode(['success' => true]));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            $this->logger->error('Save behavior error', ['error' => $e->getMessage()]);

            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    private function findOrCreateUser(array $userData): string
    {
        $sessionId = $userData['session_id'];
        $fingerprintHash = $userData['fingerprint_hash'];

        try {
            $existingUser = null;

            if (!empty($fingerprintHash)) {
                $existingUser = $this->supabase->from('users')
                    ->select('id')
                    ->eq('fingerprint_hash', $fingerprintHash)
                    ->maybeSingle();
            }

            if (!$existingUser && !empty($sessionId)) {
                $existingUser = $this->supabase->from('users')
                    ->select('id')
                    ->eq('session_id', $sessionId)
                    ->maybeSingle();
            }

            if ($existingUser) {
                $this->supabase->from('users')
                    ->update([
                        'last_visit_at' => date('Y-m-d H:i:s'),
                        'visit_count' => 'visit_count + 1'
                    ])
                    ->eq('id', $existingUser['id'])
                    ->execute();

                return $existingUser['id'];
            }

            $newUser = [
                'session_id' => $sessionId,
                'fingerprint_hash' => $fingerprintHash,
                'first_visit_at' => date('Y-m-d H:i:s'),
                'last_visit_at' => date('Y-m-d H:i:s'),
                'visit_count' => 1,
                'user_type' => 'suspicious'
            ];

            $result = $this->supabase->from('users')->insert($newUser)->single();

            $userId = $result['id'];

            $this->saveUserProfile($userId, $userData);

            return $userId;

        } catch (\Exception $e) {
            $this->logger->error('Find or create user error', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function saveUserProfile(string $userId, array $userData): void
    {
        try {
            $userAgent = $userData['user_agent'] ?? '';
            $parser = $this->parseUserAgent($userAgent);

            $profileData = [
                'user_id' => $userId,
                'ip_address' => $userData['ip'] ?? '',
                'device_type' => $parser['device_type'],
                'os' => $parser['os'],
                'browser' => $parser['browser'],
                'browser_version' => $parser['browser_version'],
                'user_agent' => $userAgent
            ];

            $this->supabase->from('user_profiles')->insert($profileData);

        } catch (\Exception $e) {
            $this->logger->error('Save user profile error', ['error' => $e->getMessage()]);
        }
    }

    private function saveAnalysis(string $userId, array $analysis): void
    {
        try {
            $scoreData = [
                'user_id' => $userId,
                'total_score' => $analysis['total_score'],
                'ip_score' => $analysis['scores']['ip_score'],
                'user_agent_score' => $analysis['scores']['user_agent_score'],
                'request_pattern_score' => $analysis['scores']['request_pattern_score'],
                'fingerprint_score' => $analysis['scores']['fingerprint_score'],
                'behavior_score' => $analysis['scores']['behavior_score'],
                'source_score' => $analysis['scores']['source_score'],
                'confidence' => $analysis['confidence'],
                'detection_details' => json_encode($analysis['detection_details'])
            ];

            $this->supabase->from('bot_detection_scores')->insert($scoreData);

        } catch (\Exception $e) {
            $this->logger->error('Save analysis error', ['error' => $e->getMessage()]);
        }
    }

    private function updateUserType(string $userId, string $userType): void
    {
        try {
            $this->supabase->from('users')
                ->update(['user_type' => $userType])
                ->eq('id', $userId)
                ->execute();
        } catch (\Exception $e) {
            $this->logger->error('Update user type error', ['error' => $e->getMessage()]);
        }
    }

    private function parseUserAgent(string $userAgent): array
    {
        $result = [
            'device_type' => 'unknown',
            'os' => 'unknown',
            'browser' => 'unknown',
            'browser_version' => ''
        ];

        $userAgent = strtolower($userAgent);

        if (preg_match('/mobile|android|iphone|ipad|ipod/', $userAgent)) {
            $result['device_type'] = 'mobile';
        } elseif (preg_match('/tablet/', $userAgent)) {
            $result['device_type'] = 'tablet';
        } else {
            $result['device_type'] = 'desktop';
        }

        if (preg_match('/windows/', $userAgent)) {
            $result['os'] = 'Windows';
        } elseif (preg_match('/mac os x/', $userAgent)) {
            $result['os'] = 'macOS';
        } elseif (preg_match('/linux/', $userAgent)) {
            $result['os'] = 'Linux';
        } elseif (preg_match('/android/', $userAgent)) {
            $result['os'] = 'Android';
        } elseif (preg_match('/iphone|ipad|ipod/', $userAgent)) {
            $result['os'] = 'iOS';
        }

        if (preg_match('/chrome\/(\d+)/', $userAgent, $matches)) {
            $result['browser'] = 'Chrome';
            $result['browser_version'] = $matches[1];
        } elseif (preg_match('/firefox\/(\d+)/', $userAgent, $matches)) {
            $result['browser'] = 'Firefox';
            $result['browser_version'] = $matches[1];
        } elseif (preg_match('/safari\/(\d+)/', $userAgent, $matches)) {
            $result['browser'] = 'Safari';
            $result['browser_version'] = $matches[1];
        } elseif (preg_match('/edg\/(\d+)/', $userAgent, $matches)) {
            $result['browser'] = 'Edge';
            $result['browser_version'] = $matches[1];
        }

        return $result;
    }

    private function getClientIp(Request $request): string
    {
        $serverParams = $request->getServerParams();

        if (!empty($serverParams['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $serverParams['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }

        if (!empty($serverParams['HTTP_X_REAL_IP'])) {
            return $serverParams['HTTP_X_REAL_IP'];
        }

        return $serverParams['REMOTE_ADDR'] ?? '';
    }
}
