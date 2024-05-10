<?php
/*	sqm_data_attributes_computer.php
	SQM Data Retriever
	(c) 2024 Darren Creutz
	Licensed under the GNU AFFERO GENERAL PUBLIC LICENSE */
	
/*	SQM_Data_Attributes_Module for counting the number of readings per night and associating
	that to the best nightly readings as 'number_of_readings' */
require_once('sqm_regression.php');

class SQM_Data_Attributes_Number_Readings_Module extends SQM_Data_Attributes_Module {
	public static function add_best_nightly_attributes(
		&$attributes,$date,$key,$datetime,$datetimes,$values,$night_attributes,
		$sqm_sun_moon_info,$fileset,$datetime_keys_at_night
	) {
		$attributes['number_of_readings'] = count($datetime_keys_at_night);
	}
}

/*	SQM_Data_Attributes_Module attaching sun and moon position attributes to the data
	attributes named 'sun_position', 'moon_position', 'moon_illumination' are attached
	
	see SQM_Sun_Moon_info for details on the information attached */
class SQM_Data_Attributes_Sun_Moon_Module extends SQM_Data_Attributes_Module {
	public static function add_attributes_from(
		&$attributes,$datetimes,$values,$sunset,$sunrise,$sqm_sun_moon_info,$fileset
	) {
		foreach ($datetimes as $key => $datetime) {
			if (isset($attributes[$key]['sun_position']) && $attributes[$key]['sun_position']) {
				continue;
			}
			$attributes[$key]['sun_position'] = $sqm_sun_moon_info->sun_position($datetime);
			$attributes[$key]['moon_position'] =
				$sqm_sun_moon_info->moon_position($datetime);
			$attributes[$key]['moon_illumination'] =
				$sqm_sun_moon_info->moon_illumination($datetime);
		}
	}
	
	public static function add_best_nightly_attributes(
		&$attributes,$date,$key,$datetime,$datetimes,$values,$night_attributes,
		$sqm_sun_moon_info,$fileset,$datetime_keys_at_night
	) {
		$attributes['sun_position'] = $night_attributes[$key]['sun_position'];
		$attributes['moon_position'] = $night_attributes[$key]['moon_position'];
		$attributes['moon_illumination'] = $night_attributes[$key]['moon_illumination'];
	}
}

/*	SQM_Data_Attrbiutes_Module for performing linear regression analysis to attempt to
	determine cloudiness
	
	individual readings will be given attributes 'r_squared' and 'mean_r_squared'
	
	best nightly readings will inherit those attributes
	best nightly readings will also have an attribute 'filtered_mean_r_squared' attached
	'filtered_mean_r_squared' will point to a reading with attrbiutes for the best
	reading of the night after excluding the cloudy readings
	
	see config.php for configuration options */
class SQM_Data_Attributes_Regression_Analysis_Module extends SQM_Data_Attributes_Module {
	public static function add_attributes_from(
		&$attributes,$datetimes,$values,$sunset,$sunrise,$sqm_sun_moon_info,$fileset
	) {
		$regression = SQM_Regression::compute_r_squareds($datetimes,$values,$sunset,$sunrise);
		foreach ($datetimes as $key => $datetime) {
			if (isset($regression['r_squareds'][$key])) {
				$attributes[$key]['r_squared'] = $regression['r_squareds'][$key];
			} else {
				$attributes[$key]['r_squared'] = null;
			}
			if (isset($regression['mean_r_squareds'][$key])) {
				$attributes[$key]['mean_r_squared'] = $regression['mean_r_squareds'][$key];
			} else {
				$attributes[$key]['mean_r_squared'] = null;
			}
		}
	}
	
