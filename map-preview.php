<?php

// Get coordinates from URL
$lat = $_GET['lat'] ?? null;
$lng = $_GET['lng'] ?? null;

if (!$lat || !$lng) {
    http_response_code(400);
    echo "Invalid coordinates.";
    exit;
}

// sanitize
$lat = floatval($lat);
$lng = floatval($lng);

// Map image for preview (OpenStreetMap free static map)
$mapImage = "https://staticmap.openstreetmap.de/staticmap.php?center={$lat},{$lng}&zoom=15&size=1200x630&markers={$lat},{$lng},red-pushpin";

// Where the user will actually go
$googleMaps = "https://www.google.com/maps/search/?api=1&query={$lat},{$lng}";

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">

<!-- OpenGraph tags for WhatsApp preview -->
<meta property="og:title" content="Sendra Delivery Location">
<meta property="og:description" content="Tap to view this delivery location on the map">
<meta property="og:image" content="<?php echo $mapImage; ?>">
<meta property="og:url" content="https://<?php echo $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']; ?>">

<meta name="twitter:card" content="summary_large_image">

<title>Sendra Map Preview</title>

<!-- redirect user to maps after opening -->
<meta http-equiv="refresh" content="0;url=<?php echo $googleMaps; ?>">

</head>

<body>

<p>Redirecting to map...</p>

</body>
</html>