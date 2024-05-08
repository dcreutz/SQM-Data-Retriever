<?php
/*	sqm_info.php
	SQM Data Retriever
	(c) 2024 Darren Creutz
	Licensed under the GNU AFFERO GENERAL PUBLIC LICENSE */
	
/*	Represents the information about a given sqm station such as name and location

	if a file named .info is placed in the data directory for a given sqm, an object of this
	type will be created using the info in that file rather than by parsing a data file */
class SQM_Info implements JsonSerializable {
	public $name;
	public $latitude;
	public $longitude;
	public $elevation;
	public $time_zone; // DateTimeZone
	
	public function __construct($name,$latitude,$longitude,$elevation,$time_zone = null) {
		$this->name = $name;
		$this->latitude = $latitude;
		$this->longitude = $longitude;
		$this->elevation = $elevation;
		if ($time_zone) {
			$this->time_zone = $time_zone;
		} else {
			if ($this->latitude) {
				$this->time_zone = SQM_Info::get_nearest_timezone($latitude,$longitude);
			} else {
				$this->time_zone = (new DateTime())->getTimezone();
			}
		}
	}
	
	/*	this object is serialized and returned to the requester during 'info' requests */
	#[\ReturnTypeWillChange]
	public function jsonSerialize() {
		return array(	
			'name'				=> $this->name,
			'latitude'			=> $this->latitude,
			'longitude'			=> $this->longitude,
			'elevation'			=> $this->elevation,
			'time_zone_id'		=> $this->time_zone->getName(),
			'time_zone_name'	=> (new DateTime("now",$this->time_zone))->format("T")
		);
	}
	
	// if the time zone can't be determined from the data file, use position to determine it
	private static function get_nearest_timezone($latitude,$longitude) {
		$timezones = array_map(function ($timezone_id) {
			return new DateTimeZone($timezone_id);
		},DateTimeZone::listIdentifiers());
		$timezone_distances = array_map(function ($timezone) use ($latitude,$longitude) {
			$location = $timezone->getLocation();
			$tz_lat   = $location['latitude'];
			$tz_long  = $location['longitude'];
			$theta    = $longitude - $tz_long;
			$distance = (sin(deg2rad($latitude)) * sin(deg2rad($tz_lat))) + 
						(cos(deg2rad($latitude)) * cos(deg2rad($tz_lat)) * cos(deg2rad($theta)));
			$distance = acos($distance);
			return abs(rad2deg($distance));
		},$timezones);
		return $timezones[array_search(min($timezone_distances),$timezone_distances)];
	}
}
?>
