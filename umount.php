<?php
// Do not use this outside of a trusted environment!
require 'devfromserial.php';

$serial = $_GET["file"];

$attempts = 100;

$device = find_device_by_serial($serial);

while (!$device and $attempts--) {
	usleep(100000);
	$device = find_device_by_serial($serial);
}

if (!$device) {
	http_response_code(400);
	die("NO_SUCH_FILE");
}

$device_encoded = urlencode($device);

// Forward the mount request to the privileged daemon.
$context = stream_context_create(['http' => ['ignore_errors' => true]]);
$message = file_get_contents("http://127.0.0.1:3030/umount?devname=${device_encoded}", false, $context);
// Grab the status code from the response.
preg_match('{HTTP\/\S*\s(\d{3})}', $http_response_header[0], $match);
// Forward the response code and body back to the client.
http_response_code((int)$match[1]);
echo $message;
?>
