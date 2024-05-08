<?php
/*	sqm.php
	SQM Data Retriever
	(c) 2024 Darren Creutz
	Licensed under the GNU AFFERO GENERAL PUBLIC LICENSE */
	
/*	the actual script to be HTTP POST requested
	also allows for preloading info and readings in index.php
	
	loads config.php via wrapper script
	then creates a global error handler (unless in debug mode)
	then decodes the post data as a request
	then initializes the SQM_Responder
	then passes the request to the responder
	and finally takes the response and returns it to the caller */
	
require_once('load_config.php');

if (isset($info_and_readings_preload) && $info_and_readings_preload === true) {

	$info_and_readings_request = array( 'queries' => array(
			'info' => array( 'type' => 'info' ),
			'readings_range' => array( 'type' => 'readings_range' )
		));
	$requests = array(
		'info_and_readings' => $info_and_readings_request
	);

}

include('error_handler.php');

if (isset($info_and_readings_preload) && $info_and_readings_preload === true) {

	$should_block_for_cacheing = false;

} else {

	$post_data = file_get_contents('php://input');
	
	if ($post_data == "") {
		echo json_encode(array('response'=>array('fail'=>true)));
		exit();
	}
	$request = json_decode($post_data,true);
	if (isset($request['block']) && $request['block'] === true) {
		$should_block_for_cacheing = true;
		unset($request['block']);
	} else {
		$should_block_for_cacheing = false;
	}

}

include('initialize_sqm_responder.php');

if (isset($info_and_readings_preload) && $info_and_readings_preload === true) {

	$responses = array();
	foreach ($requests as $key => $request) {
		$responses[$key] = $sqm_responder->respond_to(array('request' => $request));
	}
	// in case of error, output nothing
	if (!isset($responses['response']) || ($responses['response']['fail'] !== true)) {
		echo '<script type="text/javascript">';
		echo 'SQMRequest.preloadRequest(' . json_encode($info_and_readings_request) . ',' .  json_encode($responses['info_and_readings']) . ');';
		echo' </script>';
	}

} else {

	$response = $sqm_responder->respond_to($request);
	echo json_encode($response);

}
?>