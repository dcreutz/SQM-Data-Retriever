<?php
/*	sqm_date_utils.php
	SQM Data Retriever
	(c) 2024 Darren Creutz
	Licensed under the GNU AFFERO GENERAL PUBLIC LICENSE */
	
/*	Utility functions for parsing and managing dates and datetimes */
class SQM_Date_Utils {
	// parse a string into a DateTimeImmutable object
	public static function datetime_from_date_string($date_string) {
		$datetime = DateTimeImmutable::createFromFormat("Y-m",$date_string);
		if ($datetime !== false) { 
			return $datetime->modify("first day of this month")->setTime(12,0);
		}
		$datetime = DateTimeImmutable::createFromFormat("Y-m-d",$date_string);
		if ($datetime !== false) {
			return $datetime->setTime(12,0);
		}
		$datetime = DateTimeImmutable::createFromFormat("Y-m-d H",$date_string);
		if ($datetime !== false) {return $datetime; }
		$datetime = DateTimeImmutable::createFromFormat("Y-m-d H:i",$date_string);
		if ($datetime !== false) { return $datetime; }
		$datetime = DateTimeImmutable::createFromFormat("Y-m-d H:i:s",$date_string);
		if ($datetime !== false) { return $datetime; }
		return false;
	}
	
	/*	returns an array of strings representing the dates in the given range
		$start and $end are DateTimeInterface objects
		$format is how to format the result
		$interval is how far apart the dates should be */
	public static function dates_in_range($start,$end,$format="Y-m-d",$interval='+1 day') {
		return array_map(function ($datetime) use ($format) { return $datetime->format($format); },
			iterator_to_array(new DatePeriod(
				(clone $start)->modify("-12 hours"),
				DateInterval::createFromDateString($interval),
				(clone $end)->modify("-12 hours")->modify($interval))));
	}
	
	/*	returns an array of strings representing the months in a given range */
	public static function months_in_range($start_date,$end_date,$format="Y-m") {
		return array_map(function ($datetime) use ($format) { return $datetime->format($format); },
			iterator_to_array(new DatePeriod(
				SQM_Date_Utils::datetime_from_date_string($start_date),
				DateInterval::createFromDateString('+1 month'),
				SQM_Date_Utils::datetime_from_date_string($end_date)->modify("+1 month"))));
	}
}
?>
