<?php
/*	sqm_dataset.php
	SQM Data Retriever
	(c) 2024 Darren Creutz
	Licensed under the GNU AFFERO GENERAL PUBLIC LICENSE v3 */
	
/*	The actual dataset object
	
	this class handles the actual transition from text readings to validated datetimes
	and handles the task of determining what readings fall into a given range
	
	uses an SQM_Data object to actually store the data of all readings
	and an SQM_Best_Nightly_Readings object to store the best nightly readings */
require_once('sqm_readings.php');
require_once('sqm_data.php');
require_once('sqm_date_utils.php');
require_once('sqm_sun_moon_info.php');

// all dates in strings are in SQM's timezone
interface SQM_Dataset {
	// first three return SQM_Readings object
	
	/*	returns all readings in the given range as an SQM_Readings object
	
		$start_datetime and $end_datetime are DateTimeInterface objects */
	public function all_readings_in_range($start_datetime,$end_datetime);
	
	/*	returns the best nightly readings in the given range as an SQM_Readings object
	
		$start_date and $end_date are strings formatted as YYYY-mm-dd
		
		if start_date and end_date are null, all available data is returned
		if start_date only is null, all available data until end_date is returned
		if end_date is null, all data from start_date onward is returned */
	public function best_nightly_readings_in_range($start_date = null,$end_date = null);
	
	/*	returns all readings for a given date as an SQM_Readings object
	
		$date is a string formatted YYYY-mm-dd
		the range is noon on the given day to noon the next in the SQM's timezone */
	public function all_readings_for_date($date);
	
	// add an SQM_Data object of readings to the dataset
	public function add_readings($readings);
	
	// remove an SQM_Data object of readings from the dataset
	public function remove_readings($readings);
	
	// return the DateTime object and value of the latest reading
	public function latest_reading();
		
	public function earliest_reading();
}

class SQM_Dataset_Implementation implements SQM_Dataset {
	private $sqm_data;
	private $best_nightly_readings;
	private $sqm_sun_moon_info;
	private $sqm_data_attributes;
	private $sqm_fileset;
	
	public function __construct($sqm_data,$best_nightly_readings,$sqm_data_attributes) {
		$this->sqm_data = $sqm_data;
		$this->best_nightly_readings = $best_nightly_readings;
		$this->sqm_sun_moon_info = null;
		$this->sqm_data_attributes = $sqm_data_attributes;
		$this->sqm_data_attributes->set_data($this->sqm_data);
	}
	
	// the dataset manager has to determine the latitude and longitude to build this
	// as such, it's not available when this object is constructed
	public function set_sqm_sun_moon_info($sqm_sun_moon_info) {
		$this->sqm_sun_moon_info = $sqm_sun_moon_info;
		$this->sqm_data_attributes->set_sqm_sun_moon_info($sqm_sun_moon_info);
	}
	
	// the dataset manager owns the fileset
	// as such, it's not available when this object is constructed
	public function set_fileset($fileset) {
		$this->sqm_fileset = $fileset;
		$this->sqm_data_attributes->set_fileset($fileset);
	}
	
	public function all_readings_in_range($start_datetime,$end_datetime) { // DateTime objects
		$datetimes = array();
		$values = array();
		$attributes = array();
		foreach (SQM_Date_Utils::dates_in_range($start_datetime,$end_datetime) as $date) {
			$datetimes_and_values = $this->sqm_data->datetimes_and_values_by_date($date);
			foreach ($datetimes_and_values['datetimes'] as $id => $datetime) {
				if (($datetime >= $start_datetime) && ($datetime <= $end_datetime)) {
					array_push($datetimes,$datetime);
					array_push($values,$datetimes_and_values['values'][$id]);
					array_push($attributes,$datetimes_and_values['attributes'][$id]);
				}
			}
		}
		return new SQM_Readings('all_readings',
			$datetimes,$values,$attributes,$start_datetime,$end_datetime
		);
	}
	
