<?php
/*	sqm_responder.php
	SQM Data Retriever
	(c) 2024 Darren Creutz
	Licensed under the GNU AFFERO GENERAL PUBLIC LICENSE v3 */
	
/*	Responsible for actually responding to the api request

	Requests must be of the form
		{ request:
			{
				type: string,
				sqm_ids: array,
				date: string,
				start: string,
				end: string,
				datetime_format: string,
				twilight_type
			}
		}
	where type is mandatory and must be one of
		'info', 'readings_range', 'all_readings', 'best_nightly_readings',
			'nightly', 'night', 'daily', 'day', 'date'
	all other parameters are optional
		sqm_ids is an array of names of subdirectories of the data directory to query
		date is a string in YYYY-MM-DD specifying which date to use for nightly readings
		start and end are in YYYY-MM-DD or YYYY-MM-DD HH:ii:ss format
			specifying the range to return readings from
		datetime_format is a format accepted by DateTime::createFromFormat specifying
			how datetimes are sent back
		twilight_type specifies which twilight to use for nightly readings
	
	'info' returns the info on the available sqm datasets
	'readings_range" returns the earliest and latest reading
	'all_readings' returns all readings between start and end (mandatory start and end)
	'best_nightly_readings' returns the best reading per night between start and end
		if start and end are not specified, all available data will be returned
	'nightly' or 'night' returns the readings between sunset and sunrise for the date given by date
		if date is not specified, the most recent sunset will be used
	'daily','day','date' returns all readings from noon of the given date to noon following
	
	The response will be of the form
		{ response:
			{
				type: string,
				startDatetime: string,
				endDatetime: string,
				readings: array
			}
		}
	where type is as above
	startDatetime and endDatetime are the actual range returned
	
	readings is a key value array keyed by datetime string in the format
		requested or in YYYY-MM-DD HH:mm:ii format if not specified
	
	readings[datetime].reading is the actual msas value
	readings[datetime].PROPERTY is the value of PROPERTY for that reading
	
	where PROPERTY is e.g. sun_position or mean_r_squared
	
	
	Multiple requests can be packaged together as follows
		{ request:
			{
				queries:
					{
						query1: {
							type: string,
							parameters
						},
						query2 {
							type: stirng,
							parameters
						}
					},
				sqm_ids
			}
		}
*/
require_once('sqm_dataset_manager_factory.php');
require_once('sqm_request.php');

class SQM_Responder {
	private $dataset_managers;
	
	public function __construct() {
		$this->dataset_managers = array();
	}
	
	// actually process the request object
	public function respond_to($post_data) {
		if (!isset($post_data['request'])) {
			$bad_request = SQM_Request::bad_request("No request(s)",$post_data);
			$response = $bad_request->process([null]);
			return array(
				'response'=>array('type'=>$bad_request->type(),'message'=>"No request(s)")
			);
		}
		$request = $post_data['request'];
		SQM_Readings::$datetime_format = SQM_Responder::requested_datetime_format($request);
		$sqm_ids = $this->sqm_ids_from($request);
		$valid_sqm_ids = $sqm_ids['valid'];
		$invalid_sqm_ids = $sqm_ids['invalid'];
		/*	an sqm_id is deemed invalid if the SQM_File_Manager_Factory is not aware of them
			to avoid injection vulnerabilities, do not try to build them
			valid sqm_ids may end up not being buildable either
			but should be attempted to be built */
		$dataset_managers = array();
		foreach ($valid_sqm_ids as $sqm_id) {
			$dataset_managers[$sqm_id] = $this->dataset_manager_for($sqm_id);
			if (!$dataset_managers[$sqm_id]) {
				array_push($invalid_sqm_ids,$sqm_id);
			}
		}
		if (isset($request['queries'])) {
			$responses = array_map(function ($query) use ($dataset_managers,$invalid_sqm_ids) {
				$response = $this->respond_to_one($dataset_managers,$query);
				foreach ($invalid_sqm_ids as $sqm_id) {
					$response[$sqm_id] = null;
				}
				return $response;
			},$request['queries']);
			return array('response'=>$responses);
		}
		$response = $this->respond_to_one($dataset_managers,$post_data['request']);
		foreach ($invalid_sqm_ids as $sqm_id) {
			$response[$sqm_id] = null;
		}
		return array('response'=>$response);
	}
	
	// respond to a single request for a single dataset
	public function respond_to_one($dataset_managers,$request) {
		$validated = SQM_Request::validate($request);
		$processed = $validated->process($dataset_managers);
		$processed['type'] = $validated->type();
		return $processed;
	}
	
	// return an array of sqm_ids from the request
	private function sqm_ids_from($request) {
		if ((isset($request['sqm_ids'])) && (!is_array($request['sqm_ids']))) {
			$request['sqm_ids'] = array($request['sqm_ids']);
		}
		if (isset($request['sqm_id'])) {
			$request['sqm_ids'] = array($request['sqm_id']);
		}
		if ((!isset($request['sqm_ids'])) || (count($request['sqm_ids']) == 0)) {
			$valid_sqm_ids = $this->available_sqms();
			$invalid_sqm_ids = array();
		} else {
			$available_sqm_ids = SQM_File_Manager_Factory::available_sqm_ids();
			$valid_sqm_ids = array_filter($request['sqm_ids'],
				function ($sqm_id) use ($available_sqm_ids) {
					return in_array($sqm_id,$available_sqm_ids);
				}
			);
			$invalid_sqm_ids = array_diff($request['sqm_ids'],$valid_sqm_ids);
		}
		return array('valid'=>$valid_sqm_ids,'invalid'=>$invalid_sqm_ids);
	}
	
	// return the dataset manager for the given sqm_id
	private function dataset_manager_for($sqm_id) {
		if ((!isset($this->dataset_managers[$sqm_id])) || (!$this->dataset_managers[$sqm_id])) {
			$this->dataset_managers[$sqm_id] = SQM_Dataset_Manager_Factory::create($sqm_id);
		}
		return $this->dataset_managers[$sqm_id];
	}
	
	private function available_sqms() {
		$available_sqms = array();
		foreach (SQM_File_Manager_Factory::available_sqm_ids() as $sqm_id) {
			$sqm_dataset_manager = $this->dataset_manager_for($sqm_id);
			if ($sqm_dataset_manager) {
				$this->dataset_managers[$sqm_id] = $sqm_dataset_manager;
				array_push($available_sqms,$sqm_id);
			}
		}
		return $available_sqms;
	}
	
	// to guard against code injection, require the format to match a known one
	// caller can always just use U or U.u and then format at will
	private static function requested_datetime_format($request) {
		if (isset($request['datetime_format'])) {
			if (in_array($request['datetime_format'],[ "Y-m-d H:i", "Y-m-d H:i:s", "U", "U.u" ])) {
				return $request['datetime_format'];
			}
		}
		return "Y-m-d H:i:s";
	}
}
?>
