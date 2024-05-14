<?php
/*	sqm_regression.php
	SQM Data Retriever
	(c) 2024 Darren Creutz
	Licensed under the GNU AFFERO GENERAL PUBLIC LICENSE v3 */
	
/*	Utilities for performing linear regression
	
	Closely modeled on code originally written by Bill Kowalik
	that forms the core of the SunMoonMWClouds algorithm in the Unihedron software */
class SQM_Regression {
	/*	performs a linear regression based on the config parameters
		returns the r^2 value comparing the actual values to the linear model
		
		$datetimes is an array of DateTimeInterface objects
		$values are the corresponding msas values
		the arrays are paired in the sense of matching keys */
	public static function r_squared($datetimes,$values) {
		$num_readings = count($datetimes);
		if ($num_readings <= 2) {
			return null;
		}
		$sum_time = 0.0;
		$sum_value = 0.0;
		$sum_time_value = 0.0;
		$sum_time_squared = 0.0;
		$sum_value_squared = 0.0;
		$first_timestamp = $datetimes[array_key_first($datetimes)]->getTimestamp();
		foreach ($datetimes as $key => $datetime) {
			$time = $datetime->getTimestamp() - $first_timestamp;
			$value = $values[$key];
			$sum_time += $time;
			$sum_value += $value;
			$sum_time_value += floatval($time) * $value;
			$sum_time_squared += $time * $time;
			$sum_value_squared += $value * $value;
		}
		$mean_time = floatval($sum_time) / $num_readings;
		$mean_value = $sum_value / $num_readings;
		$mean_time_value = $sum_time_value / $num_readings;
		$mean_time_squared = $sum_time_squared / $num_readings;
		$mean_value_squared = $sum_value_squared / $num_readings;
		$slope = ($mean_time_value - ($mean_time * $mean_value))
					/ ($mean_time_squared - ($mean_time * $mean_time));
		$intercept = (($mean_time_squared * $mean_value) - ($mean_time_value * $mean_time))
					/ ($mean_time_squared - ($mean_time * $mean_time));
		if (($sum_value_squared - $sum_value * $sum_value / $num_readings) == 0.0) {
			$r_correlation = 1.0;
		} else {
			$r_correlation = ($sum_time_value - $sum_time * $sum_value / $num_readings)
					/ sqrt(($sum_time_squared - $sum_time * $sum_time / $num_readings)
							* ($sum_value_squared - $sum_value * $sum_value / $num_readings));
		}
		$r_squared = $r_correlation * $r_correlation;
		$residuals = array_map(
			function ($key) use ($slope,$intercept,$values,$first_timestamp,$datetimes) {
				$expected =
					$slope * ($datetimes[$key]->getTimestamp() - $first_timestamp) + $intercept;
				$observed = $values[$key];
				return $observed - $expected;
			},
			array_keys($datetimes)
		);
		$sum_residual_squared = array_reduce($residuals,
			function ($sum,$residual) {
				return $sum + $residual * $residual;
			},
			0
		);
		$degrees_of_freedom = count($datetimes) - 2;
		$residual_std_error = sqrt($sum_residual_squared/$degrees_of_freedom);
		if ($residual_std_error < 0.0) {
			$residual_std_error = -1 * $residual_std_error;
		}
		return $residual_std_error;
	}
	
	/*	computes r^2 values for each datetime between sunset and sunrise
		returns the r^2 and mean r^2 values for each datetime that can be calculated
		returns arrays keyed with the same keys as $datetimes paired to it */
	public static function compute_r_squareds($datetimes,$values,$sunset,$sunrise) {
		$r_squared = array();
		$average_r_squared = array();
		
		global $regression_time_range;
		global $regression_averaging_time_range;
		$time_range = $regression_time_range * 60 / 2;
		$averaging_time_range = $regression_averaging_time_range * 60 / 2;
		$datetimes_to_consider = array();
		$values_to_consider = array();
		
		global $regression_time_shift;
		$lag_interval = new DateInterval('PT' . $regression_time_shift . 'M');
		$sunset_plus = (clone $sunset)->add($lag_interval);
		$sunrise_minus = (clone $sunrise)->sub($lag_interval);
		
		foreach ($datetimes as $key => $datetime) {
			if (($datetime >= $sunset_plus) && ($datetime <= $sunrise_minus)) {
				$datetimes_to_consider[$key] = array();
				$values_to_consider[$key] = array();
				foreach ($datetimes as $new_key => $new_datetime) {
					if (($new_datetime >= $sunset) && ($new_datetime <= $sunrise)) {
						if (abs($datetime->getTimestamp() - $new_datetime->getTimestamp())
										<= $time_range) {
							array_push($datetimes_to_consider[$key],$new_datetime);
							array_push($values_to_consider[$key],$values[$new_key]);
						}
					}
				}
			}
		}
		foreach ($datetimes_to_consider as $key => $the_datetimes) {
			$r_squared[$key] =
						SQM_Regression::r_squared($the_datetimes,$values_to_consider[$key]);
		}
		foreach (array_keys($datetimes_to_consider) as $key) {
			$datetime_keys_to_average = array();
			foreach (array_keys($datetimes_to_consider) as $new_key) {
				if (abs($datetimes[$key]->getTimestamp() - $datetimes[$new_key]->getTimestamp())
						<= $averaging_time_range) {
					array_push($datetime_keys_to_average,$new_key);
				}
			}
			$average_r_squared[$key] = 0.0;
			foreach ($datetime_keys_to_average as $new_key) {
				$average_r_squared[$key] += $r_squared[$new_key];
			}
			$average_r_squared[$key] = $average_r_squared[$key] / count($datetime_keys_to_average);
		}	
		return array('r_squareds'=>$r_squared,'mean_r_squareds'=>$average_r_squared);
	}
}
?>
