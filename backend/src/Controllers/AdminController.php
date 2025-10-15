<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class AdminController
{
    private LoggerInterface $logger;
    private string $dataDir;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->dataDir = __DIR__ . '/../../data';
        
        // 确保数据目录存在
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }
    }

    private function isAuthenticated(Request $request): bool
    {
        // 简单的认证检查 - 在生产环境中应该使用更安全的方法
        $cookies = $request->getCookieParams();
        return isset($cookies['admin_logged_in']) && $cookies['admin_logged_in'] === 'true';
    }

    private function requireAuth(Request $request, Response $response): ?Response
    {
        if (!$this->isAuthenticated($request)) {
            return $response->withHeader('Location', '/admin')->withStatus(302);
        }
        return null;
    }

    public function login(Request $request, Response $response): Response
    {
        if ($this->isAuthenticated($request)) {
            return $response->withHeader('Location', '/admin/dashboard')->withStatus(302);
        }

        $html = $this->renderLoginPage();
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function handleLogin(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';

        // 简单的认证 - 在生产环境中应该使用数据库和哈希密码
        if ($username === 'admin' && $password === 'admin123') {
            return $response
                ->withHeader('Set-Cookie', 'admin_logged_in=true; Path=/; HttpOnly')
                ->withHeader('Location', '/admin/dashboard')
                ->withStatus(302);
        }

        $html = $this->renderLoginPage('用户名或密码错误');
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function logout(Request $request, Response $response): Response
    {
        return $response
            ->withHeader('Set-Cookie', 'admin_logged_in=; Path=/; HttpOnly; Expires=Thu, 01 Jan 1970 00:00:00 GMT')
            ->withHeader('Location', '/admin')
            ->withStatus(302);
    }

    public function dashboard(Request $request, Response $response): Response
    {
        $authResponse = $this->requireAuth($request, $response);
        if ($authResponse) return $authResponse;

        $html = $this->renderDashboard();
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function customerServices(Request $request, Response $response): Response
    {
        $authResponse = $this->requireAuth($request, $response);
        if ($authResponse) return $authResponse;

        if ($request->getMethod() === 'POST') {
            return $this->handleCustomerServiceUpdate($request, $response);
        }

        $html = $this->renderCustomerServices();
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function trackingData(Request $request, Response $response): Response
    {
        $authResponse = $this->requireAuth($request, $response);
        if ($authResponse) return $authResponse;

        $html = $this->renderTrackingData();
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function assignments(Request $request, Response $response): Response
    {
        $authResponse = $this->requireAuth($request, $response);
        if ($authResponse) return $authResponse;

        $html = $this->renderAssignments();
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    // API 方法
    public function apiCustomerServices(Request $request, Response $response): Response
    {
        $authResponse = $this->requireAuth($request, $response);
        if ($authResponse) {
            $response->getBody()->write(json_encode(['error' => 'Unauthorized']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        $method = $request->getMethod();
        
        switch ($method) {
            case 'GET':
                $services = $this->loadCustomerServices();
                $response->getBody()->write(json_encode($services));
                return $response->withHeader('Content-Type', 'application/json');
                
            case 'POST':
                $data = json_decode($request->getBody()->getContents(), true);
                $result = $this->createCustomerService($data);
                $response->getBody()->write(json_encode($result));
                return $response->withHeader('Content-Type', 'application/json');
                
            case 'PUT':
                $data = json_decode($request->getBody()->getContents(), true);
                $result = $this->updateCustomerService($data);
                $response->getBody()->write(json_encode($result));
                return $response->withHeader('Content-Type', 'application/json');
                
            case 'DELETE':
                $data = json_decode($request->getBody()->getContents(), true);
                $result = $this->deleteCustomerService($data['id'] ?? '');
                $response->getBody()->write(json_encode($result));
                return $response->withHeader('Content-Type', 'application/json');
                
            default:
                $response->getBody()->write(json_encode(['error' => 'Method not allowed']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(405);
        }
    }

    public function apiTrackingData(Request $request, Response $response): Response
    {
        $authResponse = $this->requireAuth($request, $response);
        if ($authResponse) {
            $response->getBody()->write(json_encode(['error' => 'Unauthorized']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        $queryParams = $request->getQueryParams();
        $page = isset($queryParams['page']) ? max(1, (int)$queryParams['page']) : 1;
        $perPage = isset($queryParams['per_page']) ? max(1, min(50, (int)$queryParams['per_page'])) : 10;

        $trackingData = $this->loadTrackingDataPaginated($page, $perPage);
        $response->getBody()->write(json_encode($trackingData));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function apiAssignments(Request $request, Response $response): Response
    {
        $authResponse = $this->requireAuth($request, $response);
        if ($authResponse) {
            $response->getBody()->write(json_encode(['error' => 'Unauthorized']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        $assignments = $this->loadAssignments();
        $response->getBody()->write(json_encode($assignments));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function apiSettings(Request $request, Response $response): Response
    {
        $authResponse = $this->requireAuth($request, $response);
        if ($authResponse) {
            $response->getBody()->write(json_encode(['error' => 'Unauthorized']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        if ($request->getMethod() === 'POST') {
            $data = json_decode($request->getBody()->getContents(), true);
            $result = $this->updateSettings($data);
            $response->getBody()->write(json_encode($result));
            return $response->withHeader('Content-Type', 'application/json');
        } else {
            $settings = $this->loadSettings();
            $response->getBody()->write(json_encode($settings));
            return $response->withHeader('Content-Type', 'application/json');
        }
    }

    // 数据处理方法
    private function loadSettings(): array
    {
        $file = $this->dataDir . '/settings.json';
        if (!file_exists($file)) {
            $defaultSettings = [
                'cloaking_enhanced' => false
            ];
            file_put_contents($file, json_encode($defaultSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return $defaultSettings;
        }
        
        return json_decode(file_get_contents($file), true) ?: ['cloaking_enhanced' => false];
    }

    private function updateSettings(array $data): array
    {
        $file = $this->dataDir . '/settings.json';
        $settings = $this->loadSettings();
        
        if (isset($data['cloaking_enhanced'])) {
            $settings['cloaking_enhanced'] = (bool)$data['cloaking_enhanced'];
        }
        
        file_put_contents($file, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        $this->logger->info('Settings updated', $settings);
        
        return ['success' => true, 'settings' => $settings];
    }

    private function loadCustomerServices(): array
    {
        $file = $this->dataDir . '/customer_services.json';
        if (!file_exists($file)) {
            return [];
        }
        
        return json_decode(file_get_contents($file), true) ?: [];
    }

    private function createCustomerService(array $data): array
    {
        $services = $this->loadCustomerServices();
        
        $newService = [
            'id' => uniqid('cs_', true),
            'name' => $data['name'] ?? '',
            'url' => $data['url'] ?? '',
            'fallback_url' => $data['fallback_url'] ?? '/',
            'status' => $data['status'] ?? 'active',
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $services[] = $newService;
        
        file_put_contents($this->dataDir . '/customer_services.json', json_encode($services, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        return ['success' => true, 'service' => $newService];
    }

    private function updateCustomerService(array $data): array
    {
        $services = $this->loadCustomerServices();
        $updated = false;
        
        for ($i = 0; $i < count($services); $i++) {
            if ($services[$i]['id'] === ($data['id'] ?? '')) {
                $services[$i]['name'] = $data['name'] ?? $services[$i]['name'];
                $services[$i]['url'] = $data['url'] ?? $services[$i]['url'];
                $services[$i]['fallback_url'] = $data['fallback_url'] ?? $services[$i]['fallback_url'];
                $services[$i]['status'] = $data['status'] ?? $services[$i]['status'];
                $updated = true;
                break;
            }
        }
        
        if ($updated) {
            file_put_contents($this->dataDir . '/customer_services.json', json_encode($services, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return ['success' => true];
        }
        
        return ['success' => false, 'error' => 'Service not found'];
    }

    private function deleteCustomerService(string $id): array
    {
        $services = $this->loadCustomerServices();
        $originalCount = count($services);
        
        $services = array_filter($services, function($service) use ($id) {
            return $service['id'] !== $id;
        });
        
        if (count($services) < $originalCount) {
            file_put_contents($this->dataDir . '/customer_services.json', json_encode(array_values($services), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return ['success' => true];
        }
        
        return ['success' => false, 'error' => 'Service not found'];
    }

    private function handleCustomerServiceUpdate(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        
        if (isset($data['action'])) {
            switch ($data['action']) {
                case 'create':
                    $result = $this->createCustomerService($data);
                    break;
                case 'update':
                    $result = $this->updateCustomerService($data);
                    break;
                case 'delete':
                    $result = $this->deleteCustomerService($data['id'] ?? '');
                    break;
                default:
                    $result = ['success' => false, 'error' => 'Invalid action'];
            }
        } else {
            $result = ['success' => false, 'error' => 'No action specified'];
        }

        return $response->withHeader('Location', '/admin/customer-services')->withStatus(302);
    }

    private function loadTrackingData(): array
    {
        $file = $this->dataDir . '/../logs/tracking.log';
        if (!file_exists($file)) {
            return [];
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        $data = [];

        foreach (array_reverse(array_slice($lines, -100)) as $line) {
            if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) \[([^\]]+)\] (.+)$/', $line, $matches)) {
                $data[] = [
                    'timestamp' => $matches[1],
                    'type' => $matches[2],
                    'data' => json_decode($matches[3], true) ?: $matches[3]
                ];
            }
        }

        return $data;
    }

    private function loadTrackingDataPaginated(int $page, int $perPage): array
    {
        $allBehaviors = [];

        $file = $this->dataDir . '/../logs/tracking.log';
        if (file_exists($file)) {
            $lines = file($file, FILE_IGNORE_NEW_LINES);

            foreach ($lines as $line) {
                if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) \[([^\]]+)\] (.+)$/', $line, $matches)) {
                    $data = json_decode($matches[3], true);
                    if ($data && isset($data['session_id'])) {
                        $allBehaviors[] = [
                            'session_id' => $data['session_id'],
                            'timestamp' => $matches[1],
                            'type' => $matches[2],
                            'action_type' => $data['action_type'] ?? '',
                            'stock_name' => $data['stock_name'] ?? '',
                            'stock_code' => $data['stock_code'] ?? '',
                            'ip' => $data['ip'] ?? '',
                            'url' => $data['url'] ?? '',
                            'data' => $data
                        ];
                    }
                }
            }
        }

        $behaviorFile = $this->dataDir . '/user_behaviors.jsonl';
        if (file_exists($behaviorFile)) {
            $lines = file($behaviorFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            foreach ($lines as $line) {
                $data = json_decode($line, true);
                if ($data && isset($data['session_id'])) {
                    $allBehaviors[] = [
                        'session_id' => $data['session_id'],
                        'timestamp' => $data['timestamp'] ?? '',
                        'type' => 'user_behavior',
                        'action_type' => $data['action_type'] ?? '',
                        'stock_name' => $data['stock_name'] ?? '',
                        'stock_code' => $data['stock_code'] ?? '',
                        'ip' => $data['ip'] ?? '',
                        'url' => $data['url'] ?? '',
                        'data' => $data
                    ];
                }
            }
        }

        usort($allBehaviors, function($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });

        $groupedBySession = [];
        foreach ($allBehaviors as $behavior) {
            $sessionId = $behavior['session_id'];
            if (!isset($groupedBySession[$sessionId])) {
                $groupedBySession[$sessionId] = [
                    'session_id' => $sessionId,
                    'first_seen' => $behavior['timestamp'],
                    'last_seen' => $behavior['timestamp'],
                    'ip' => $behavior['ip'],
                    'stock_name' => $behavior['stock_name'],
                    'stock_code' => $behavior['stock_code'],
                    'behaviors' => []
                ];
            }
            $groupedBySession[$sessionId]['behaviors'][] = $behavior;
            if (strtotime($behavior['timestamp']) < strtotime($groupedBySession[$sessionId]['first_seen'])) {
                $groupedBySession[$sessionId]['first_seen'] = $behavior['timestamp'];
            }
            if (strtotime($behavior['timestamp']) > strtotime($groupedBySession[$sessionId]['last_seen'])) {
                $groupedBySession[$sessionId]['last_seen'] = $behavior['timestamp'];
            }
        }

        $sessions = array_values($groupedBySession);
        usort($sessions, function($a, $b) {
            return strtotime($b['last_seen']) - strtotime($a['last_seen']);
        });

        $total = count($sessions);
        $offset = ($page - 1) * $perPage;
        $paginatedSessions = array_slice($sessions, $offset, $perPage);

        return [
            'sessions' => $paginatedSessions,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => (int)ceil($total / $perPage)
        ];
    }

    private function loadAssignments(): array
    {
        $file = $this->dataDir . '/assignments.jsonl';
        if (!file_exists($file)) {
            return [];
        }
        
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        $assignments = [];
        
        foreach (array_reverse(array_slice($lines, -100)) as $line) {
            $assignment = json_decode($line, true);
            if ($assignment) {
                $assignments[] = $assignment;
            }
        }
        
        return $assignments;
    }

    // 渲染方法
    private function renderLoginPage(string $error = ''): string
    {
        $errorHtml = $error ? "<div class='alert alert-danger'>$error</div>" : '';
        
        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理后台登录</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 50px; }
        .login-container { max-width: 400px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type="text"], input[type="password"] { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        button { width: 100%; padding: 12px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        button:hover { background: #0056b3; }
        .alert { padding: 10px; margin-bottom: 20px; border-radius: 4px; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        h2 { text-align: center; margin-bottom: 30px; color: #333; }
    </style>
</head>
<body>
    <div class="login-container">
        <h2>管理后台登录</h2>
        $errorHtml
        <form method="POST" action="/admin/login">
            <div class="form-group">
                <label for="username">用户名:</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div class="form-group">
                <label for="password">密码:</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit">登录</button>
        </form>
    </div>
</body>
</html>
HTML;
    }

    private function renderDashboard(): string
    {
        $settings = $this->loadSettings();
        $cloakingStatus = $settings['cloaking_enhanced'] ? '启用' : '禁用';
        $cloakingClass = $settings['cloaking_enhanced'] ? 'text-success' : 'text-danger';
        $cloakingChecked = $settings['cloaking_enhanced'] ? 'checked' : '';
        
        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理后台 - 仪表板</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background: #f8f9fa; }
        .navbar { background: #343a40; color: white; padding: 1rem; display: flex; justify-content: space-between; align-items: center; }
        .navbar h1 { margin: 0; font-size: 1.5rem; }
        .navbar a { color: white; text-decoration: none; margin-left: 1rem; }
        .navbar a:hover { text-decoration: underline; }
        .container { max-width: 1200px; margin: 2rem auto; padding: 0 1rem; }
        .nav-tabs { display: flex; border-bottom: 1px solid #dee2e6; margin-bottom: 2rem; }
        .nav-tab { padding: 0.75rem 1.5rem; background: none; border: none; cursor: pointer; border-bottom: 2px solid transparent; }
        .nav-tab.active { border-bottom-color: #007bff; color: #007bff; font-weight: bold; }
        .nav-tab:hover { background: #f8f9fa; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .card { background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 2rem; }
        .card-header { padding: 1rem 1.5rem; border-bottom: 1px solid #dee2e6; font-weight: bold; }
        .card-body { padding: 1.5rem; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .stat-card { background: white; padding: 1.5rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); text-align: center; }
        .stat-number { font-size: 2rem; font-weight: bold; color: #007bff; }
        .stat-label { color: #6c757d; margin-top: 0.5rem; }
        .text-success { color: #28a745 !important; }
        .text-danger { color: #dc3545 !important; }
        .btn { padding: 0.5rem 1rem; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-primary { background: #007bff; color: white; }
        .btn-success { background: #28a745; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .btn:hover { opacity: 0.9; }
        .form-group { margin-bottom: 1rem; }
        .form-control { width: 100%; padding: 0.5rem; border: 1px solid #ced4da; border-radius: 4px; }
        .switch { position: relative; display: inline-block; width: 60px; height: 34px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 34px; }
        .slider:before { position: absolute; content: ""; height: 26px; width: 26px; left: 4px; bottom: 4px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: #2196F3; }
        input:focus + .slider { box-shadow: 0 0 1px #2196F3; }
        input:checked + .slider:before { transform: translateX(26px); }
        .setting-item { display: flex; justify-content: space-between; align-items: center; padding: 1rem 0; border-bottom: 1px solid #eee; }
        .setting-item:last-child { border-bottom: none; }
        .setting-label { font-weight: bold; }
        .setting-description { color: #6c757d; font-size: 0.9rem; margin-top: 0.25rem; }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>管理后台</h1>
        <div>
            <a href="/admin/dashboard">仪表板</a>
            <a href="/admin/customer-services">客服管理</a>
            <a href="/admin/tracking">追踪数据</a>
            <a href="/admin/assignments">分配记录</a>
            <a href="/admin/logout">退出</a>
        </div>
    </nav>

    <div class="container">
        <div class="nav-tabs">
            <button class="nav-tab active" onclick="showTab('overview')">概览</button>
            <button class="nav-tab" onclick="showTab('settings')">系统设置</button>
        </div>

        <div id="overview" class="tab-content active">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number" id="total-assignments">-</div>
                    <div class="stat-label">总分配数</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" id="success-rate">-</div>
                    <div class="stat-label">成功率</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" id="active-services">-</div>
                    <div class="stat-label">活跃客服</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number $cloakingClass">$cloakingStatus</div>
                    <div class="stat-label">斗篷加强</div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">最近活动</div>
                <div class="card-body">
                    <div id="recent-activity">加载中...</div>
                </div>
            </div>
        </div>

        <div id="settings" class="tab-content">
            <div class="card">
                <div class="card-header">系统设置</div>
                <div class="card-body">
                    <div class="setting-item">
                        <div>
                            <div class="setting-label">斗篷加强</div>
                            <div class="setting-description">启用后，只允许来自Google搜索的用户访问客服分配接口</div>
                        </div>
                        <label class="switch">
                            <input type="checkbox" id="cloaking-switch" onchange="toggleCloaking()" $cloakingChecked>
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function showTab(tabName) {
            // 隐藏所有标签内容
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            // 移除所有标签的活跃状态
            document.querySelectorAll('.nav-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // 显示选中的标签内容
            document.getElementById(tabName).classList.add('active');
            
            // 设置选中的标签为活跃状态
            event.target.classList.add('active');
        }

        function toggleCloaking() {
            const checkbox = document.getElementById('cloaking-switch');
            const enabled = checkbox.checked;
            
            fetch('/admin/api/settings', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    cloaking_enhanced: enabled
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('设置已更新');
                    location.reload();
                } else {
                    alert('更新失败');
                    checkbox.checked = !enabled; // 恢复原状态
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('更新失败');
                checkbox.checked = !enabled; // 恢复原状态
            });
        }

        // 加载统计数据
        function loadStats() {
            Promise.all([
                fetch('/admin/api/assignments').then(r => r.json()),
                fetch('/admin/api/customer-services').then(r => r.json())
            ]).then(([assignments, services]) => {
                document.getElementById('total-assignments').textContent = assignments.length;
                
                const successCount = assignments.filter(a => a.launch_success).length;
                const successRate = assignments.length > 0 ? Math.round((successCount / assignments.length) * 100) : 0;
                document.getElementById('success-rate').textContent = successRate + '%';
                
                const activeServices = services.filter(s => s.status === 'active').length;
                document.getElementById('active-services').textContent = activeServices;
                
                // 显示最近活动
                const recentActivity = assignments.slice(0, 10).map(a => 
                    `<div style="padding: 0.5rem 0; border-bottom: 1px solid #eee;">
                        <strong>\${a.stockcode || '未知股票'}</strong> - \${a.customer_service_name} 
                        <span style="color: #6c757d; float: right;">\${a.created_at}</span>
                    </div>`
                ).join('');
                
                document.getElementById('recent-activity').innerHTML = recentActivity || '暂无活动记录';
            }).catch(error => {
                console.error('Error loading stats:', error);
            });
        }

        // 页面加载时执行
        document.addEventListener('DOMContentLoaded', loadStats);
    </script>
</body>
</html>
HTML;
    }

    private function renderCustomerServices(): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>客服管理</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background: #f8f9fa; }
        .navbar { background: #343a40; color: white; padding: 1rem; display: flex; justify-content: space-between; align-items: center; }
        .navbar h1 { margin: 0; font-size: 1.5rem; }
        .navbar a { color: white; text-decoration: none; margin-left: 1rem; }
        .navbar a:hover { text-decoration: underline; }
        .container { max-width: 1200px; margin: 2rem auto; padding: 0 1rem; }
        .card { background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 2rem; }
        .card-header { padding: 1rem 1.5rem; border-bottom: 1px solid #dee2e6; font-weight: bold; display: flex; justify-content: space-between; align-items: center; }
        .card-body { padding: 1.5rem; }
        .btn { padding: 0.5rem 1rem; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-primary { background: #007bff; color: white; }
        .btn-success { background: #28a745; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .btn:hover { opacity: 0.9; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 0.75rem; text-align: left; border-bottom: 1px solid #dee2e6; }
        .table th { background: #f8f9fa; font-weight: bold; }
        .status-active { color: #28a745; font-weight: bold; }
        .status-inactive { color: #dc3545; font-weight: bold; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
        .modal-content { background-color: white; margin: 5% auto; padding: 2rem; width: 80%; max-width: 500px; border-radius: 8px; }
        .form-group { margin-bottom: 1rem; }
        .form-control { width: 100%; padding: 0.5rem; border: 1px solid #ced4da; border-radius: 4px; }
        .close { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
        .close:hover { color: black; }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>客服管理</h1>
        <div>
            <a href="/admin/dashboard">仪表板</a>
            <a href="/admin/customer-services">客服管理</a>
            <a href="/admin/tracking">追踪数据</a>
            <a href="/admin/assignments">分配记录</a>
            <a href="/admin/logout">退出</a>
        </div>
    </nav>

    <div class="container">
        <div class="card">
            <div class="card-header">
                客服列表
                <button class="btn btn-primary" onclick="showAddModal()">添加客服</button>
            </div>
            <div class="card-body">
                <table class="table" id="services-table">
                    <thead>
                        <tr>
                            <th>名称</th>
                            <th>URL</th>
                            <th>备用URL</th>
                            <th>状态</th>
                            <th>创建时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- 数据将通过JavaScript加载 -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 添加/编辑模态框 -->
    <div id="serviceModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2 id="modal-title">添加客服</h2>
            <form id="service-form">
                <input type="hidden" id="service-id">
                <div class="form-group">
                    <label for="service-name">名称:</label>
                    <input type="text" id="service-name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="service-url">URL:</label>
                    <input type="url" id="service-url" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="service-fallback">备用URL:</label>
                    <input type="url" id="service-fallback" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="service-status">状态:</label>
                    <select id="service-status" class="form-control">
                        <option value="active">活跃</option>
                        <option value="inactive">停用</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-success">保存</button>
                <button type="button" class="btn btn-danger" onclick="closeModal()">取消</button>
            </form>
        </div>
    </div>

    <script>
        let services = [];

        function loadServices() {
            fetch('/admin/api/customer-services')
                .then(response => response.json())
                .then(data => {
                    services = data;
                    renderServicesTable();
                })
                .catch(error => console.error('Error:', error));
        }

        function renderServicesTable() {
            const tbody = document.querySelector('#services-table tbody');
            tbody.innerHTML = services.map(service => `
                <tr>
                    <td>\${service.name}</td>
                    <td><a href="\${service.url}" target="_blank">\${service.url}</a></td>
                    <td><a href="\${service.fallback_url}" target="_blank">\${service.fallback_url}</a></td>
                    <td><span class="status-\${service.status}">\${service.status === 'active' ? '活跃' : '停用'}</span></td>
                    <td>\${service.created_at}</td>
                    <td>
                        <button class="btn btn-primary" onclick="editService('\${service.id}')">编辑</button>
                        <button class="btn btn-danger" onclick="deleteService('\${service.id}')">删除</button>
                    </td>
                </tr>
            `).join('');
        }

        function showAddModal() {
            document.getElementById('modal-title').textContent = '添加客服';
            document.getElementById('service-form').reset();
            document.getElementById('service-id').value = '';
            document.getElementById('serviceModal').style.display = 'block';
        }

        function editService(id) {
            const service = services.find(s => s.id === id);
            if (service) {
                document.getElementById('modal-title').textContent = '编辑客服';
                document.getElementById('service-id').value = service.id;
                document.getElementById('service-name').value = service.name;
                document.getElementById('service-url').value = service.url;
                document.getElementById('service-fallback').value = service.fallback_url;
                document.getElementById('service-status').value = service.status;
                document.getElementById('serviceModal').style.display = 'block';
            }
        }

        function deleteService(id) {
            if (confirm('确定要删除这个客服吗？')) {
                fetch('/admin/api/customer-services', {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        loadServices();
                    } else {
                        alert('删除失败');
                    }
                })
                .catch(error => console.error('Error:', error));
            }
        }

        function closeModal() {
            document.getElementById('serviceModal').style.display = 'none';
        }

        document.getElementById('service-form').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = {
                id: document.getElementById('service-id').value,
                name: document.getElementById('service-name').value,
                url: document.getElementById('service-url').value,
                fallback_url: document.getElementById('service-fallback').value,
                status: document.getElementById('service-status').value
            };

            const method = formData.id ? 'PUT' : 'POST';
            
            fetch('/admin/api/customer-services', {
                method: method,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(formData)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closeModal();
                    loadServices();
                } else {
                    alert('保存失败');
                }
            })
            .catch(error => console.error('Error:', error));
        });

        // 页面加载时执行
        document.addEventListener('DOMContentLoaded', loadServices);
    </script>
</body>
</html>
HTML;
    }

    private function renderTrackingData(): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>追踪数据</title>
    <script src="/static/js/pagination.js"></script>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background: #f8f9fa; }
        .navbar { background: #343a40; color: white; padding: 1rem; display: flex; justify-content: space-between; align-items: center; }
        .navbar h1 { margin: 0; font-size: 1.5rem; }
        .navbar a { color: white; text-decoration: none; margin-left: 1rem; }
        .navbar a:hover { text-decoration: underline; }
        .container { max-width: 1600px; margin: 2rem auto; padding: 0 1rem; }
        .card { background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 2rem; }
        .card-header { padding: 1rem 1.5rem; border-bottom: 1px solid #dee2e6; font-weight: bold; }
        .card-body { display: flex; padding: 0; }

        .tabs-sidebar { width: 280px; min-width: 280px; border-right: 1px solid #dee2e6; background: #f8f9fa; overflow-y: auto; max-height: calc(100vh - 200px); }
        .tabs-container { display: flex; flex-direction: column; }
        .tab { padding: 1rem 1.5rem; cursor: pointer; border-left: 3px solid transparent; white-space: nowrap; transition: all 0.3s; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e9ecef; }
        .tab:hover { background: #e9ecef; }
        .tab.active { border-left-color: #007bff; background: white; font-weight: bold; color: #007bff; }
        .tab-info { flex: 1; overflow: hidden; }
        .tab-title { font-size: 0.95rem; margin-bottom: 0.25rem; }
        .tab-subtitle { font-size: 0.75rem; color: #6c757d; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .tab.active .tab-subtitle { color: #007bff; }
        .tab-badge { display: inline-block; padding: 0.2rem 0.5rem; background: #6c757d; color: white; border-radius: 10px; font-size: 0.75rem; margin-left: 0.5rem; }
        .tab.active .tab-badge { background: #007bff; }

        .tab-content-wrapper { flex: 1; overflow-y: auto; max-height: calc(100vh - 200px); }
        .tab-content { display: none; padding: 1.5rem; }
        .tab-content.active { display: block; }

        .session-info { display: flex; justify-content: space-between; padding: 1rem; background: #e9ecef; border-radius: 4px; margin-bottom: 1rem; }
        .session-info-item { display: flex; flex-direction: column; }
        .session-info-label { font-size: 0.8rem; color: #6c757d; margin-bottom: 0.25rem; }
        .session-info-value { font-weight: bold; color: #495057; }

        .log-entry { margin-bottom: 1rem; padding: 1rem; background: #f8f9fa; border-radius: 4px; border-left: 4px solid #dee2e6; }
        .log-timestamp { font-weight: bold; color: #007bff; margin-bottom: 0.5rem; }
        .log-type { display: inline-block; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem; font-weight: bold; margin-left: 0.5rem; }
        .log-type-page_load { background: #d4edda; color: #155724; }
        .log-type-popup_triggered { background: #fff3cd; color: #856404; }
        .log-type-conversion { background: #d1ecf1; color: #0c5460; }
        .log-type-uppage_track { background: #d1ecf1; color: #0c5460; }
        .log-type-error_log { background: #f8d7da; color: #721c24; }

        .action-label { font-weight: 600; color: #495057; margin-top: 0.5rem; }
        .action-details { font-size: 0.9rem; color: #6c757d; margin-top: 0.25rem; }

        .empty-state { text-align: center; padding: 3rem; color: #6c757d; }

        .tabs-sidebar::-webkit-scrollbar { width: 8px; }
        .tabs-sidebar::-webkit-scrollbar-track { background: #f1f1f1; }
        .tabs-sidebar::-webkit-scrollbar-thumb { background: #888; border-radius: 4px; }
        .tabs-sidebar::-webkit-scrollbar-thumb:hover { background: #555; }

        .tab-content-wrapper::-webkit-scrollbar { width: 8px; }
        .tab-content-wrapper::-webkit-scrollbar-track { background: #f1f1f1; }
        .tab-content-wrapper::-webkit-scrollbar-thumb { background: #888; border-radius: 4px; }
        .tab-content-wrapper::-webkit-scrollbar-thumb:hover { background: #555; }

        @media (max-width: 768px) {
            .card-body { flex-direction: column; }
            .tabs-sidebar { width: 100%; border-right: none; border-bottom: 1px solid #dee2e6; max-height: 300px; }
            .tab-content-wrapper { max-height: none; }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>追踪数据</h1>
        <div>
            <a href="/admin/dashboard">仪表板</a>
            <a href="/admin/customer-services">客服管理</a>
            <a href="/admin/tracking">追踪数据</a>
            <a href="/admin/assignments">分配记录</a>
            <a href="/admin/logout">退出</a>
        </div>
    </nav>

    <div class="container">
        <div class="card">
            <div class="card-header">用户追踪数据</div>
            <div class="card-body">
                <div class="tabs-sidebar">
                    <div class="tabs-container" id="tabs-container">
                        <div class="empty-state" style="padding: 2rem 1rem;">加载中...</div>
                    </div>
                </div>
                <div class="tab-content-wrapper">
                    <div id="tab-contents"></div>
                </div>
            </div>
        </div>
        <div id="pagination-container"></div>
    </div>

    <script>
        let currentPage = 1;
        let sessionsData = [];

        function getActionLabel(actionType, stockName) {
            switch(actionType) {
                case 'page_load':
                    return '打开网站' + (stockName ? ' (' + stockName + ')' : '');
                case 'popup_triggered':
                    return '触发弹窗';
                case 'conversion':
                    return '用户产生转化';
                default:
                    return actionType;
            }
        }

        function switchTab(sessionId) {
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });

            const activeTab = document.querySelector('[data-session="' + sessionId + '"]');
            const activeContent = document.getElementById('content-' + sessionId);

            if (activeTab) activeTab.classList.add('active');
            if (activeContent) activeContent.classList.add('active');
        }

        function loadTrackingData(page = 1) {
            currentPage = page;

            fetch('/admin/api/tracking?page=' + page + '&per_page=10')
                .then(response => response.json())
                .then(data => {
                    sessionsData = data.sessions || [];

                    if (sessionsData.length === 0) {
                        document.getElementById('tabs-container').innerHTML = '<div class="empty-state" style="padding: 2rem 1rem;">暂无追踪数据</div>';
                        document.getElementById('tab-contents').innerHTML = '<div class="empty-state">暂无数据</div>';
                        document.getElementById('pagination-container').innerHTML = '';
                        return;
                    }

                    let tabsHTML = '';
                    let contentsHTML = '';

                    sessionsData.forEach((session, index) => {
                        const isActive = index === 0 ? 'active' : '';
                        const sessionShort = session.session_id.substring(0, 8);
                        const lastSeenTime = new Date(session.last_seen).toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit' });

                        tabsHTML += `
                            <div class="tab \${isActive}" data-session="\${session.session_id}" onclick="switchTab('\${session.session_id}')">
                                <div class="tab-info">
                                    <div class="tab-title">用户 \${sessionShort}</div>
                                    <div class="tab-subtitle">\${session.ip || '未知IP'} · \${lastSeenTime}</div>
                                </div>
                                <span class="tab-badge">\${session.behaviors.length}</span>
                            </div>
                        `;

                        contentsHTML += `
                            <div id="content-\${session.session_id}" class="tab-content \${isActive}">
                                <div class="session-info">
                                    <div class="session-info-item">
                                        <span class="session-info-label">会话ID</span>
                                        <span class="session-info-value">\${session.session_id}</span>
                                    </div>
                                    <div class="session-info-item">
                                        <span class="session-info-label">IP地址</span>
                                        <span class="session-info-value">\${session.ip || '未知'}</span>
                                    </div>
                                    <div class="session-info-item">
                                        <span class="session-info-label">首次访问</span>
                                        <span class="session-info-value">\${session.first_seen}</span>
                                    </div>
                                    <div class="session-info-item">
                                        <span class="session-info-label">最后访问</span>
                                        <span class="session-info-value">\${session.last_seen}</span>
                                    </div>
                                    <div class="session-info-item">
                                        <span class="session-info-label">行为数量</span>
                                        <span class="session-info-value">\${session.behaviors.length}</span>
                                    </div>
                                </div>
                                <div class="behaviors-list">
                                    \${session.behaviors.map(behavior => `
                                        <div class="log-entry">
                                            <div class="log-timestamp">\${behavior.timestamp}</div>
                                            <span class="log-type log-type-\${behavior.action_type}">\${behavior.action_type}</span>
                                            <div class="action-label">\${getActionLabel(behavior.action_type, behavior.stock_name)}</div>
                                            \${behavior.stock_name ? '<div class="action-details">股票: ' + behavior.stock_name + (behavior.stock_code ? ' (' + behavior.stock_code + ')' : '') + '</div>' : ''}
                                            \${behavior.url ? '<div class="action-details">URL: ' + behavior.url + '</div>' : ''}
                                        </div>
                                    `).join('')}
                                </div>
                            </div>
                        `;
                    });

                    document.getElementById('tabs-container').innerHTML = tabsHTML;
                    document.getElementById('tab-contents').innerHTML = contentsHTML;

                    renderPagination(data.page, data.total_pages);
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('tabs-container').innerHTML = '<div class="empty-state">加载失败</div>';
                });
        }

        function renderPagination(currentPage, totalPages) {
            if (totalPages <= 1) {
                document.getElementById('pagination-container').innerHTML = '';
                return;
            }

            let paginationHTML = '<div style="display: flex; justify-content: center; gap: 0.5rem; margin-top: 1rem;">';

            if (currentPage > 1) {
                paginationHTML += '<button onclick="loadTrackingData(' + (currentPage - 1) + ')" style="padding: 0.5rem 1rem; cursor: pointer;">上一页</button>';
            }

            paginationHTML += '<span style="padding: 0.5rem 1rem;">第 ' + currentPage + ' / ' + totalPages + ' 页</span>';

            if (currentPage < totalPages) {
                paginationHTML += '<button onclick="loadTrackingData(' + (currentPage + 1) + ')" style="padding: 0.5rem 1rem; cursor: pointer;">下一页</button>';
            }

            paginationHTML += '</div>';

            document.getElementById('pagination-container').innerHTML = paginationHTML;
        }

        document.addEventListener('DOMContentLoaded', function() {
            loadTrackingData(1);
        });
    </script>
</body>
</html>
HTML;
    }

    private function renderAssignments(): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>分配记录</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background: #f8f9fa; }
        .navbar { background: #343a40; color: white; padding: 1rem; display: flex; justify-content: space-between; align-items: center; }
        .navbar h1 { margin: 0; font-size: 1.5rem; }
        .navbar a { color: white; text-decoration: none; margin-left: 1rem; }
        .navbar a:hover { text-decoration: underline; }
        .container { max-width: 1200px; margin: 2rem auto; padding: 0 1rem; }
        .card { background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 2rem; }
        .card-header { padding: 1rem 1.5rem; border-bottom: 1px solid #dee2e6; font-weight: bold; }
        .card-body { padding: 1.5rem; }
        .table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        .table th, .table td { padding: 0.5rem; text-align: left; border-bottom: 1px solid #dee2e6; }
        .table th { background: #f8f9fa; font-weight: bold; }
        .status-success { color: #28a745; font-weight: bold; }
        .status-failed { color: #dc3545; font-weight: bold; }
        .status-pending { color: #ffc107; font-weight: bold; }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>分配记录</h1>
        <div>
            <a href="/admin/dashboard">仪表板</a>
            <a href="/admin/customer-services">客服管理</a>
            <a href="/admin/tracking">追踪数据</a>
            <a href="/admin/assignments">分配记录</a>
            <a href="/admin/logout">退出</a>
        </div>
    </nav>

    <div class="container">
        <div class="card">
            <div class="card-header">最近分配记录</div>
            <div class="card-body">
                <table class="table" id="assignments-table">
                    <thead>
                        <tr>
                            <th>时间</th>
                            <th>股票代码</th>
                            <th>客服名称</th>
                            <th>状态</th>
                            <th>IP地址</th>
                            <th>用户代理</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- 数据将通过JavaScript加载 -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        function loadAssignments() {
            fetch('/admin/api/assignments')
                .then(response => response.json())
                .then(data => {
                    const tbody = document.querySelector('#assignments-table tbody');
                    if (data.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="6">暂无分配记录</td></tr>';
                        return;
                    }
                    
                    tbody.innerHTML = data.map(assignment => {
                        let status = '待处理';
                        let statusClass = 'status-pending';
                        
                        if (assignment.launch_success) {
                            status = '成功';
                            statusClass = 'status-success';
                        } else if (assignment.page_leave_at || assignment.fallback_redirect_at) {
                            status = '失败';
                            statusClass = 'status-failed';
                        }
                        
                        return `
                            <tr>
                                <td>\${assignment.created_at}</td>
                                <td>\${assignment.stockcode || '-'}</td>
                                <td>\${assignment.customer_service_name}</td>
                                <td><span class="\${statusClass}">\${status}</span></td>
                                <td>\${assignment.ip}</td>
                                <td title="\${assignment.user_agent}">\${assignment.user_agent.substring(0, 50)}...</td>
                            </tr>
                        `;
                    }).join('');
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.querySelector('#assignments-table tbody').innerHTML = '<tr><td colspan="6">加载失败</td></tr>';
                });
        }

        document.addEventListener('DOMContentLoaded', loadAssignments);
    </script>
</body>
</html>
HTML;
    }

    public function userBehaviors(Request $request, Response $response): Response
    {
        $authResponse = $this->requireAuth($request, $response);
        if ($authResponse) return $authResponse;

        $html = $this->renderUserBehaviors();
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function apiUserBehaviors(Request $request, Response $response): Response
    {
        $authResponse = $this->requireAuth($request, $response);
        if ($authResponse) return $authResponse;

        $queryParams = $request->getQueryParams();
        $page = isset($queryParams['page']) ? (int)$queryParams['page'] : 1;
        $perPage = isset($queryParams['per_page']) ? (int)$queryParams['per_page'] : 10;

        if ($page < 1) $page = 1;
        if ($perPage < 1 || $perPage > 50) $perPage = 10;

        $trackingController = new TrackingController($this->logger);
        $behaviors = $trackingController->getUserBehaviors();

        $allBehaviors = [];
        foreach ($behaviors as $sessionId => $actions) {
            foreach ($actions as $action) {
                $action['session_id'] = $sessionId;
                $allBehaviors[] = $action;
            }
        }

        usort($allBehaviors, function($a, $b) {
            $timeA = $a['timestamp'] ?? '';
            $timeB = $b['timestamp'] ?? '';
            return strtotime($timeB) - strtotime($timeA);
        });

        $total = count($allBehaviors);
        $offset = ($page - 1) * $perPage;
        $paginatedBehaviors = array_slice($allBehaviors, $offset, $perPage);

        $groupedBySession = [];
        foreach ($paginatedBehaviors as $behavior) {
            $sessionId = $behavior['session_id'];
            if (!isset($groupedBySession[$sessionId])) {
                $groupedBySession[$sessionId] = [];
            }
            $groupedBySession[$sessionId][] = $behavior;
        }

        $formattedBehaviors = [];
        foreach ($groupedBySession as $sessionId => $actions) {
            $sessionData = [
                'session_id' => $sessionId,
                'actions' => [],
                'first_action_time' => '',
                'last_action_time' => '',
                'ip' => '',
                'stock_name' => '',
                'stock_code' => ''
            ];

            usort($actions, function($a, $b) {
                $timeA = isset($a['timestamp']) ? $a['timestamp'] : ($a['created_at'] ?? '');
                $timeB = isset($b['timestamp']) ? $b['timestamp'] : ($b['created_at'] ?? '');
                return strtotime($timeA) - strtotime($timeB);
            });

            foreach ($actions as $action) {
                $actionLabel = '';
                switch ($action['action_type']) {
                    case 'page_load':
                        $actionLabel = "打开网站 ({$action['stock_name']})";
                        break;
                    case 'popup_triggered':
                        $actionLabel = '触发弹窗';
                        break;
                    case 'conversion':
                        $actionLabel = '用户产生转化';
                        break;
                    default:
                        $actionLabel = $action['action_type'];
                }

                $timestamp = $action['timestamp'] ?? $action['created_at'] ?? '';
                $sessionData['actions'][] = [
                    'type' => $action['action_type'],
                    'label' => $actionLabel,
                    'timestamp' => $timestamp,
                    'stock_name' => $action['stock_name'] ?? '',
                    'stock_code' => $action['stock_code'] ?? ''
                ];
            }

            if (!empty($actions)) {
                $sessionData['first_action_time'] = $actions[0]['timestamp'] ?? $actions[0]['created_at'] ?? '';
                $sessionData['last_action_time'] = $actions[count($actions) - 1]['timestamp'] ?? $actions[count($actions) - 1]['created_at'] ?? '';
                $sessionData['ip'] = $actions[0]['ip'] ?? '';
                $sessionData['stock_name'] = $actions[0]['stock_name'] ?? '';
                $sessionData['stock_code'] = $actions[0]['stock_code'] ?? '';
            }

            $formattedBehaviors[] = $sessionData;
        }

        usort($formattedBehaviors, function($a, $b) {
            return strtotime($b['last_action_time']) - strtotime($a['last_action_time']);
        });

        $responseData = [
            'data' => $formattedBehaviors,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => count($formattedBehaviors),
                'total_pages' => (int)ceil(count($formattedBehaviors) / $perPage)
            ]
        ];

        $response->getBody()->write(json_encode($responseData));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function renderUserBehaviors(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>用户行为追踪 - 管理后台</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: "Segoe UI", Arial, sans-serif; background: #f5f7fa; }

        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }

        .header {
            background: white;
            padding: 20px 30px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 { color: #2c3e50; font-size: 24px; }

        .nav { display: flex; gap: 15px; }
        .nav a {
            color: #5a6c7d;
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 6px;
            transition: all 0.3s;
        }
        .nav a:hover { background: #f0f2f5; color: #2c3e50; }
        .nav a.active { background: #3498db; color: white; }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .stat-card h3 { color: #7f8c8d; font-size: 14px; margin-bottom: 10px; }
        .stat-card .number { color: #2c3e50; font-size: 32px; font-weight: bold; }

        .content {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 30px;
            padding: 20px 0;
        }

        .page-btn {
            padding: 8px 16px;
            border: 1px solid #dee2e6;
            background: white;
            color: #2c3e50;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 14px;
        }

        .page-btn:hover {
            background: #f8f9fa;
            border-color: #3498db;
        }

        .page-btn.active {
            background: #3498db;
            color: white;
            border-color: #3498db;
        }

        .page-ellipsis {
            padding: 8px 12px;
            color: #95a5a6;
        }

        .user-session {
            border: 1px solid #e1e8ed;
            border-radius: 8px;
            margin-bottom: 20px;
            overflow: hidden;
        }

        .session-header {
            background: #f8f9fa;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e1e8ed;
        }

        .session-info {
            display: flex;
            gap: 20px;
            align-items: center;
        }

        .session-id {
            font-family: monospace;
            color: #7f8c8d;
            font-size: 12px;
        }

        .session-stock {
            color: #2c3e50;
            font-weight: 600;
        }

        .session-ip {
            color: #95a5a6;
            font-size: 13px;
        }

        .session-time {
            color: #95a5a6;
            font-size: 13px;
        }

        .behavior-timeline {
            padding: 20px;
        }

        .behavior-item {
            display: flex;
            align-items: flex-start;
            margin-bottom: 15px;
            position: relative;
            padding-left: 40px;
        }

        .behavior-item:not(:last-child)::before {
            content: '';
            position: absolute;
            left: 14px;
            top: 30px;
            bottom: -15px;
            width: 2px;
            background: #e1e8ed;
        }

        .behavior-icon {
            position: absolute;
            left: 0;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
            font-size: 14px;
            z-index: 1;
        }

        .behavior-icon.page-load {
            background: #3498db;
        }

        .behavior-icon.popup {
            background: #f39c12;
        }

        .behavior-icon.conversion {
            background: #27ae60;
        }

        .behavior-content {
            flex: 1;
        }

        .behavior-label {
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .behavior-time {
            color: #95a5a6;
            font-size: 13px;
        }

        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-success {
            background: #d4edda;
            color: #155724;
        }

        .badge-warning {
            background: #fff3cd;
            color: #856404;
        }

        .badge-info {
            background: #d1ecf1;
            color: #0c5460;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #95a5a6;
        }

        .empty-state svg {
            width: 80px;
            height: 80px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>用户行为追踪</h1>
            <div class="nav">
                <a href="/admin/dashboard">仪表板</a>
                <a href="/admin/customer-services">客服管理</a>
                <a href="/admin/user-behaviors" class="active">用户行为</a>
                <a href="/admin/assignments">分配记录</a>
                <a href="/admin/logout">退出</a>
            </div>
        </div>

        <div class="stats">
            <div class="stat-card">
                <h3>总会话数</h3>
                <div class="number" id="total-sessions">0</div>
            </div>
            <div class="stat-card">
                <h3>触发弹窗</h3>
                <div class="number" id="popup-count">0</div>
            </div>
            <div class="stat-card">
                <h3>产生转化</h3>
                <div class="number" id="conversion-count">0</div>
            </div>
            <div class="stat-card">
                <h3>转化率</h3>
                <div class="number" id="conversion-rate">0%</div>
            </div>
        </div>

        <div class="content">
            <div id="behaviors-container">
                <div class="empty-state">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                    </svg>
                    <p>正在加载用户行为数据...</p>
                </div>
            </div>
            <div id="pagination-container"></div>
        </div>
    </div>

    <script>
        let currentPage = 1;
        const perPage = 10;
        let totalPages = 1;

        function loadUserBehaviors(page = 1) {
            currentPage = page;
            fetch(`/admin/api/user-behaviors?page=${page}&per_page=${perPage}`)
                .then(response => response.json())
                .then(result => {
                    const data = result.data || [];
                    const pagination = result.pagination || { current_page: 1, total: 0, total_pages: 1 };

                    totalPages = pagination.total_pages;

                    if (data.length === 0) {
                        document.getElementById('behaviors-container').innerHTML = `
                            <div class="empty-state">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                                </svg>
                                <p>暂无用户行为数据</p>
                            </div>
                        `;
                        return;
                    }

                    let popupCount = 0;
                    let conversionCount = 0;

                    data.forEach(session => {
                        session.actions.forEach(action => {
                            if (action.type === 'popup_triggered') popupCount++;
                            if (action.type === 'conversion') conversionCount++;
                        });
                    });

                    document.getElementById('total-sessions').textContent = pagination.total;
                    document.getElementById('popup-count').textContent = popupCount;
                    document.getElementById('conversion-count').textContent = conversionCount;
                    const rate = pagination.total > 0 ? ((conversionCount / pagination.total) * 100).toFixed(1) : 0;
                    document.getElementById('conversion-rate').textContent = rate + '%';

                    const container = document.getElementById('behaviors-container');
                    container.innerHTML = data.map(session => {
                        const actionsHtml = session.actions.map(action => {
                            let iconClass = 'page-load';
                            let iconText = '1';

                            if (action.type === 'popup_triggered') {
                                iconClass = 'popup';
                                iconText = '2';
                            } else if (action.type === 'conversion') {
                                iconClass = 'conversion';
                                iconText = '3';
                            }

                            return `
                                <div class="behavior-item">
                                    <div class="behavior-icon ${iconClass}">${iconText}</div>
                                    <div class="behavior-content">
                                        <div class="behavior-label">${action.label}</div>
                                        <div class="behavior-time">${action.timestamp}</div>
                                    </div>
                                </div>
                            `;
                        }).join('');

                        let completionBadge = '';
                        if (session.actions.some(a => a.type === 'conversion')) {
                            completionBadge = '<span class="badge badge-success">已转化</span>';
                        } else if (session.actions.some(a => a.type === 'popup_triggered')) {
                            completionBadge = '<span class="badge badge-warning">已触发弹窗</span>';
                        } else {
                            completionBadge = '<span class="badge badge-info">仅访问</span>';
                        }

                        return `
                            <div class="user-session">
                                <div class="session-header">
                                    <div class="session-info">
                                        <span class="session-stock">${session.stock_name || '未知股票'} (${session.stock_code || 'N/A'})</span>
                                        <span class="session-ip">IP: ${session.ip}</span>
                                        <span class="session-id">${session.session_id}</span>
                                    </div>
                                    <div>
                                        ${completionBadge}
                                        <span class="session-time">${session.last_action_time}</span>
                                    </div>
                                </div>
                                <div class="behavior-timeline">
                                    ${actionsHtml}
                                </div>
                            </div>
                        `;
                    }).join('');

                    renderPagination();
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('behaviors-container').innerHTML = `
                        <div class="empty-state">
                            <p>加载失败，请刷新页面重试</p>
                        </div>
                    `;
                });
        }

        function renderPagination() {
            const paginationContainer = document.getElementById('pagination-container');
            if (!paginationContainer) return;

            if (totalPages <= 1) {
                paginationContainer.innerHTML = '';
                return;
            }

            let paginationHTML = '<div class="pagination">';

            if (currentPage > 1) {
                paginationHTML += `<button class="page-btn" onclick="loadUserBehaviors(${currentPage - 1})">上一页</button>`;
            }

            const startPage = Math.max(1, currentPage - 2);
            const endPage = Math.min(totalPages, currentPage + 2);

            if (startPage > 1) {
                paginationHTML += `<button class="page-btn" onclick="loadUserBehaviors(1)">1</button>`;
                if (startPage > 2) {
                    paginationHTML += `<span class="page-ellipsis">...</span>`;
                }
            }

            for (let i = startPage; i <= endPage; i++) {
                const activeClass = i === currentPage ? 'active' : '';
                paginationHTML += `<button class="page-btn ${activeClass}" onclick="loadUserBehaviors(${i})">${i}</button>`;
            }

            if (endPage < totalPages) {
                if (endPage < totalPages - 1) {
                    paginationHTML += `<span class="page-ellipsis">...</span>`;
                }
                paginationHTML += `<button class="page-btn" onclick="loadUserBehaviors(${totalPages})">${totalPages}</button>`;
            }

            if (currentPage < totalPages) {
                paginationHTML += `<button class="page-btn" onclick="loadUserBehaviors(${currentPage + 1})">下一页</button>`;
            }

            paginationHTML += '</div>';
            paginationContainer.innerHTML = paginationHTML;
        }

        document.addEventListener('DOMContentLoaded', () => loadUserBehaviors(1));
        setInterval(() => loadUserBehaviors(currentPage), 30000);
    </script>
</body>
</html>
HTML;
    }
}