	public static function add_best_nightly_attributes(
		&$attributes,$date,$key,$datetime,$datetimes,$values,$night_attributes,
		$sqm_sun_moon_info,$fileset,$datetime_keys_at_night
	) {
		global $filter_mean_r_squared;
		$best_key = -1;
		$best_value = -1;
		$count = 0;
		foreach ($datetime_keys_at_night as $dt_key) {
			if (($night_attributes[$dt_key]['mean_r_squared'] == null) ||
					($night_attributes[$dt_key]['mean_r_squared'] >= $filter_mean_r_squared)) {
				continue;
			}
			$count += 1;
			if ($values[$dt_key] > $best_value) {
				$best_key = $dt_key;
				$best_value = $values[$dt_key];
			}
		}
		if ($best_key >= 0) {
			$attributes['filtered_mean_r_squared'] = array(
				'datetime'=>SQM_Readings::format_datetime_json($datetimes[$best_key]),
				'reading'=>$values[$best_key]
			);
			foreach ($night_attributes[$best_key] as $attribute => $attr_value) {
				$attributes['filtered_mean_r_squared'][$attribute] = $attr_value;
			}
			$attributes['filtered_mean_r_squared']['number_of_readings'] = $count;
		} else {
			$attributes['filtered_mean_r_squared'] = null;
		}
		if (isset($night_attributes[$key]['mean_r_squared'])
				&& ($night_attributes[$key]['mean_r_squared'] != null)) {
			$attributes['mean_r_squared'] = $night_attributes[$key]['mean_r_squared'];
		} else {
		// if mean R^2 isn't computed for the best (due to it being too close to sunset/sunrise)
		// then use the R^2 of closest reading that did have R^2
			$closest_key = false;
			foreach ($datetime_keys_at_night as $dt_key) {
				if (isset($night_attributes[$dt_key]['mean_r_squared'])
						&& ($night_attributes[$dt_key]['mean_r_squared'] != null)) {
					$closest_key = $dt_key;
					break;
				}
			}
			if ($closest_key) {
				foreach ($datetime_keys_at_night as $dt_key) {
					if (isset($night_attributes[$dt_key]['mean_r_squared'])
							&& ($night_attributes[$dt_key]['mean_r_squared'] != null)) {
						$diff = $datetime->diff($datetimes[$dt_key],true);
						$cdiff = $datetime->diff($datetimes[$closest_key],true);
						$date = new DateTimeImmutable();
						if ($date->add($diff) < $date->add($cdiff)) {
							$closest_key = $dt_key;
						}
					}
				}
				$attributes['mean_r_squared'] = $night_attributes[$closest_key]['mean_r_squared'];
			} else {
				$attributes['mean_r_squared'] = null;
			}
		}
	}
}

/*	SQM_Data_Attributes_Module for filtering readings based on sun, moon and clouds

	best nightly readings will have 'filtered_sun_moon_clouds' attached which points to
	the reading and attributes for the best reading of the night after excluding
	data when the sun or moon or clouds are interfering
	
	see config.php for configuration options */
class SQM_Data_Attributes_Sun_Moon_Clouds_Module extends SQM_Data_Attributes_Module {
	private static function exclude($attributes) {
		global $filter_mean_r_squared;
		global $filter_sun_elevation;
		global $filter_moon_elevation;
		global $filter_moon_illumination;
		$sun_filter = $filter_sun_elevation*M_PI/180;
		$moon_filter = $filter_moon_elevation*M_PI/180;
		if ($attributes['mean_r_squared'] == null) {
			return true;
		}
		if ($attributes['mean_r_squared'] >= $filter_mean_r_squared) {
			return true;
		}
		if ($attributes['sun_position']['altitude'] >= $sun_filter) {
			return true;
		}
		if (($attributes['moon_position']['altitude'] >= $moon_filter) &&
			($attributes['moon_illumination']['fraction']
				>= $filter_moon_illumination)) {
			return true;
		}
		return false;
	}

	public static function add_attributes_from(
		&$attributes,$datetimes,$values,$sunset,$sunrise,$sqm_sun_moon_info,$fileset
	) {
		foreach ($datetimes as $key => $datetime) {
			if (SQM_Data_Attributes_Sun_Moon_Clouds_Module::exclude($attributes[$key])) {
				$attributes[$key]['filtered_sun_moon_clouds'] = null;
			} else {
				$new_attributes = array(
					'datetime'=>SQM_Readings::format_datetime_json($datetimes[$key]),
					'reading'=>$values[$key]
				);
				foreach ($attributes[$key] as $attr => $attr_value) {
					$new_attributes[$attr] = $attr_value;
				}
				$attributes[$key]['filtered_sun_moon_clouds'] = $new_attributes;
			}
		}
	}
	
	public static function add_best_nightly_attributes(
		&$attributes,$date,$key,$datetime,$datetimes,$values,$night_attributes,
		$sqm_sun_moon_info,$fileset,$datetime_keys_at_night
	) {
		$best_key = -1;
		$best_value = -1;
		$count = 0;
		foreach ($datetimes as $dt_key => $datetime) {
			if (SQM_Data_Attributes_Sun_Moon_Clouds_Module::exclude($night_attributes[$dt_key])) {
				continue;
			}
			$count += 1;
			if ($values[$dt_key] > $best_value) {
				$best_key = $dt_key;
				$best_value = $values[$key];
			}
		}
		if ($best_key >= 0) {
			$attributes['filtered_sun_moon_clouds'] = array(
				'datetime'=>SQM_Readings::format_datetime_json($datetimes[$best_key]),
				'reading'=>$values[$best_key]
			);
			foreach ($night_attributes[$best_key] as $attribute => $attr_value) {
				$attributes['filtered_sun_moon_clouds'][$attribute] = $attr_value;
			}
			$attributes['filtered_sun_moon_clouds']['number_of_readings'] = $count;
		} else {
			$attributes['filtered_sun_moon_clouds'] = null;
		}
	}
}
?>
