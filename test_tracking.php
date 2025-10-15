<?php
/**
 * Test script for tracking system
 * This script tests the Supabase integration for user behavior tracking
 */

require __DIR__ . '/backend/vendor/autoload.php';

use App\Utils\SupabaseClient;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

echo "=== Testing Supabase Connection ===\n\n";

try {
    $supabase = new SupabaseClient();
    echo "✓ Supabase client initialized successfully\n\n";

    // Test 1: Insert a test behavior
    echo "Test 1: Inserting test user behavior...\n";
    $testData = [
        'session_id' => 'test_' . time(),
        'action_type' => 'page_load',
        'stock_name' => 'テスト株式',
        'stock_code' => '0000',
        'url' => 'http://test.example.com',
        'user_agent' => 'Test User Agent',
        'ip' => '127.0.0.1',
        'timezone' => 'Asia/Tokyo',
        'language' => 'ja',
        'referer' => ''
    ];

    $result = $supabase->insert('user_behaviors', $testData);
    echo "✓ Successfully inserted test data\n";
    echo "  Inserted ID: " . ($result[0]['id'] ?? 'N/A') . "\n\n";

    // Test 2: Query recent behaviors
    echo "Test 2: Querying recent behaviors...\n";
    $behaviors = $supabase->query('user_behaviors', [], 'created_at.desc', 5, 0);
    echo "✓ Successfully queried behaviors\n";
    echo "  Found " . count($behaviors['data']) . " recent behaviors\n\n";

    // Test 3: Count unique sessions
    echo "Test 3: Counting unique sessions...\n";
    $sessionCount = $supabase->countUniqueSessions();
    echo "✓ Successfully counted sessions\n";
    echo "  Total unique sessions: " . $sessionCount . "\n\n";

    // Test 4: Get behaviors by session
    if (!empty($behaviors['data'])) {
        $firstSession = $behaviors['data'][0]['session_id'];
        echo "Test 4: Getting behaviors for session: " . $firstSession . "\n";
        $sessionBehaviors = $supabase->getUserBehaviorsBySession($firstSession);
        echo "✓ Successfully retrieved session behaviors\n";
        echo "  Found " . count($sessionBehaviors['data']) . " behaviors for this session\n\n";
    }

    echo "=== All Tests Passed! ===\n";
    echo "\nThe tracking system is working correctly.\n";
    echo "You can now access the admin panel to view user behaviors:\n";
    echo "  URL: /admin\n";
    echo "  Username: admin\n";
    echo "  Password: admin123\n\n";

} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
