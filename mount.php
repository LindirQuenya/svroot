<?php
// Do not use this outside of a trusted environment!
// Gets the device name (sda1, vda2, etc.) from the serial number.
function find_device_by_serial($serial) {
	// Check: running in docker?
	if (file_exists("/.dockerenv")) {
		// Yes, treat things differently.
		$dir = opendir("/mnt/docker");
		// For each entry in /mnt/docker/*,
		while (false !== ($entry = readdir($dir))) {
			// Skip . and .., they're special.
			if ($entry == "." or $entry == "..") {
				continue;
			}
			// If the filename matches, close the directory and return.
			if ($serial === $entry) {
				closedir($dir);
				return $entry;
			}
		}
		// If we didn't find one, close the dir anyway.
		closedir($dir);
	} else {
		// No, search for it as a device.
		$dir = opendir("/sys/block");
		// For each entry in /sys/block/*,
		while (false !== ($entry = readdir($dir))) {
			// Skip . and .., they're special.
			if ($entry == "." or $entry == "..") {
				continue;
			}
			// Check the device's serial.
			$found = @file_get_contents("/sys/block/${entry}/serial");
			// If the serial matches, close the directory and return.
			if ($serial === $found) {
				closedir($dir);
				return $entry;
			}
		}
		// If we didn't find one, close the dir anyway.
		closedir($dir);
	}
}
// The default device location is /dev/
$devLocation = "/dev/";
// But if we're running in docker...
if (file_exists("/.dockerenv")) {
	// Change it to /mnt/docker/.
	$devLocation = "/mnt/docker/";
}
// Read the GET param "file" to get the serial.
$serial = $_GET["file"];
// Max number of attempts before we give up on mounting.
$attempts = 100;
// First try: find the device.
$device = find_device_by_serial($serial);
// It failed? Try again until the attempts are exhausted.
while (!$device and $attempts--) {
	// Wait 0.1 seconds between attempts.
	usleep(100000);
	$device = find_device_by_serial($serial);
}
// If we still haven't found it, give up and return an error.
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
// Okay, we're mounting it. Log that.
error_log("Mounting ${devLocation}${device}");
// Symlink the device to a .zip, so that avfs knows what to do with it.
exec("sudo ln -s ${devLocation}${device} /tmp/${device}.zip");
// Make a folder for the gamezip contents to be fuzzy-mounted at.
exec("mkdir /tmp/${device}");
// Funny thing about avfs: it lets you access the contents of zips transparantly.
// "/root/.avfs" is where avfs is running.
// "/root/.avfs/tmp/${device}.zip#" is the "folder" with the contents of that zip.
// Fuzzy-mounting is just a case-insensitive bind-mount.
exec("sudo fuzzyfs /root/.avfs/tmp/${device}.zip# /tmp/${device} -o allow_other");
// The location of the hypothetical content folder.
$content = "/tmp/${device}/content";
// Check that a content folder exists.
if (!is_dir($content)) {
	// If not, send back some errors.
	http_response_code(400);
	die("NO_CONTENT_FOLDER");
}
// Lock on /tmp/lock: we're about to write to /tmp/union.
$lock = fopen("/tmp/lock", "w+");
// Obtain an exclusive (writer) lock. flock is blocking, so this isn't really an if.
if (flock($lock, LOCK_EX)) {
	// Get the latest contents of /tmp/union.
	$union = file_get_contents("/tmp/union");
	// Append our new content folder to the mix.
	$union = "${content}:${union}";
	// Unmount the current union mount.
	exec("sudo umount -l /var/www/localhost/htdocs");
	// Remake it from /root/base and all the union paths.
	exec("sudo unionfs '/root/base:${union}' /var/www/localhost/htdocs -o allow_other");
	// Write the contents of the union variable to /tmp/union.
	file_put_contents("/tmp/union", $union);
	// Unlock the flock.
	flock($lock, LOCK_UN);
	// Close the file.
	fclose($lock);
}
// We're done! Say so.
echo "OK";
