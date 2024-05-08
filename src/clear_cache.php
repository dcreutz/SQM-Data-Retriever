<?php
/*	clear_cache.php
	SQM Data Retriever
	(c) 2024 Darren Creutz
	Licensed under the GNU AFFERO GENERAL PUBLIC LICENSE */

/*	Browser loadable script to clear the cache
	Recommended to be disabled so non-admin users cannot access it
	or else password protected
	
	note: it will block until it obtains the cache lock */
$disable = true;


require_once('load_config.php');
require_once('sqm_cache.php');
$path = is_dir($data_directory) ? "" : getcwd() . DIRECTORY_SEPARATOR;
if (!$disable && isset($cache_directory) && $cache_directory) {
	$cache_directory_path = $path . $cache_directory;
	if ((is_dir($cache_directory_path)) && (is_writeable($cache_directory_path))) {
		SQM_Cache_Factory::initialize($cache_directory_path,true);
		SQM_Cache_Factory::clear_cache();
		echo "Cache cleared";
	} else {
		echo "Cache directory is not writeable";
	}
} else {
	echo "Script disabled";
}
?>