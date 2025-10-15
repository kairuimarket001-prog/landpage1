<?php
declare(strict_types=1);

namespace App\Utils;

class SupabaseClient
{
    private string $url;
    private string $anonKey;

    public function __construct()
    {
        $this->url = $_ENV['VITE_SUPABASE_URL'] ?? '';
        $this->anonKey = $_ENV['VITE_SUPABASE_SUPABASE_ANON_KEY'] ?? '';

        if (empty($this->url) || empty($this->anonKey)) {
            throw new \Exception('Supabase credentials not found in environment variables');
        }
    }

    public function insert(string $table, array $data): array
    {
        $url = $this->url . '/rest/v1/' . $table;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'apikey: ' . $this->anonKey,
                'Authorization: Bearer ' . $this->anonKey,
                'Content-Type: application/json',
                'Prefer: return=representation'
            ],
            CURLOPT_POSTFIELDS => json_encode($data)
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response, true) ?? [];
        }

        throw new \Exception('Supabase insert failed: ' . $response);
    }

    public function select(string $table, array $params = []): array
    {
        $url = $this->url . '/rest/v1/' . $table;

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'apikey: ' . $this->anonKey,
                'Authorization: Bearer ' . $this->anonKey,
                'Content-Type: application/json'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response, true) ?? [];
        }

        throw new \Exception('Supabase select failed: ' . $response);
    }

    public function query(string $table, array $filters = [], string $order = 'created_at.desc', int $limit = 100, int $offset = 0): array
    {
        $url = $this->url . '/rest/v1/' . $table;

        $queryParams = [];

        foreach ($filters as $key => $value) {
            if (is_array($value)) {
                $operator = $value['operator'] ?? 'eq';
                $queryParams[$key] = $operator . '.' . $value['value'];
            } else {
                $queryParams[$key] = 'eq.' . $value;
            }
        }

        $queryParams['order'] = $order;
        $queryParams['limit'] = (string)$limit;
        $queryParams['offset'] = (string)$offset;

        $url .= '?' . http_build_query($queryParams);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'apikey: ' . $this->anonKey,
                'Authorization: Bearer ' . $this->anonKey,
                'Content-Type: application/json',
                'Prefer: count=exact'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headers = curl_getinfo($ch);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            $data = json_decode($response, true) ?? [];
            return [
                'data' => $data,
                'count' => count($data)
            ];
        }

        throw new \Exception('Supabase query failed: ' . $response);
    }

    public function getUserBehaviorsBySession(string $sessionId): array
    {
        return $this->query('user_behaviors', ['session_id' => $sessionId], 'created_at.asc');
    }

    public function getAllSessions(int $limit = 10, int $offset = 0): array
    {
        $url = $this->url . '/rest/v1/rpc/get_unique_sessions';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'apikey: ' . $this->anonKey,
                'Authorization: Bearer ' . $this->anonKey,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'limit_count' => $limit,
                'offset_count' => $offset
            ])
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response, true) ?? [];
        }

        $allBehaviors = $this->query('user_behaviors', [], 'created_at.desc', 1000, 0);

        $sessions = [];
        $seen = [];

        foreach ($allBehaviors['data'] as $behavior) {
            $sessionId = $behavior['session_id'];
            if (!isset($seen[$sessionId])) {
                $seen[$sessionId] = true;
                $sessions[] = $sessionId;
            }
        }

        return array_slice($sessions, $offset, $limit);
    }

    public function countUniqueSessions(): int
    {
        $allBehaviors = $this->query('user_behaviors', [], 'created_at.desc', 10000, 0);

        $uniqueSessions = [];
        foreach ($allBehaviors['data'] as $behavior) {
            $uniqueSessions[$behavior['session_id']] = true;
        }

        return count($uniqueSessions);
    }
}
