<?php
/*	sqm_sun_moon_info.php
	SQM Data Retriever
	(c) 2024 Darren Creutz
	Licensed under the GNU AFFERO GENERAL PUBLIC LICENSE v3 */

/*	Utilities for determining information about the sun and moon */

class SQM_Sun_Moon_Info {
	private $latitude;
	private $longitude;
	private $time_zone; // DateTimeZone object

	public function __construct($latitude,$longitude,$time_zone) {
		$this->latitude = $latitude;
		$this->longitude = $longitude;
		$this->time_zone = $time_zone;
	}

	/*	returns sunset of the given date and the following sunrise
		$date must be a string in YYYY-MM-DD format
		$twilight_type must be one of 'civil', 'nautical', 'astronomical'
		
		if the sun does not rise or does not set, noon will be returned */
	public function sunset_to_sunrise($date,$twilight_type) {
		$datetime = DateTime::createFromFormat("Y-m-d",$date,$this->time_zone);
		$datetime->setTime(12,0);
		return array(
			'sunset'=>$this->sun_datetimes($datetime,$twilight_type)['sunset'],
			'sunrise'=>$this->sun_datetimes($datetime->modify("+1 day"),$twilight_type)['sunrise']
		);
	}
	
	/*	translate twilight types to suncalc keys */
	private function sunset_key($twilight_type) {
		switch ($twilight_type) {
			case 'civil':
				return 'sunset';
			case 'nautical':
				return 'civil_twilight_end';
			case 'astronomical':
				return 'nautical_twilight_end';
			case 'night':
				return 'astronomical_twilight_end';
		}
	}
	
	private function sunrise_key($twilight_type) {
		switch ($twilight_type) {
			case 'civil':
				return 'sunrise';
			case 'nautical':
				return 'civil_twilight_begin';
			case 'astronomical':
				return 'nautical_twilight_begin';
			case 'night':
				return 'astronomical_twilight_begin';
		}
	}
	
	/*	return the sunrise and sunset times
		return 7pm to 5am in case of errors */
	private function sun_datetimes($datetime,$twilight_type) {
		if (!$this->latitude) {
			$fake_sunset = (clone $datetime)->setTime(19,0);
			$fake_sunrise = (clone $datetime)->modify("+10 hours");
			return array('sunset'=>$fake_sunset,'sunrise'=>$fake_sunrise);
		}
		// workaround for the fact that date_sun_info should really require a timezone argument
		$tz = date_default_timezone_get();
		date_default_timezone_set($this->time_zone->getName());
		$sun_info = date_sun_info(
			$datetime->format("U"),$this->latitude,$this->longitude
		);
		date_default_timezone_set($tz);
		$sunset_key = $this->sunset_key($twilight_type);
		$sunrise_key = $this->sunrise_key($twilight_type);
		if (($sun_info[$sunrise_key] === true) || // sun always up
				($sun_info[$sunrise_key] === false)) { // sun always down
			$sunrise = (clone $datetime)->setTime(12,0);
			$sunset = $sunrise;
		} else {
			$sunrise = new DateTime("@" . $sun_info[$sunrise_key]);
			$sunset = new DateTime("@" . $sun_info[$sunset_key]);
			$sunrise->setTimeZone($datetime->getTimeZone());
			$sunset->setTimeZone($datetime->getTimeZone());
			$sunrise = DateTimeImmutable::createFromInterface($sunrise);
			$sunset = DateTimeImmutable::createFromInterface($sunset);
		}
		return array('sunset'=>$sunset,'sunrise'=>$sunrise);
	}
	
	/*	sun_calc wrapper functions */
	private function suncalc($datetime) {
		return new SunCalc($datetime,$this->latitude,$this->longitude);
	}
	
	/*	returns the position of the sun at a give time
		$datetime must implement DateTimeInterface */
	public function sun_position($datetime) {
		$sun_position = $this->suncalc($datetime)->getSunPosition();
		return array(
			'altitude' => $sun_position->altitude,
			'azimuth' => $sun_position->azimuth
		);
	}
	
	/*	returns the position of the moon at a give time
		$datetime must implement DateTimeInterface */
	public function moon_position($datetime) {
		$moon_position = $this->suncalc($datetime)->getMoonPosition($datetime);
		return array(
			'altitude' => $moon_position->altitude,
			'azimuth' => $moon_position->azimuth,
			'distance' => $moon_position->dist
		);
	}
	
	/*	returns the illumination of the moon at a give time
		$datetime must implement DateTimeInterface
		
		see the suncalc documentation for details */
	public function moon_illumination($datetime) {
		return $this->suncalc($datetime)->getMoonIllumination();
	}
}
?>