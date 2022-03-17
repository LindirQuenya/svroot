<?php
// Do not use this outside of a trusted environment!
// Gets the device name (sda1, vda2, etc.) from the serial number when in QEMU.
// When in docker, returns the input if it exists in /mnt/docker.
function find_device_by_serial($serial) {
	// Check: running in docker?
	if (file_exists("/.dockerenv")) {
		$dir = opendir("/mnt/docker");
		while (false !== ($entry = readdir($dir))) {
			if ($entry == "." or $entry == "..") {
				continue;
			}
			// Check for a match with the filename (not serial, careful!).
			if ($serial === $entry) {
				closedir($dir);
				return $entry;
			}
		}
		closedir($dir);
	} else {
		// No, search for it as a device.
		$dir = opendir("/sys/block");
		while (false !== ($entry = readdir($dir))) {
			if ($entry == "." or $entry == "..") {
				continue;
			}
			$found = @file_get_contents("/sys/block/${entry}/serial");
			if ($serial === $found) {
				closedir($dir);
				return $entry;
			}
		}
		closedir($dir);
	}
}
// The device location is /dev/ in QEMU and /mnt/docker/ in docker.
$devLocation = "/dev/";
if (file_exists("/.dockerenv")) {
	$devLocation = "/mnt/docker/";
}

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
// Race possible here
// The file /tmp/union tracks all the directories currently union-mounted.
$union = file_get_contents("/tmp/union");
// Check if the device identifier is already in the list of union-mounted directories.
// Later on, when we union-mount directories, they will be of the form "/tmp/${device}/content".
// Because of this, searching for the device id is sufficient to tell if it's already mounted.
if (strpos($union, $device) !== false) {
	die("ALREADY_MOUNTED");
}
// Watch carefully: we're going to transparently unzip/mount that gamezip without *ever* touching persistent storage.
error_log("Mounting ${devLocation}${device}");

exec("mkdir /tmp/${device} /tmp/${device}.fuzzy");

// fuse-archive will allow us to transparently access the contents of a zip.
exec("sudo fuse-archive ${devLocation}${device} /tmp/${device} -o allow_other");
// Fuzzy-mounting is just a case-insensitive bind-mount.
exec("sudo fuzzyfs /tmp/${device} /tmp/${device}.fuzzy -o allow_other");

$content = "/tmp/${device}.fuzzy/content";

if (!is_dir($content)) {
	http_response_code(400);
	die("NO_CONTENT_FOLDER");
}

$lock = fopen("/tmp/lock", "w+");
// flock is blocking, so this isn't really an if.
if (flock($lock, LOCK_EX)) {
	// Get the latest contents of /tmp/union.
	$union = file_get_contents("/tmp/union");
	$union = "${content}:${union}";

	exec("sudo umount -l /var/www/localhost/htdocs");
	exec("sudo unionfs '/root/base:${union}' /var/www/localhost/htdocs -o allow_other");

	file_put_contents("/tmp/union", $union);

	fclose($lock);
	flock($lock, LOCK_UN);
}

echo "OK";
