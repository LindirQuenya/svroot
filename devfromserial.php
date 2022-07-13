<?php
// Do not use this outside of a trusted environment!
// Gets the device name (sda1, vda2, etc.) from the serial number when in QEMU.
// When in docker, returns the input if it exists in /mnt/docker.
// Check: running in docker?
if (file_exists("/.dockerenv")) {
	function find_device_by_serial($serial) {
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
	}
} else {
	function find_device_by_serial($serial) {
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
?>
