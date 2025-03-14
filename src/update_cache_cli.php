<?php
/*	update_cache_cli.php
	SQM Data Retriever
	(c) 2024 Darren Creutz
	Licensed under the GNU AFFERO GENERAL PUBLIC LICENSE v3 */

/*	command line script to update the cache
	
	best practice is to use this script after installation if using cacheing
	designed to be triggered by a cron job after that

	note: it will block until it obtains the cache lock
	and the cache will not be cleared if an error occurs */

if (PHP_VERSION_ID < 80000) {
	echo "PHP version 8 or later is required\n";
	exit();
}

if (php_sapi_name() != 'cli') {
	echo "This script is for the command line\n";
	exit();
}

function sqm_error_log($msg) {
	echo $msg . "\n";
}
if (!file_exists("sqm.php")) {
	echo "This script must be run from the directory containing config.php\n";
	exit();
}

require_once('load_config.php');
$trust_files = false;
$should_block_for_cacheing = true;
$clear_cache_on_errors = false;
$is_cli = true;

$sqm_memory_limit_if_cacheing = '1G';
$extended_time = false;

include('initialize_sqm_responder.php');

function request($request) {
	global $sqm_responder;
	$sqm_responder->respond_to(array('request'=>$request));
}

request(array('type' => 'info'));
request(array('type' => 'readings_range'));
request(array('type' => 'best_nightly_readings'));

echo "Cache updated" . PHP_EOL;
echo "If you did not run this script as the same user the web server runs as, either chown the cache to the web server user or perform chmod -R a+rwx on the cache directory" . PHP_EOL;

?>