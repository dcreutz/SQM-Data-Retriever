<?php
/*	sqm_dataset_manager.php
	SQM Data Retriever
	(c) 2024 Darren Creutz
	Licensed under the GNU AFFERO GENERAL PUBLIC LICENSE */
	
/*	Manages the interaction of the SQM_Request objects with a given SQM_Dataset
	and the interaction between the SQM_Dataset and corresponding SQM_Fileset */
interface SQM_Dataset_Manager {
	//	return an SQM_Info object describing the sqm dataset
	public function sqm_info();
	
	//	return an SQM_Readings object containing the earliest and latest readings
	public function readings_range();
	
	/*	return an SQM_Readings object containing all readings for a given date
	
		$date is a string formatted as YYYY-mm-dd
		interpreted as noon that date to noon the next in the SQM's timezone */
	public function daily_readings($date);
	
	/*	return an SQM_Readings object containing all readings between sunset of the
		given date and sunrise of the next day at the SQM's location
		
		$date is a string formatted as YYYY-mm-dd
		$twilight_type is one of 'civil', 'nautical', 'astronomical' */
	public function nightly_readings($date,$twilight_type);
	
	/*	return an SQM_Readings object containing all readings in the given range
	
		$start_datetime and $end_datetime are DateTimeInterface objects */
	public function all_readings_in_range($start_datetime,$end_datetime);
	
	/*	return an SQM_Readings object containing the best nightly readings for all dates
		in the given range
		
		$start_date and $end_date are strings formatted as YYYY-mm-dd
		
		the best reading for a given date happens between noon that date and noon
		the next day, both in the SQM's timezone */
	public function best_nightly_readings_in_range($start_date,$end_date);
}

require_once('sqm_dataset.php');
require_once('sqm_fileset.php');
require_once('sqm_info.php');
require_once('sqm_sun_moon_info.php');

class SQM_Dataset_Manager_Implementation implements SQM_Dataset_Manager {
	private $dataset;
	private $fileset;
	public $sqm_info;
	private $sqm_sun_moon_info;
	
	public function __construct($dataset,$fileset) {
		$this->dataset = $dataset;
		$this->fileset = $fileset;
		$this->sqm_info = $this->fileset->sqm_info();
		$this->sqm_sun_moon_info = new SQM_Sun_Moon_Info(
			$this->sqm_info->latitude,$this->sqm_info->longitude,$this->sqm_info->time_zone
		);
		$this->dataset->set_sqm_sun_moon_info($this->sqm_sun_moon_info);
	}
	
	public function sqm_info() {
		return $this->sqm_info;
	}
	
	public function readings_range() {
		$fileset_answer = $this->fileset->earliest_and_latest_readings();
		if ($fileset_answer) { // if the fileset thinks it should guess, it should
			return SQM_Readings::from_earliest_and_latest(
				new DateTimeImmutable($fileset_answer['earliest']['datetime']),
				new DateTimeImmutable($fileset_answer['latest']['datetime']),
				floatval($fileset_answer['earliest']['value']),
				floatval($fileset_answer['latest']['value'])
			);
		}
		// if the fileset didn't guess, actually go to the data
		$this->load_data_from(...$this->fileset->files_for_latest());
		$this->load_data_from(...$this->fileset->files_for_earliest());
		$earliest = $this->dataset->earliest_reading();
		$latest = $this->dataset->latest_reading();
		return SQM_Readings::from_earliest_and_latest(
			$earliest['datetime'],$latest['datetime'],$earliest['value'],$latest['value']
		);
	}
	
	public function nightly_readings($date,$twilight_type) {
		$sunset_to_sunrise = $this->sqm_sun_moon_info->sunset_to_sunrise($date,$twilight_type);
		return $this->all_readings_in_range(
			$sunset_to_sunrise['sunset'],
			$sunset_to_sunrise['sunrise']
		);
	}
	
	public function daily_readings($date) {
		$this->load_data_for($date,$date);
		return $this->dataset->all_readings_for_date($date);
	}
	
	public function all_readings_in_range($start_datetime,$end_datetime) {
		$this->load_data_for(
			$start_datetime->format("Y-m-d"),
			(clone $end_datetime)->modify("+12 hours")->format("Y-m-d")
		);
		return $this->dataset->all_readings_in_range($start_datetime,$end_datetime);
	}
	
	public function best_nightly_readings_in_range($start_date,$end_date) { // YYYY-mm-dd
		if ((!$start_date) || (!$end_date)) {
			$this->load_all_data();
		} else {
			$this->load_data_for($start_date,$end_date);
		}
		return $this->dataset->best_nightly_readings_in_range($start_date,$end_date);
	}
	
	private function load_all_data() {
		$this->load_data_from(...$this->fileset->all_files());
	}
	
	private function load_data_for($start_date,$end_date) {
		$this->load_data_from(...$this->fileset->files_for($start_date,$end_date));
	}
	
	// iterate through the requested files to load data from looking for new readings
	// after each file, request additional time (if specified in config.php)
	private function load_data_from(...$files) {
		global $extended_time;
		foreach ($files as $file) {
			if ($extended_time) {
				set_time_limit(30);
			}
			$changed_readings = $this->fileset->new_readings_from($file);
			if ($changed_readings) {
				$this->dataset->remove_readings(
					SQM_Data_In_Memory::create_from(
						$changed_readings['remove'],
						$this->sqm_info->time_zone
					)
				);
				$this->dataset->add_readings(
					SQM_Data_In_Memory::create_from(
						$changed_readings['add'],
						$this->sqm_info->time_zone
					)
				);
			}
		}
	}
}
?>
