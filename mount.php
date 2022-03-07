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

// Check for the existence of a few GET params.
$hasfile = array_key_exists("file", $_GET);
$hasnonzip = array_key_exists("nonzip", $_GET);
$hasnzloc = array_key_exists("nzloc", $_GET);

// If both types of mount were specified, bail out.
if ($hasfile && $hasnonzip) {
	http_response_code(400);
	die("PARAM_CONFLICT");
// If we have the "file" param, set the serial.
} elseif ($hasfile) {
	$serial = $_GET["file"];
// If we have the "nonzip" param, ensure that we have nzloc as well.
} elseif ($hasnonzip) {
	// If we have both, set the serial and the nzloc.
	if ($hasnzloc) {
		$serial = $_GET["nonzip"];
		$nzloc = $_GET["nzloc"];
        } else {
		// We need nzloc to perform a nonzip mount. Bail out.
		http_response_code(400);
		die("INSUFFICIENT_PARAMS");
        }
} else {
	// No params specified. Bail out.
	http_response_code(400);
	die("INSUFFICIENT_PARAMS");
}

// The default device location is /dev/
$devLocation = "/dev/";
// But if we're running in docker...
if (file_exists("/.dockerenv")) {
	// Change it to /mnt/docker/.
	$devLocation = "/mnt/docker/";
}

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
// Later on, when we union-mount directories, they will be of the form "/tmp/${device}.fuzzy/content".
if (strpos($union, "/tmp/${device}.fuzzy/content") !== false) {
	die("ALREADY_MOUNTED");
}
// If we're handling a file (zip) request:
if ($hasfile) {
	// Watch carefully: we're going to transparently unzip/mount that gamezip without *ever* touching persistent storage.
	// Okay, we're mounting it. Log that.
	error_log("Mounting ${devLocation}${device}");
	// Make a folder for the gamezip contents to be fuzzy-mounted at.
	exec("mkdir /tmp/${device} /tmp/${device}.fuzzy");
	// fuse-archive will allow us to transparently access the contents of a zip.
	exec("sudo fuse-archive ${devLocation}${device} /tmp/${device} -o allow_other");
	// Fuzzy-mounting is just a case-insensitive bind-mount.
	exec("sudo fuzzyfs /tmp/${device} /tmp/${device}.fuzzy -o allow_other");
	// The location of the hypothetical content folder.
	$content = "/tmp/${device}.fuzzy/content";

// Else: we are handling a nonzip request.
} else {
	// Find the folder path in which the file should be located.
	// We find the last instance of "/", and set its index to that.
	$lastpos = strrpos($nzloc, "/");
	// Check that we found it. This will bail out if $lastpos is
	// either unset or zero.
	if (!$lastpos) {
		http_response_code(400);
		die("INVALID_NZLOC");
	}
	// Get a substring from the beginning to right before the last slash.
	$treepath = substr($nzloc, 0, $lastpos);
	// Create the tree for the file to be symlinked into, and a fuzzyfs mountpoint.
	// For the sake of consistency, I'm keeping the /content subfolder.
	// Also, it ensures that the already-mounted check works.
	exec("mkdir -p /tmp/${device}/content/${treepath} /tmp/${device}.fuzzy");
	// Symlink it to the right place.
	exec("ln -s ${devLocation}${device} /tmp/${device}/content/${nzloc}");
	// Fuzzy-mount it. Note: this makes it readonly.
	exec("sudo fuzzyfs /tmp/${device} /tmp/${device}.fuzzy -o allow_other");
	// The location of the content folder that we just created.
	$content = "/tmp/${device}.fuzzy/content");
}

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