	public function best_nightly_readings_in_range($start_date=null,$end_date=null) { // YYYY-mm-dd
		if ((!$start_date) && (!$end_date)) {
			$start_datetime = SQM_Date_Utils::datetime_from_date_string(
				min($this->sqm_data->dates())
			);
			$end_datetime = SQM_Date_Utils::datetime_from_date_string(
				max($this->sqm_data->dates())
			);
			$best_datetimes_and_values = $this->best_nightly_readings->get_datetimes_and_values();
			return new SQM_Readings(
				'best_nightly_readings',
				$best_datetimes_and_values['datetimes'],
				$best_datetimes_and_values['values'],
				$best_datetimes_and_values['attributes'],
				$start_datetime,
				$end_datetime
			);
		}
		// clone the objects since the caller may need them not to change
		if (!$start_date) {
			$start_date = (clone $this->earliest_datetime())->modify("-12 hours")->format("Y-m-d");
		}
		if (!$end_date) {
			$end_date = (clone $this->leatest_datetime())->modify("+12 hours")->format("Y-m-d");
		}
		$datetimes = array();
		$values = array();
		$attributes = array();
		$start_datetime = SQM_Date_Utils::datetime_from_date_string($start_date);
		$end_datetime = SQM_Date_Utils::datetime_from_date_string($end_date);
		$datetimes_and_values = $this->best_nightly_readings->get_datetimes_and_values();
		foreach (SQM_Date_Utils::dates_in_range($start_datetime,$end_datetime) as $date) {
			if (isset($datetimes_and_values['datetimes'][$date])) {
				$datetimes[$date] = $datetimes_and_values['datetimes'][$date];
				$values[$date] = $datetimes_and_values['values'][$date];
				$attributes[$date] = $datetimes_and_values['attributes'][$date];
			}
		}
		return new SQM_Readings(
			'best_nightly_readings',
			$datetimes,
			$values,
			$attributes,
			$start_datetime,
			$end_datetime
		);
	}
	
	public function all_readings_for_date($date) {
		$datetimes_and_values = $this->sqm_data->datetimes_and_values_by_date($date);
		return new SQM_Readings(
			'all_readings',
			$datetimes_and_values['datetimes'],
			$datetimes_and_values['values'],
			$datetimes_and_values['attributes'],
			SQM_Date_Utils::datetime_from_date_string($date),
			SQM_Date_Utils::datetime_from_date_string($date)->modify("+1 day")
		);
	}
	
	// remove the data from the dataset and recompute attributes accordingly
	public function remove_readings($readings) {
		$this->sqm_data->remove($readings);
		foreach ($readings->dates() as $date) {
			$this->sqm_data_attributes->compute_attributes_for_date($date);
		}
		$this->cleanup_best_nightly_readings($readings);
	}
	
	// add the new data to the dataset and compute its attributes
	public function add_readings($readings) {
		$this->sqm_data->add($readings);
		foreach ($readings->dates() as $date) {
			$this->sqm_data_attributes->compute_attributes_for_date($date);
		}
		$this->cleanup_best_nightly_readings($readings);
	}
	
	public function latest_reading() {
		if (count($this->sqm_data->dates()) > 0) {
			$latest_date = max($this->sqm_data->dates());
			$datetimes_and_values = $this->sqm_data->datetimes_and_values_by_date($latest_date);
			if (count($datetimes_and_values) > 0) {
				$latest_datetime = max($datetimes_and_values['datetimes']);
				$latest_value = $datetimes_and_values['values'][
					array_search($latest_datetime,$datetimes_and_values['datetimes'])
				];
				return array('datetime'=>$latest_datetime,'value'=>$latest_value);
			}
		}
		return array('datetime'=>null,'value'=>null);
	}
	
	public function earliest_reading() {
		if (count($this->sqm_data->dates()) > 0) {
			$earliest_date = min($this->sqm_data->dates());
			$datetimes_and_values = $this->sqm_data->datetimes_and_values_by_date($earliest_date);
			if (count($datetimes_and_values) > 0) {
				$earliest_datetime = min($datetimes_and_values['datetimes']);
				$earliest_value = $datetimes_and_values['values'][
					array_search($earliest_datetime,$datetimes_and_values['datetimes'])
				];
				return array('datetime'=>$earliest_datetime,'value'=>$earliest_value);
			}
		}
		return array('datetime'=>null,'value'=>null);
	}
	
	// (re)compute the best nightly readings after data has changed
	// also (re)compute the attributes for the best nightly
	private function cleanup_best_nightly_readings($changed_readings) {
		$dates = $changed_readings->dates();
		if (count($dates) > 0) {
			$best_nightly_readings_datetimes = array();
			$best_nightly_readings_values = array();
			$best_nightly_readings_attributes = array();
			global $default_twilight_type;
			foreach ($dates as $date) {
				$datetimes_and_values = $this->sqm_data->datetimes_and_values_by_date($date);
				$best_nightly_readings_values[$date] = max($datetimes_and_values['values']);
				$id = array_search(
					$best_nightly_readings_values[$date],$datetimes_and_values['values']
				);
				$best_nightly_readings_datetimes[$date] = $datetimes_and_values['datetimes'][$id];
				$best_nightly_readings_attributes[$date] =
					$this->sqm_data_attributes->for_best_nightly_reading(
						$date,
						$id,
						$best_nightly_readings_datetimes[$date],
						$datetimes_and_values['datetimes'],
						$datetimes_and_values['values'],
						$datetimes_and_values['attributes']
					);
			}
			$this->best_nightly_readings->set_best_nightly_readings(
				$best_nightly_readings_datetimes,
				$best_nightly_readings_values,
				$best_nightly_readings_attributes
			);
		}
	}
}
?>
