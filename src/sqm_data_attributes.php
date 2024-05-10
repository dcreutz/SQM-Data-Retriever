<?php
/*	sqm_data_attributes.php
	SQM Data Retriever
	(c) 2024 Darren Creutz
	Licensed under the GNU AFFERO GENERAL PUBLIC LICENSE */
	
/*	Utility class for attaching attributes to SQM_Data objects

	SQM_Data_Attributes_Module subclasses are added via the include_module method
	all such subclasses are asked to attach attributes to data as it is processed
	the modules are guaranteed to be run in the order they are included
	
	to add new attributes, create a subclass of SQM_Data_Attributes_Module
	and then include the module from initialize_sqm_responder.php

	either method may be left unimplemented
	
	note that anytime best nightly attributes are computed, any attributes attached to the
	individual reading determined to be the best should probably be copied to the best reading
	attributes array but this is not mandatory */
abstract class SQM_Data_Attributes_Module {
	public static function add_attributes_from(
		&$attributes,$datetimes,$values,$sunset,$sunrise,$sqm_sun_moon_info,$fileset
	) {
	}
	
	public static function add_best_nightly_attributes(
		&$attributes,$date,$key,$datetime,$datetimes,$values,$night_attributes,
		$sqm_sun_moon_info,$fileset,$datetime_keys_at_night
	) {
	}
}

require_once('sqm_data_attributes_computer.php');

require_once('sqm_data_attributes_from_data_files.php');

class SQM_Data_Attributes {
	public static function initialize() {
		SQM_Data_Attributes::$modules = array(SQM_Data_Attributes_Number_Readings_Module::class);
	}
	
	public static function include_module($sqm_data_attributes_module) {
		global $debug;
		if ($debug) {
			error_log("Including data attributes module " . $sqm_data_attributes_module);
		}
		if (!in_array($sqm_data_attributes_module,SQM_Data_Attributes::$modules)) {
			array_push(SQM_Data_Attributes::$modules,$sqm_data_attributes_module);
		}
	}

	private static $modules;

	private $sqm_data;
	private $sqm_sun_moon_info;
	private $fileset;
	
	public function set_sqm_sun_moon_info($sqm_sun_moon_info) {
		$this->sqm_sun_moon_info = $sqm_sun_moon_info;
	}
	
	public function set_fileset($fileset) {
		$this->fileset = $fileset;
	}
	
	public function set_data($sqm_data) {
		$this->sqm_data = $sqm_data;
	}
	
	/*	compute the attributes for the given date and assign them to the associated SQM_Data
	
		$date is a string formatted as YYYY-mm-dd */
	public function compute_attributes_for_date($date) {
		$datetimes_and_values = $this->sqm_data->datetimes_and_values_by_date($date,false);
		$sunset_to_sunrise =
			$this->sqm_sun_moon_info->sunset_to_sunrise($date,'astronomical');
		$this->sqm_data->set_attributes_by_date($date,
			$this->attributes_from($datetimes_and_values['datetimes'],
								   $datetimes_and_values['values'],
								   $sunset_to_sunrise['sunset'],
								   $sunset_to_sunrise['sunrise']
			)
		);
	}
	
	// actually compute the attributes by iterating through the modules
	private function attributes_from($datetimes,$values,$sunset,$sunrise) {
		$attributes = array_map(function ($datetime) { return array(); },$datetimes);
		foreach (SQM_Data_Attributes::$modules as $module) {
			$module::add_attributes_from(
				$attributes,$datetimes,$values,$sunset,$sunrise,
				$this->sqm_sun_moon_info,$this->fileset
			);
		}
		return $attributes;
	}
	
	/*	compute the attributes for the best nightly readings for the given date
	
		$date is a string formatted as YYYY-mm-dd representing the night of the reading
		$key is the key in the $datetimes,$values,$night_attributes arrays corresponding 
		to the best nightly reading
		$datetime is the DateTimeInterface of the time of the best reading
		$datetimes is all DateTimeInterfaces for readings that day
		$values is paired to $datetimes and contains the msas of all readings that day
		$night_attributes are the attributes computed above for the inidividual readings */
	public function for_best_nightly_reading(
		$date,$key,$datetime,$datetimes,$values,$night_attributes
	) {
		$attributes = array('date'=>$date);
		
		$sunset_to_sunrise =
			$this->sqm_sun_moon_info->sunset_to_sunrise($date,'astronomical');
		$datetime_keys_at_night = array_filter(array_keys($datetimes),
			function ($key) use ($sunset_to_sunrise,$datetimes) {
				return ($datetimes[$key] >= $sunset_to_sunrise['sunset'])
						&& ($datetimes[$key] <= $sunset_to_sunrise['sunrise']);
			});
		
		foreach (SQM_Data_Attributes::$modules as $module) {
			$module::add_best_nightly_attributes(
				$attributes,$date,$key,$datetime,$datetimes,$values,$night_attributes,
				$this->sqm_sun_moon_info,$this->fileset,$datetime_keys_at_night
			);
		}
		return $attributes;
	}
}
?>
