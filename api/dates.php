<?php
require_once __DIR__ . '/../lib/paths.php';
header('Content-Type: application/json');

// The upstream API returns the full list of dates (and HTTP 400) when called with no date.
$ch = curl_init(vbf_api_base());
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
$body = curl_exec($ch);
curl_close($ch);

if ($body === false) {
    http_response_code(502);
    echo json_encode(['error' => 'upstream API unreachable']);
    exit;
}

$data = json_decode($body, true);
$dates = (is_array($data) && isset($data['available_dates']) && is_array($data['available_dates']))
    ? array_values($data['available_dates'])
    : [];

// Keep only well-formed YYYYMMDD entries, newest first (API already returns newest-first).
$dates = array_values(array_filter($dates, fn($d) => is_string($d) && preg_match('/^\d{8}$/', $d)));

echo json_encode(['dates' => $dates]);
