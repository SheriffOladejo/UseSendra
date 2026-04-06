<?php

require __DIR__ . '../../../vendor/autoload.php';

$db = new PDO(
    'mysql:host=localhost;dbname=sendra_logistics_db;charset=utf8mb4',
    'developer',
    'Stipendly@2026',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

try {

    // Find offers that expired
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

        // Update status
        $update = $db->prepare("
            UPDATE delivery_offers
            SET status = 'rejected'
            WHERE id = :id
        ");

        $update->execute([
            'id' => $offer['id']
        ]);

        echo "Offer {$offer['id']} expired and rejected\n";
    }

} catch (Exception $e) {
    error_log("Cron error: " . $e->getMessage());
}