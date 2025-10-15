<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use App\Utils\SupabaseClient;

class UserMonitoringController
{
    private LoggerInterface $logger;
    private SupabaseClient $supabase;
    private string $adminPassword;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->supabase = new SupabaseClient($logger);
        $this->adminPassword = $_ENV['ADMIN_PASSWORD'] ?? 'admin123';
    }

    private function requireAuth(Request $request, Response $response): ?Response
    {
        $cookies = $request->getCookieParams();
        $sessionToken = $cookies['admin_session'] ?? '';

        if ($sessionToken !== hash('sha256', $this->adminPassword)) {
            return $response
                ->withHeader('Location', '/admin/login')
                ->withStatus(302);
        }

        return null;
    }

    public function dashboard(Request $request, Response $response): Response
    {
        $authResponse = $this->requireAuth($request, $response);
        if ($authResponse) return $authResponse;

        $html = $this->renderDashboard();
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function apiUsersList(Request $request, Response $response): Response
    {
        $authResponse = $this->requireAuth($request, $response);
        if ($authResponse) return $authResponse;

        try {
            $queryParams = $request->getQueryParams();
            $page = isset($queryParams['page']) ? (int)$queryParams['page'] : 1;
            $perPage = isset($queryParams['per_page']) ? (int)$queryParams['per_page'] : 50;
            $userType = $queryParams['user_type'] ?? '';
            $minScore = isset($queryParams['min_score']) ? (int)$queryParams['min_score'] : 0;
            $maxScore = isset($queryParams['max_score']) ? (int)$queryParams['max_score'] : 100;

            $offset = ($page - 1) * $perPage;

            $query = $this->supabase->from('users')
                ->select('*, user_profiles(*), bot_detection_scores(*)', ['count' => 'exact'])
                ->order('last_visit_at', ['ascending' => false])
                ->range($offset, $offset + $perPage - 1);

            if (!empty($userType)) {
                $query->eq('user_type', $userType);
            }

            $result = $query->execute();

            $users = $result['data'] ?? [];
            $total = $result['count'] ?? 0;

            $enrichedUsers = array_map(function($user) use ($minScore, $maxScore) {
                $latestScore = !empty($user['bot_detection_scores'])
                    ? $user['bot_detection_scores'][0]
                    : null;

                $score = $latestScore ? $latestScore['total_score'] : 0;

                if ($score < $minScore || $score > $maxScore) {
                    return null;
                }

                return [
                    'user_id' => $user['id'],
                    'session_id' => $user['session_id'],
                    'user_type' => $user['user_type'],
                    'score' => $score,
                    'confidence' => $latestScore ? $latestScore['confidence'] : 0,
                    'first_visit' => $user['first_visit_at'],
                    'last_visit' => $user['last_visit_at'],
                    'visit_count' => $user['visit_count'],
                    'is_whitelisted' => $user['is_whitelisted'],
                    'is_blacklisted' => $user['is_blacklisted'],
                    'profile' => !empty($user['user_profiles']) ? $user['user_profiles'][0] : null
                ];
            }, $users);

            $enrichedUsers = array_filter($enrichedUsers);

            $responseData = [
                'success' => true,
                'users' => array_values($enrichedUsers),
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage
            ];

            $response->getBody()->write(json_encode($responseData));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            $this->logger->error('Get users list error', ['error' => $e->getMessage()]);

            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    public function apiUserDetail(Request $request, Response $response, array $args): Response
    {
        $authResponse = $this->requireAuth($request, $response);
        if ($authResponse) return $authResponse;

        try {
            $userId = $args['userId'] ?? '';

            $user = $this->supabase->from('users')
                ->select('*')
                ->eq('id', $userId)
                ->maybeSingle();

            if (!$user) {
                throw new \Exception('User not found');
            }

            $profile = $this->supabase->from('user_profiles')
                ->select('*')
                ->eq('user_id', $userId)
                ->order('created_at', ['ascending' => false])
                ->limit(1)
                ->maybeSingle();

            $fingerprint = $this->supabase->from('user_fingerprints')
                ->select('*')
                ->eq('user_id', $userId)
                ->order('created_at', ['ascending' => false])
                ->limit(1)
                ->maybeSingle();

            $scores = $this->supabase->from('bot_detection_scores')
                ->select('*')
                ->eq('user_id', $userId)
                ->order('created_at', ['ascending' => false'])
                ->limit(10)
                ->execute();

            $trafficSource = $this->supabase->from('traffic_sources')
                ->select('*')
                ->eq('user_id', $userId)
                ->order('created_at', ['ascending' => false'])
                ->limit(1)
                ->maybeSingle();

            $assignments = $this->supabase->from('customer_service_assignments')
                ->select('*')
                ->eq('user_id', $userId)
                ->order('created_at', ['ascending' => false'])
                ->limit(5)
                ->execute();

            $responseData = [
                'success' => true,
                'user' => $user,
                'profile' => $profile,
                'fingerprint' => $fingerprint,
                'scores' => $scores['data'] ?? [],
                'traffic_source' => $trafficSource,
                'assignments' => $assignments['data'] ?? []
            ];

            $response->getBody()->write(json_encode($responseData));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            $this->logger->error('Get user detail error', ['error' => $e->getMessage()]);

            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    public function apiUpdateUser(Request $request, Response $response, array $args): Response
    {
        $authResponse = $this->requireAuth($request, $response);
        if ($authResponse) return $authResponse;

        try {
            $userId = $args['userId'] ?? '';
            $data = json_decode($request->getBody()->getContents(), true);

            $updateData = [];
            if (isset($data['user_type'])) {
                $updateData['user_type'] = $data['user_type'];
                $updateData['manual_override'] = true;
            }
            if (isset($data['notes'])) {
                $updateData['notes'] = $data['notes'];
            }
            if (isset($data['is_whitelisted'])) {
                $updateData['is_whitelisted'] = $data['is_whitelisted'];
            }
            if (isset($data['is_blacklisted'])) {
                $updateData['is_blacklisted'] = $data['is_blacklisted'];
            }

            if (!empty($updateData)) {
                $this->supabase->from('users')
                    ->update($updateData)
                    ->eq('id', $userId)
                    ->execute();
            }

            $response->getBody()->write(json_encode(['success' => true]));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            $this->logger->error('Update user error', ['error' => $e->getMessage()]);

            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    public function apiStatistics(Request $request, Response $response): Response
    {
        $authResponse = $this->requireAuth($request, $response);
        if ($authResponse) return $authResponse;

        try {
            $totalUsers = $this->supabase->from('users')
                ->select('id', ['count' => 'exact'])
                ->execute();

            $humanUsers = $this->supabase->from('users')
                ->select('id', ['count' => 'exact'])
                ->eq('user_type', 'human')
                ->execute();

            $botUsers = $this->supabase->from('users')
                ->select('id', ['count' => 'exact'])
                ->in('user_type', ['bot', 'high_risk'])
                ->execute();

            $suspiciousUsers = $this->supabase->from('users')
                ->select('id', ['count' => 'exact'])
                ->eq('user_type', 'suspicious')
                ->execute();

            $last24Hours = date('Y-m-d H:i:s', strtotime('-24 hours'));
            $recentUsers = $this->supabase->from('users')
                ->select('id', ['count' => 'exact'])
                ->gte('created_at', $last24Hours)
                ->execute();

            $responseData = [
                'success' => true,
                'total_users' => $totalUsers['count'] ?? 0,
                'human_users' => $humanUsers['count'] ?? 0,
                'bot_users' => $botUsers['count'] ?? 0,
                'suspicious_users' => $suspiciousUsers['count'] ?? 0,
                'recent_users_24h' => $recentUsers['count'] ?? 0,
                'bot_percentage' => $totalUsers['count'] > 0
                    ? round(($botUsers['count'] / $totalUsers['count']) * 100, 2)
                    : 0
            ];

            $response->getBody()->write(json_encode($responseData));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            $this->logger->error('Get statistics error', ['error' => $e->getMessage()]);

            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    private function renderDashboard(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>用户监控中心</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; background: #f5f5f5; }

        .navbar { background: #1a1a1a; color: white; padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .navbar h1 { font-size: 1.5rem; font-weight: 600; }
        .navbar a { color: white; text-decoration: none; margin-left: 1.5rem; transition: opacity 0.2s; }
        .navbar a:hover { opacity: 0.8; }

        .container { max-width: 1400px; margin: 2rem auto; padding: 0 2rem; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
        .stat-card { background: white; padding: 1.5rem; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .stat-label { font-size: 0.875rem; color: #666; margin-bottom: 0.5rem; }
        .stat-value { font-size: 2rem; font-weight: 700; color: #1a1a1a; }
        .stat-card.human { border-left: 4px solid #10b981; }
        .stat-card.bot { border-left: 4px solid #ef4444; }
        .stat-card.suspicious { border-left: 4px solid #f59e0b; }

        .card { background: white; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 2rem; }
        .card-header { padding: 1.5rem; border-bottom: 1px solid #e5e5e5; display: flex; justify-content: space-between; align-items: center; }
        .card-title { font-size: 1.25rem; font-weight: 600; }
        .card-body { padding: 1.5rem; }

        .filters { display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .filter-group { display: flex; flex-direction: column; gap: 0.5rem; }
        .filter-label { font-size: 0.875rem; color: #666; }
        .filter-input, .filter-select { padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px; font-size: 0.875rem; }
        .filter-button { padding: 0.5rem 1rem; background: #2563eb; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 0.875rem; transition: background 0.2s; }
        .filter-button:hover { background: #1d4ed8; }

        .table-container { overflow-x: auto; }
        .table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        .table th { text-align: left; padding: 0.75rem; background: #f9fafb; font-weight: 600; color: #374151; border-bottom: 2px solid #e5e7eb; white-space: nowrap; }
        .table td { padding: 0.75rem; border-bottom: 1px solid #e5e7eb; }
        .table tr:hover { background: #f9fafb; }

        .user-id { font-family: monospace; font-size: 0.75rem; color: #6b7280; }
        .badge { display: inline-block; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; }
        .badge.human { background: #d1fae5; color: #065f46; }
        .badge.bot { background: #fee2e2; color: #991b1b; }
        .badge.suspicious { background: #fef3c7; color: #92400e; }
        .badge.high_risk { background: #fecaca; color: #7f1d1d; }

        .score { font-weight: 700; font-size: 1.125rem; }
        .score.high { color: #10b981; }
        .score.medium { color: #f59e0b; }
        .score.low { color: #ef4444; }

        .tooltip { position: relative; cursor: help; }
        .tooltip:hover::after { content: attr(data-tooltip); position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%); background: #1f2937; color: white; padding: 0.5rem; border-radius: 4px; font-size: 0.75rem; white-space: nowrap; z-index: 1000; margin-bottom: 0.5rem; }

        .pagination { display: flex; justify-content: center; gap: 0.5rem; margin-top: 1.5rem; }
        .pagination button { padding: 0.5rem 1rem; border: 1px solid #d1d5db; background: white; border-radius: 4px; cursor: pointer; transition: all 0.2s; }
        .pagination button:hover:not(:disabled) { background: #f3f4f6; }
        .pagination button:disabled { opacity: 0.5; cursor: not-allowed; }
        .pagination button.active { background: #2563eb; color: white; border-color: #2563eb; }

        .loading { text-align: center; padding: 2rem; color: #6b7280; }

        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; }
        .modal.show { display: flex; }
        .modal-content { background: white; border-radius: 8px; padding: 2rem; max-width: 600px; width: 90%; max-height: 80vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .modal-title { font-size: 1.5rem; font-weight: 600; }
        .modal-close { background: none; border: none; font-size: 1.5rem; cursor: pointer; }
        .modal-section { margin-bottom: 1.5rem; }
        .modal-section-title { font-weight: 600; margin-bottom: 0.5rem; color: #374151; }
        .info-grid { display: grid; grid-template-columns: 120px 1fr; gap: 0.5rem; font-size: 0.875rem; }
        .info-label { color: #6b7280; }
        .info-value { color: #1f2937; font-weight: 500; }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>用户监控中心</h1>
        <div>
            <a href="/admin/dashboard">仪表板</a>
            <a href="/admin/customer-services">客服管理</a>
            <a href="/admin/tracking">追踪数据</a>
            <a href="/admin/user-monitoring">用户监控</a>
            <a href="/admin/logout">退出</a>
        </div>
    </nav>

    <div class="container">
        <div class="stats-grid" id="stats-grid">
            <div class="stat-card">
                <div class="stat-label">总用户数</div>
                <div class="stat-value" id="total-users">-</div>
            </div>
            <div class="stat-card human">
                <div class="stat-label">人类用户</div>
                <div class="stat-value" id="human-users">-</div>
            </div>
            <div class="stat-card bot">
                <div class="stat-label">机器人</div>
                <div class="stat-value" id="bot-users">-</div>
            </div>
            <div class="stat-card suspicious">
                <div class="stat-label">可疑用户</div>
                <div class="stat-value" id="suspicious-users">-</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">24小时新增</div>
                <div class="stat-value" id="recent-users">-</div>
            </div>
            <div class="stat-card bot">
                <div class="stat-label">机器人占比</div>
                <div class="stat-value" id="bot-percentage">-</div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">用户列表</h2>
            </div>
            <div class="card-body">
                <div class="filters">
                    <div class="filter-group">
                        <label class="filter-label">用户类型</label>
                        <select class="filter-select" id="filter-user-type">
                            <option value="">全部</option>
                            <option value="human">人类</option>
                            <option value="suspicious">可疑</option>
                            <option value="bot">机器人</option>
                            <option value="high_risk">高风险</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">最低评分</label>
                        <input type="number" class="filter-input" id="filter-min-score" placeholder="0" min="0" max="100">
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">最高评分</label>
                        <input type="number" class="filter-input" id="filter-max-score" placeholder="100" min="0" max="100">
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">&nbsp;</label>
                        <button class="filter-button" onclick="applyFilters()">筛选</button>
                    </div>
                </div>

                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>用户ID</th>
                                <th>用户资料</th>
                                <th>判断来源</th>
                                <th>评分</th>
                                <th>类型</th>
                                <th>访问次数</th>
                                <th>最后访问</th>
                            </tr>
                        </thead>
                        <tbody id="users-table-body">
                            <tr><td colspan="7" class="loading">加载中...</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="pagination" id="pagination"></div>
            </div>
        </div>
    </div>

    <div class="modal" id="user-detail-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">用户详情</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div id="modal-body"></div>
        </div>
    </div>

    <script>
        let currentPage = 1;
        let totalPages = 1;

        async function loadStatistics() {
            try {
                const response = await fetch('/admin/api/user-monitoring/statistics');
                const data = await response.json();

                if (data.success) {
                    document.getElementById('total-users').textContent = data.total_users;
                    document.getElementById('human-users').textContent = data.human_users;
                    document.getElementById('bot-users').textContent = data.bot_users;
                    document.getElementById('suspicious-users').textContent = data.suspicious_users;
                    document.getElementById('recent-users').textContent = data.recent_users_24h;
                    document.getElementById('bot-percentage').textContent = data.bot_percentage + '%';
                }
            } catch (error) {
                console.error('Error loading statistics:', error);
            }
        }

        async function loadUsers(page = 1) {
            try {
                const userType = document.getElementById('filter-user-type').value;
                const minScore = document.getElementById('filter-min-score').value || 0;
                const maxScore = document.getElementById('filter-max-score').value || 100;

                const params = new URLSearchParams({
                    page: page,
                    per_page: 50,
                    user_type: userType,
                    min_score: minScore,
                    max_score: maxScore
                });

                const response = await fetch(`/admin/api/user-monitoring/users?${params}`);
                const data = await response.json();

                if (data.success) {
                    renderUsers(data.users);
                    renderPagination(page, data.total, data.per_page);
                }
            } catch (error) {
                console.error('Error loading users:', error);
                document.getElementById('users-table-body').innerHTML = '<tr><td colspan="7" class="loading">加载失败</td></tr>';
            }
        }

        function renderUsers(users) {
            const tbody = document.getElementById('users-table-body');

            if (users.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="loading">暂无数据</td></tr>';
                return;
            }

            tbody.innerHTML = users.map(user => {
                const scoreClass = user.score >= 70 ? 'high' : user.score >= 40 ? 'medium' : 'low';
                const profile = user.profile || {};

                const tooltipText = `IP: ${profile.ip_address || 'N/A'} | 设备: ${profile.device_type || 'N/A'} | 系统: ${profile.os || 'N/A'} | 浏览器: ${profile.browser || 'N/A'}`;

                const source = profile.ip_address ? `IP: ${profile.ip_address.substring(0, 12)}...` : 'N/A';

                return `
                    <tr onclick="showUserDetail('${user.user_id}')" style="cursor: pointer;">
                        <td><span class="user-id">${user.user_id.substring(0, 8)}</span></td>
                        <td><span class="tooltip" data-tooltip="${tooltipText}">📊 查看详情</span></td>
                        <td>${source}</td>
                        <td><span class="score ${scoreClass}">${user.score}</span></td>
                        <td><span class="badge ${user.user_type}">${getTypeLabel(user.user_type)}</span></td>
                        <td>${user.visit_count}</td>
                        <td>${formatDateTime(user.last_visit)}</td>
                    </tr>
                `;
            }).join('');
        }

        function renderPagination(current, total, perPage) {
            totalPages = Math.ceil(total / perPage);
            currentPage = current;

            const pagination = document.getElementById('pagination');
            let html = '';

            html += `<button onclick="loadUsers(${current - 1})" ${current === 1 ? 'disabled' : ''}>上一页</button>`;

            for (let i = Math.max(1, current - 2); i <= Math.min(totalPages, current + 2); i++) {
                html += `<button onclick="loadUsers(${i})" class="${i === current ? 'active' : ''}">${i}</button>`;
            }

            html += `<button onclick="loadUsers(${current + 1})" ${current === totalPages ? 'disabled' : ''}>下一页</button>`;

            pagination.innerHTML = html;
        }

        async function showUserDetail(userId) {
            try {
                const modal = document.getElementById('user-detail-modal');
                const modalBody = document.getElementById('modal-body');

                modalBody.innerHTML = '<div class="loading">加载中...</div>';
                modal.classList.add('show');

                const response = await fetch(`/admin/api/user-monitoring/users/${userId}`);
                const data = await response.json();

                if (data.success) {
                    const user = data.user;
                    const profile = data.profile || {};
                    const latestScore = data.scores[0] || {};

                    modalBody.innerHTML = `
                        <div class="modal-section">
                            <div class="modal-section-title">基本信息</div>
                            <div class="info-grid">
                                <div class="info-label">用户ID:</div>
                                <div class="info-value">${user.id}</div>
                                <div class="info-label">用户类型:</div>
                                <div class="info-value"><span class="badge ${user.user_type}">${getTypeLabel(user.user_type)}</span></div>
                                <div class="info-label">总评分:</div>
                                <div class="info-value score ${latestScore.total_score >= 70 ? 'high' : latestScore.total_score >= 40 ? 'medium' : 'low'}">${latestScore.total_score || 0}</div>
                                <div class="info-label">置信度:</div>
                                <div class="info-value">${latestScore.confidence || 0}</div>
                                <div class="info-label">访问次数:</div>
                                <div class="info-value">${user.visit_count}</div>
                                <div class="info-label">首次访问:</div>
                                <div class="info-value">${formatDateTime(user.first_visit_at)}</div>
                                <div class="info-label">最后访问:</div>
                                <div class="info-value">${formatDateTime(user.last_visit_at)}</div>
                            </div>
                        </div>

                        <div class="modal-section">
                            <div class="modal-section-title">设备信息</div>
                            <div class="info-grid">
                                <div class="info-label">IP地址:</div>
                                <div class="info-value">${profile.ip_address || 'N/A'}</div>
                                <div class="info-label">设备类型:</div>
                                <div class="info-value">${profile.device_type || 'N/A'}</div>
                                <div class="info-label">操作系统:</div>
                                <div class="info-value">${profile.os || 'N/A'}</div>
                                <div class="info-label">浏览器:</div>
                                <div class="info-value">${profile.browser || 'N/A'} ${profile.browser_version || ''}</div>
                                <div class="info-label">屏幕分辨率:</div>
                                <div class="info-value">${profile.screen_resolution || 'N/A'}</div>
                                <div class="info-label">时区:</div>
                                <div class="info-value">${profile.timezone || 'N/A'}</div>
                                <div class="info-label">语言:</div>
                                <div class="info-value">${profile.language || 'N/A'}</div>
                            </div>
                        </div>

                        ${latestScore.total_score ? `
                        <div class="modal-section">
                            <div class="modal-section-title">评分详情</div>
                            <div class="info-grid">
                                <div class="info-label">IP评分:</div>
                                <div class="info-value">${latestScore.ip_score}/20</div>
                                <div class="info-label">User-Agent评分:</div>
                                <div class="info-value">${latestScore.user_agent_score}/15</div>
                                <div class="info-label">请求模式评分:</div>
                                <div class="info-value">${latestScore.request_pattern_score}/15</div>
                                <div class="info-label">指纹评分:</div>
                                <div class="info-value">${latestScore.fingerprint_score}/20</div>
                                <div class="info-label">行为评分:</div>
                                <div class="info-value">${latestScore.behavior_score}/20</div>
                                <div class="info-label">来源评分:</div>
                                <div class="info-value">${latestScore.source_score}/10</div>
                            </div>
                        </div>
                        ` : ''}
                    `;
                }
            } catch (error) {
                console.error('Error loading user detail:', error);
                document.getElementById('modal-body').innerHTML = '<div class="loading">加载失败</div>';
            }
        }

        function closeModal() {
            document.getElementById('user-detail-modal').classList.remove('show');
        }

        function applyFilters() {
            loadUsers(1);
        }

        function getTypeLabel(type) {
            const labels = {
                'human': '人类',
                'suspicious': '可疑',
                'bot': '机器人',
                'high_risk': '高风险'
            };
            return labels[type] || type;
        }

        function formatDateTime(dateTime) {
            if (!dateTime) return 'N/A';
            const date = new Date(dateTime);
            return date.toLocaleString('zh-CN', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            loadStatistics();
            loadUsers(1);

            setInterval(loadStatistics, 30000);
        });

        document.getElementById('user-detail-modal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
    </script>
</body>
</html>
HTML;
    }
}
