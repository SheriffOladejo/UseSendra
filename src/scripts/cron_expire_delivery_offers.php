<?php

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/config/bootstrap_env.php';

$host = $_ENV['LOGISTICS_DB_HOST'] ?? getenv('LOGISTICS_DB_HOST') ?: 'localhost';
$name = $_ENV['LOGISTICS_DB_NAME'] ?? getenv('LOGISTICS_DB_NAME') ?: 'sendra_logistics_db';
$user = $_ENV['LOGISTICS_DB_USER'] ?? getenv('LOGISTICS_DB_USER') ?: 'developer';
$pass = $_ENV['LOGISTICS_DB_PASS'] ?? getenv('LOGISTICS_DB_PASS') ?: '';

$db = new PDO(
    "mysql:host={$host};dbname={$name};charset=utf8mb4",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

try {

    $stmt = $db->prepare("
        SELECT id, request_id, driver_id
        FROM delivery_offers
        WHERE status = 'inreview' 
        AND updated_at <= (NOW() - INTERVAL 90 SECOND)
    ");

    $stmt->execute();
    $expiredOffers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$expiredOffers) {
        echo "No expired offers\n";
        exit;
    }

    foreach ($expiredOffers as $offer) {

        $update = $db->prepare("
            UPDATE delivery_offers
            SET status = 'rejected'
            WHERE id = :id
        ");

        $update->execute([
            'id' => $offer['id'],
        ]);

        echo "Offer {$offer['id']} expired and rejected\n";
    }

} catch (\Exception $e) {
    error_log('Cron error: ' . $e->getMessage());
}
