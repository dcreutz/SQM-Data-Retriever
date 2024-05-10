<?php
/*	sqm_readings.php
	SQM Data Retriever
	(c) 2024 Darren Creutz
	Licensed under the GNU AFFERO GENERAL PUBLIC LICENSE */
	
/*	Represents the actual response data for a given request and given dataset */
class SQM_Readings implements JsonSerializable {
	public static $datetime_format;

	// string specifying the type of request
	public $type;
	// array of DateTimeInterface objects
	public $datetimes;
	// array of msas readings keyed paired to datetimes
	public $values;
	// array of arrays of attributes keyed by attribute name then datetime keys
	public $attributes;
	// DateTime of start of range
	public $start_datetime;
	// DateTime of end of range
	public $end_datetime;
	
	public function __construct(
			$type,$datetimes,$values,$attributes,$start_datetime,$end_datetime) {
		$this->type = $type;
		$this->datetimes = $datetimes;
		$this->values = $values;
		$this->attributes = $attributes;
		$this->start_datetime = $start_datetime;
		$this->end_datetime = $end_datetime;
	}
	
	/*	create a response to a 'readings_range' request
		$start_datetime,$end_datetime are DateTimeInterface objects
		$start_value,$end_value are the msas readings */
	public static function from_earliest_and_latest(
			$start_datetime,$end_datetime,$start_value,$end_value) {
		return new SQM_Readings(
			'readings_range',
			[ $start_datetime, $end_datetime ],
			[ $start_value, $end_value ],
			[ [], [] ],
			$start_datetime,
			$end_datetime
		);
	}
	
	/*	create a response to a request for data
		rather than return paired arrays, re-key the value and attributes arrays using
		string representations of the datetimes */
	#[\ReturnTypeWillChange]
	public function jsonSerialize() {
		$result = array(
			'type'			=> $this->type,
			'startDatetime'	=> SQM_Readings::format_datetime_json($this->start_datetime),
			'endDatetime'	=> SQM_Readings::format_datetime_json($this->end_datetime)
		);
		$result['readings'] = array();
		foreach ($this->datetimes as $key => $datetime) {
			$date_string = SQM_Readings::format_datetime_json($datetime);
			$result['readings'][$date_string] = $this->attributes[$key];
			$result['readings'][$date_string]['reading'] = $this->values[$key];
		}
		return $result;
	}
	
	// format a DateTime object as a string using the request specified format
	public static function format_datetime_json($datetime) {
		return $datetime->format(SQM_Readings::$datetime_format);
	}
}
?>
