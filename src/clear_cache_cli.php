<?php
/*	clear_cache_cli.php
	SQM Data Retriever
	(c) 2024 Darren Creutz
	Licensed under the GNU AFFERO GENERAL PUBLIC LICENSE */

/*	command line script to clear the cache

	note: it will block until it obtains the cache lock */

if (php_sapi_name() != 'cli') {
	echo "This script is for the command line";
	exit();
}

function sqm_error_log($msg) {
	echo $msg;
}
if (!file_exists("sqm.php")) {
	echo "This script must be run from the directory containing config.php";
	exit();
}

require_once('load_config.php');
require_once('sqm_cache.php');
$path = is_dir($data_directory) ? "" : getcwd() . DIRECTORY_SEPARATOR;
if (isset($cache_directory) && $cache_directory) {
	$cache_directory_path = $path . $cache_directory;
	if ((is_dir($cache_directory_path)) && (is_writeable($cache_directory_path))) {
		SQM_Cache_Factory::initialize($cache_directory_path,true);
		SQM_Cache_Factory::clear_cache();
		echo "Cache cleared" . PHP_EOL;
	} else {
		echo "Cache directory is not writeable" . PHP_EOL;
	}
} else {
	echo "No cache directory found" . PHP_EOL;
}
?>