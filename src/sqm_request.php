<?php
/*	sqm_request.php
	SQM Data Retriever
	(c) 2024 Darren Creutz
	Licensed under the GNU AFFERO GENERAL PUBLIC LICENSE v3 */
	
/*	Class representing a request object as defined in SQM_Responder */
abstract class SQM_Request {
	/*	actually process the request represented on the given dataset
		returns an SQM_Readings object */
	protected abstract function process_one($dataset_manager);
	
	/*	returns the type of the request as a string */
	public abstract function type();
	
	public function process($dataset_managers) {
		return array_map(function ($manager) { 
			return $this->process_one($manager);
		},$dataset_managers);
	}

	/*	to avoid injection vulnerabilities, every part of the request sent is validated */
	public static function validate($request) {
		if (!isset($request['type'])) {
			return SQM_Request::bad_request("No request type",$request);
		}
		$type = $request['type'];
		switch ($request['type']) {
			case 'nightly':
			case 'night':
			case 'daily':
			case 'day':
			case 'date':
				$date = SQM_Request::validate_datetimestring($request['date']);
				if (!$date) {
					return SQM_Request::bad_request("Invalid date string",$request);
				}
				break;
			case 'best_nightly_readings':
			case 'all_readings':
				if ((!isset($request['start'])) || (!$request['start'])) {
					$start = null;
				} else {
					$start = SQM_Request::validate_datetimestring($request['start']);
					if (!$start) {
						return SQM_Request::bad_request("Invalid start datetime string",$request);
					}
				}
				if ((!isset($request['end'])) || (!$request['end'])) {
					$end = null;
				} else {
					$end = SQM_Request::validate_datetimestring($request['end']);
					if (!$end) {
						return SQM_Request::bad_request("Invalid end datetime string",$request);
					}
				}
				break;
		}
		switch ($request['type']) {
			case 'info':
				return new SQM_Info_Request();
			case 'readings_range':
				return new SQM_Readings_Range_Request();
			case 'current':
				return new SQM_Current_Request();
			case 'tonight':
				return new SQM_Tonight_Request();
			case 'nightly':
			case 'night':
				if (isset($request['twilightType'])) {
					$twilight_type =
						SQM_Nightly_Request::validate_twilight_type($request['twilightType']);
				}
				if (!isset($twilight_type) || (!$twilight_type)) {
					global $default_twilight_type;
					$twilight_type = $default_twilight_type;
				}
				return new SQM_Nightly_Request($date->format("Y-m-d"),$twilight_type);
			case 'daily':
			case 'day':
			case 'date':
				return new SQM_Daily_Request($date->format("Y-m-d"));
			case 'best_nightly_readings':
				return new SQM_Best_Nightly_Readings_Request(
					$start ? $start->format("Y-m-d") : null,
					$end ? $end->format("Y-m-d") : null
				);
			case 'all_readings':
				if ((!$start) || (!$end)) {
					return SQM_Request::bad_request("No start or end datetime",$request);
				}
				return new SQM_All_Readings_Request($start,$end);
			default:
				return SQM_Request::bad_request("Invalid request type:",$request);
		}
	}
	
	public static function bad_request($message,$request) {
		return new SQM_Bad_Request($message);
	}
	
	protected static function validate_datetimestring($string) {
		$datetime = DateTimeImmutable::createFromFormat("Y-m-d",$string);
		if ($datetime) { return $datetime->setTime(12,0); }
		$datetime = DateTimeImmutable::createFromFormat("Y-m-d H:i",$string);
		if ($datetime) { return $datetime; }
		$datetime = DateTimeImmutable::createFromFormat("Y-m-d H:i:s",$string);
		if ($datetime) { return $datetime; }
		try {
			return new DateTimeImmutable($string);
		} catch (Exception $e) {
			return false;
		}
	}
}

// represents a request that could not be validated
class SQM_Bad_Request extends SQM_Request {
	private $message;
	
	public function __construct($message = "Invalid request") {
		$this->message = $message;
	}

	protected function process_one($dataset_managers) {
		return array('message'=>$this->message);
	}
	
	public function type() {
		return 'failure';
	}
}

// represents a request of type 'info', see SQM_Responder
class SQM_Info_Request extends SQM_Request {
	protected function process_one($dataset_manager) {
		return $dataset_manager->sqm_info();
	}
	
	public function type() {
		return 'info';
	}
}

// represents a request of type 'readings_range', see SQM_Responder
class SQM_Readings_Range_Request extends SQM_Request {
	protected function process_one($dataset_manager) {
		return $dataset_manager->readings_range();
	}
	
	public function type() {
		return 'readings_range';
	}
}

// represents a request of type 'nightly', see SQM_Responder
class SQM_Nightly_Request extends SQM_Request {
	private $date;
	private $twilight_type;
	
	public function __construct($date,$twilight_type) {
		$this->date = $date;
		$this->twilight_type = $twilight_type;
	}
	
	protected function process_one($dataset_manager) {
		return $dataset_manager->nightly_readings($this->date,$this->twilight_type);
	}
		
	public static function validate_twilight_type($twilight_type) {
		if (in_array($twilight_type,[ 'civil', 'astronomical', 'nautical', 'night' ])) {
			return $twilight_type;
		}
		return null;
	}
	
	public function type() {
		return 'all_readings';
	}
}

// represents a request of type 'daily'
class SQM_Daily_Request extends SQM_Request {
	private $date;
	
	public function __construct($date) {
		$this->date = $date;
	}
	
	protected function process_one($dataset_manager) {
		return $dataset_manager->daily_readings($this->date);
	}
	
	public function type() {
		return 'all_readings';
	}
}

// represents a request of type 'best_nightly_readings'
class SQM_Best_Nightly_Readings_Request extends SQM_Request {
	private $start;
	private $end;
	
	public function __construct($start,$end) {
		$this->start = $start;
		$this->end = $end;
	}
	
	protected function process_one($dataset_manager) {
		return $dataset_manager->best_nightly_readings_in_range($this->start,$this->end);
	}
	
	public function type() {
		return 'best_nightly_readings';
	}
}

// represents a request of type 'all_readings'
class SQM_All_Readings_Request extends SQM_Request {
	private $start;
	private $end;
	
	public function __construct($start,$end) {
		$this->start = $start;
		$this->end = $end;
	}
	
	protected function process_one($dataset_manager) {
		return $dataset_manager->all_readings_in_range(
			$this->start->setTimezone($dataset_manager->sqm_info->time_zone),
			$this->end->setTimezone($dataset_manager->sqm_info->time_zone)
		);
	}
	
	public function type() {
		return 'all_readings';
	}
}
?>
