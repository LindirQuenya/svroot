<?php
// Do not use this outside of a trusted environment!
if (file_exists("/tmp/wake")) {
	return;
}
exec("mkdir /tmp/htdocs");
exec("sudo curlftpfs 10.0.2.2:22400 /mnt/games -o allow_other,user=games:adobesucks");
$contents = file_get_contents("/proc/meminfo");
preg_match_all("/(\w+):\s+(\d+)\s/", $contents, $matches);
$info = array_combine($matches[1], $matches[2]);
$ramdisk = $info["MemTotal"] - 500000;
if ($ramdisk > (1 << 20)) {
	touch("/tmp/enable_ramdisk");
	// nr_inodes=0 forces the use of RAM only
	exec("sudo mount -t tmpfs -o size=${ramdisk}k,nr_inodes=0 tmpfs /mnt/ramdisk");
}
touch("/tmp/wake");
