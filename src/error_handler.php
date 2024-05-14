<?php
/*	error_handler.php
	SQM Data Retriever
	(c) 2024 Darren Creutz
	Licensed under the GNU AFFERO GENERAL PUBLIC LICENSE v3 */
	
/*	Sets up the global error handler when debugging is disabled
	and the recoverable error logging function */

require_once('sqm_cache.php');

if ($debug) {
	ini_set('display_errors', 1);
	ini_set('display_startup_errors', 1);
	error_reporting(E_ALL);
	error_log("SQM backend running in debug mode");
} else {

	function sqm_error_handler($errno, $errstr, $errfile, $errline, $errcontext = false) {
		sqm_error_log("SQM Data Retriever error: " . $errno . " " . $errstr . " "
					. $errfile . " " . $errline);
		global $debug;
		if ($debug && $errcontext) {
			sqm_error_log(print_r($errcontext,true));
		}
		return true;
	}
	
	function sqm_fatal_error_handler() {
		$error = error_get_last();
		if ($error !== NULL) {
			$errno   = $error["type"];
			$errfile = $error["file"];
			$errline = $error["line"];
			$errstr  = $error["message"];
			sqm_error_handler($errno,$errfile,$errline,$errstr);
			global $clear_cache_on_errors;
			if ($clear_cache_on_errors) {
				sqm_error_log("Clearing the cache due to errors");
				// Suppress all errors and warnings as we are in the error handler already
				@SQM_Cache_Factory::clear_cache();
			}
			global $suppress_output_on_error;
			if (!isset($suppress_output_on_error) || $suppress_output_on_error !== false) {
				echo json_encode(
					['response'=>array('fail'=>true,'message'=>"Something went wrong")]
				);
			}
		}
	}

	set_error_handler("sqm_error_handler");
	register_shutdown_function("sqm_fataL_error_handler");
}

function sqm_error_log($message,$level = 1) {
	if ($level == 0) {
		error_log("SQM Data Retriever:: " . $message);
	} elseif ($level == 1) {
		global $logging_enabled;
		if ($logging_enabled) {
			error_log("SQM Data Retriever:: " . $message);
		}
	} elseif ($level == 2) {
		global $cli_logging;
		if (isset($cli_logging) && $cli_logging) {
			error_log("SQM Data Retriever:: " . $message);
		}
	}
}
?>
