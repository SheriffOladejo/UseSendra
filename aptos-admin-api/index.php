<?php

declare(strict_types=1);

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && (str_contains($origin, 'usesendra.com') || str_contains($origin, 'localhost'))) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Admin-Secret');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require $root . '/config/bootstrap_env.php';
require $root . '/config/Database.php';

use App\Controllers\AptosAdminController;
use App\Services\AptosAdminApiService;

$bots = require $root . '/config/Bots.php';
$aptosPhoneId = $_ENV['WHATSAPP_APTOS_PHONE_NUMBER_ID']
    ?? getenv('WHATSAPP_APTOS_PHONE_NUMBER_ID')
    ?: '851265871413834';
$dbConfig = $bots[$aptosPhoneId]['db_config'] ?? null;

if ($dbConfig === null) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Aptos wallet database not configured']);
    exit;
}

$db = Database::getInstance($dbConfig)->getConnection();
$api = new AptosAdminApiService($db);
$controller = new AptosAdminController($api);

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?: '/';

foreach (['/aptos/api', '/aptos/api/index.php'] as $prefix) {
    if (str_starts_with($path, $prefix)) {
        $path = substr($path, strlen($prefix)) ?: '/';
        break;
    }
}

$controller->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
