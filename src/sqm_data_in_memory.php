<?php
/*	sqm_data_in_memory.php
	SQM Data Retriever
	(c) 2024 Darren Creutz
	Licensed under the GNU AFFERO GENERAL PUBLIC LICENSE v3 */
	
/*	Implementation of SQM_Data that resides in memory

	used as the primary storage for datasets when cacheing is disabled
	also used to pass readings from the fileset to the dataset */
class SQM_Data_In_Memory extends SQM_Data {
	public $datetimes_by_date;
	public $values_by_date;
	public $attributes_by_date;
	private $sqm_id;
	
	public function __construct($sqm_id = ".") {
		$this->sqm_id = $sqm_id;
		$this->datetimes_by_date = array();
		$this->values_by_date = array();
		$this->attributes_by_date = array();
	}
	
	public function sqmid() {
		return $this->sqm_id;
	}
	
	// create an SQM_Data object from raw fileset readings
	public static function create_from($readings_string_key_array,$time_zone) {
		$result = new SQM_Data_In_Memory();
		foreach ($readings_string_key_array as $datetime_string => $value_string) {
			// actually convert the strings from the files to DateTime objects
			$datetime = new DateTimeImmutable($datetime_string,$time_zone);
			$date = $datetime->modify("-12 hours")->format("Y-m-d");
			if (!isset($result->datetimes_by_date[$date])) {
				$result->datetimes_by_date[$date] = array();
				$result->values_by_date[$date] = array();
				$result->attributes_by_date[$date] = array();
			}
			array_push($result->datetimes_by_date[$date],$datetime);
			array_push($result->values_by_date[$date],floatval($value_string));
			array_push($result->attributes_by_date[$date],array());
		}
		return $result;
	}
	
	public function dates() {
		return array_keys($this->datetimes_by_date);
	}
	
	public function datetimes_and_values_by_date($date,$with_attributes = true) {
		if (isset($this->datetimes_by_date[$date])) {
			if ($with_attributes) {
				return array(
					'datetimes'=>$this->datetimes_by_date[$date],
					'values'=>$this->values_by_date[$date],
					'attributes'=>$this->attributes_by_date[$date]
				);
			} else {
				return array(
					'datetimes'=>$this->datetimes_by_date[$date],
					'values'=>$this->values_by_date[$date]
				);
			}
		} else {
			return $this->empty();
		}
	}
	
	protected function set_datetimes_and_values_by_date($date,$datetimes_and_values) {
		$this->datetimes_by_date[$date] = $datetimes_and_values['datetimes'];
		$this->values_by_date[$date] = $datetimes_and_values['values'];
	}
	
	public function set_attributes_by_date($date,$attributes) {
		$this->attributes_by_date[$date] = $attributes;
	}
}

/*	Implementation of SQM_Best_Nightly_Data storing everything in memory */
class SQM_Best_Nightly_Data_In_Memory extends SQM_Best_Nightly_Data {
	private $datetimes;
	private $values;
	private $attributes;
	
	public function __construct() {
		$this->datetimes = array();
		$this->values = array();
		$this->attributes = array();
	}
	
	public function get_datetimes_and_values() {
		return array(
			'datetimes'=>$this->datetimes,
			'values'=>$this->values,
			'attributes'=>$this->attributes
		);
	}
	
	public function set_best_nightly_readings($datetimes,$values,$attributes) {
		foreach ($datetimes as $date => $datetime) {
			$this->datetimes[$date] = $datetime;
			$this->values[$date] = $values[$date];
			$this->attributes[$date] = $attributes[$date];
		}
		ksort($this->datetimes);
		ksort($this->values);
		ksort($this->attributes);
	}
}

class SQM_Data_In_Memory_Factory extends SQM_Data_Factory {
	public static function initialize() {
		SQM_Data_Factory::$instance = new SQM_Data_In_Memory_Factory();
	}
	
	protected function build_sqm_data($sqm_id) {
		return new SQM_Data_In_Memory($sqm_id);
	}
	
	protected function build_best_nightly_data($sqm_id) {
		return new SQM_Best_Nightly_Data_In_Memory();
	}
}
?>
