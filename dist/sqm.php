<?php
/*	sqm.php
	SQM Data Retriever
	(c) 2024 Darren Creutz
	Licensed under the GNU AFFERO GENERAL PUBLIC LICENSE v3 */
	
/*	the actual script to be HTTP POST requested
	also allows for preloading info and readings in index.php
	
	in standard mode
		loads config.php via wrapper script
		then creates a global error handler (unless in debug mode)
		then decodes the post data as a request
		then initializes the SQM_Responder
		then passes the request to the responder
		and finally takes the response and returns it to the caller 
	
	in preload mode
		loads config.php via wrapper script
		then creates the preload request object
		then creates a global error handler (unless in debug mode)
		then initializes the SQM_Responder
		then passes the request to the responder
		and finally outputs javascript for the preloaded response
	*/
	

	


@include('config.php');
if (!isset($data_directory)) {
	$data_directory = "data";
}
if (!isset($update_cache_cli_only)) {
	$update_cache_cli_only = false;
}
if (!isset($read_only_mode)) {
	$read_only_mode = false;
}
if (!isset($trust_files)) {
	$trust_files = 'only if not cacheing';
}
if (!isset($default_twilight_type)) {
	$default_twilight_type = 'nautical';
}
if (!isset($extended_time)) {
	$extended_time = true;
}
if (!isset($add_raw_data)) {
	$add_raw_data = 'only if cacheing';
}
if (!isset($add_sun_moon_info)) {
	$add_sun_moon_info = 'only if cacheing';
}
if (!isset($perform_regression_analysis)) {
	$perform_regression_analysis = 'only if cacheing';
}
if (!isset($regression_time_range)) {
	$regression_time_range = 90;
}
if (!isset($regression_averaging_time_range)) {
	$regression_averaging_time_range = 30;
}
if (!isset($regression_time_shift)) {
	$regression_time_shift = 60;
}
if (!isset($filter_mean_r_squared)) {
	$filter_mean_r_squared = 0.04;
}
if (!isset($filter_sun_elevation)) {
	$filter_sun_elevation = -12;
}
if (!isset($filter_moon_elevation)) {
	$filter_moon_elevation = -10;
}
if (!isset($filter_moon_illumination)) {
	$filter_moon_illumination = 0.1;
}
if (!isset($use_images)) {
	$use_images = 'only if cacheing';
}
if (!isset($image_name_format)) {
	$image_name_format = "YmdHis";
}
if (!isset($image_name_prefix_length)) {
	$image_name_prefix_length = 0;
}
if (!isset($image_name_suffix_length)) {
	$image_name_suffix_length = 4;
}
if (!isset($image_time_frame)) {
	$image_time_frame = 600;
}
if (!isset($resize_images)) {
	$resize_images = false;
}
if (!isset($resized_width)) {
	$resized_widths = $resized_widths = array('display_image'=>800,'thumbnail'=>200);;
}
if (!isset($clear_cache_on_errors)) {
	$clear_cache_on_errors = true;
}
if (!isset($debug)) {
	$debug = false;
}
if (!isset($logging_enabled)) {
	$logging_enabled = false;
}

if (isset($info_and_readings_preload) && $info_and_readings_preload === true) {

	$info_and_readings_request = array( 'queries' => array(
			'info' => array( 'type' => 'info' ),
			'readings_range' => array( 'type' => 'readings_range' )
		));
	$requests = array(
		'info_and_readings' => $info_and_readings_request
	);

}


	



	


	

class SQM_Directory {
	private $directory;
	
	public function __construct($directory) {
		$this->directory = $directory;
	}
	
	protected function file_path($file = null) {
		if ($file) {
			return $this->directory . DIRECTORY_SEPARATOR . $file;
		} else {
			return $this->directory;
		}
	}
	
	public function file_exists($file = null) {
		return file_exists($this->file_path($file));
	}
	
	public function is_dir($file = null) {
		return is_dir($this->file_path($file));
	}
	
	public function filemtime($file = null) {
		if ($this->file_exists($file)) {
			return filemtime($this->file_path($file));
		}
		return -1;
	}
	
	public function scandir($subdirectory = null) {
		if (($this->file_exists($subdirectory)) && ($this->is_dir($subdirectory))) {
			return SQM_Directory::scandir_no_dotfiles($this->file_path($subdirectory));
		}
		return array();
	}
	
	public function mkdir($subdirectory = null) {
		if (!$this->file_exists($subdirectory)) {
			mkdir($this->file_path($subdirectory));
		}
	}
	
	public static function scandir_no_dotfiles($directory) {
		return preg_grep('/^([^.])/',scandir($directory));
	}
}

class SQM_Cache extends SQM_Directory {	
	public function __construct($directory) {
		parent::__construct($directory);
		$this->initialize();
	}
	
	protected function initialize() {
		if (!$this->file_exists()) {
			$this->mkdir();
		}
	}
	
	public function load_from($file) {
		if ($this->file_exists($file)) {
			return unserialize(file_get_contents($this->file_path($file)));
		} else {
			return null;
		}
	}
	
	public function save_to($file,$data) {
		file_put_contents($this->file_path($file),serialize($data));
	}
	
	public function remove($file) {
		if ($this->file_exists($file)) {
			unlink($this->file_path($file));
		}
	}
}


class SQM_Cache_Read_Only extends SQM_Cache {
	public function __construct($directory) {
		parent::__construct($directory);
	}
	
	protected function initialize() {
	}
	
	public function save_to($file,$data) {
	}
	
	public function remove($file) {
	}
}


class SQM_Cache_Factory {
	protected static $instance;
	private $cache_directory;
	private $file_handle;
	private $has_lock;
	private $force_read_only;
	
	public static function initialize($cache_directory,$should_block) {
		SQM_Cache_Factory::$instance = new SQM_Cache_Factory($cache_directory,$should_block,false);
	}
	
	public static function initialize_read_only($cache_directory) {
		SQM_Cache_Factory::$instance = new SQM_Cache_Factory($cache_directory,false,true);
	}
	
	
	public static function is_read_only() {
		return !SQM_Cache_Factory::$instance->has_lock;
	}
	
	private function __construct($cache_directory,$should_block,$force_read_only) {
		$this->has_lock = false;
		$this->cache_directory = $cache_directory;
		$this->force_read_only = $force_read_only;
		if (!$force_read_only) {
			$this->file_handle =
				fopen($this->cache_directory . DIRECTORY_SEPARATOR . ".sqm_cache_lock_file","w");
			if ($should_block) {
				if (flock($this->file_handle,LOCK_EX)) {
					$this->has_lock = true;
				} else {
					fclose($this->file_handle);
				}
			} else {
				if (flock($this->file_handle,LOCK_EX|LOCK_NB)) {
					$this->has_lock = true;
				} else {
					fclose($this->file_handle);
				}
			}
		}
	}
	
	public function __destruct() {
		if ($this->has_lock) {
			fclose($this->file_handle);
		}
	}
	
	public static function create($sqm_id) {
		return SQM_Cache_Factory::$instance->build($sqm_id);
	}
	
	protected function build($sqm_id) {
		if ($this->force_read_only) {
			return new SQM_Cache_Read_Only($this->cache_directory . DIRECTORY_SEPARATOR . $sqm_id);
		}
		global $read_only_mode;
		if ($this->has_lock && !$read_only_mode) {
			return new SQM_Cache($this->cache_directory . DIRECTORY_SEPARATOR . $sqm_id);
		} else {
			return new SQM_Cache_Read_Only($this->cache_directory . DIRECTORY_SEPARATOR . $sqm_id);
		}
	}
	
	
	public static function clear_cache() {
		if (SQM_Cache_Factory::$instance) {
			SQM_Cache_Factory::$instance->clear();
		}
	}
	
	private function clear() {
		if ($this->has_lock && file_exists($this->cache_directory)) {
			$di = new RecursiveDirectoryIterator(
				$this->cache_directory, FilesystemIterator::SKIP_DOTS
			);
			$ri = new RecursiveIteratorIterator($di, RecursiveIteratorIterator::CHILD_FIRST);
			foreach ( $ri as $file ) {
				$file->isDir() ?  rmdir($file) : unlink($file);
			}
		} else {
			if ($this->has_lock) {
				sqm_error_log("Could not clear cache as directory does not exist");
			}
			if (!$this->has_lock) {
				sqm_error_log("Could not clear cache due to not having the cache lock");
			}
		}
	}
}

if ($debug) {
	ini_set('display_errors', 1);
	ini_set('display_startup_errors', 1);
	error_reporting(E_ALL);
	error_log("SQM backend running in debug mode");
} else {

	function sqm_error_handler($errno, $errstr, $errfile, $errline, $errcontext = false) {
		sqm_error_log("SQM Data Retriever error: " . $errno . " " . $errstr . " "
					. $errfile . " " . $errline);
		global $debug;
		if ($debug && $errcontext) {
			sqm_error_log(print_r($errcontext,true));
		}
		return true;
	}
	
	function sqm_fatal_error_handler() {
		$error = error_get_last();
		if ($error !== NULL) {
			$errno   = $error["type"];
			$errfile = $error["file"];
			$errline = $error["line"];
			$errstr  = $error["message"];
			sqm_error_handler($errno,$errfile,$errline,$errstr);
			global $clear_cache_on_errors;
			if ($clear_cache_on_errors) {
				sqm_error_log("Clearing the cache due to errors");
				
				@SQM_Cache_Factory::clear_cache();
			}
			global $suppress_output_on_error;
			if (!isset($suppress_output_on_error) || $suppress_output_on_error !== false) {
				echo json_encode(
					['response'=>array('fail'=>true,'message'=>"Something went wrong")]
				);
			}
		}
	}

	set_error_handler("sqm_error_handler");
	register_shutdown_function("sqm_fataL_error_handler");
}

function sqm_error_log($message,$level = 1) {
	if ($level == 0) {
		error_log("SQM Data Retriever:: " . $message);
	} elseif ($level == 1) {
		global $logging_enabled;
		if ($logging_enabled) {
			error_log("SQM Data Retriever:: " . $message);
		}
	} elseif ($level == 2) {
		global $cli_logging;
		if (isset($cli_logging) && $cli_logging) {
			error_log("SQM Data Retriever:: " . $message);
		}
	}
}

if (isset($info_and_readings_preload) && $info_and_readings_preload === true) {

	$should_block_for_cacheing = false;

} else {

	$post_data = file_get_contents('php://input');
	
	if ($post_data == "") {
		echo json_encode(array('response'=>array('fail'=>true)));
		exit();
	}
	$request = json_decode($post_data,true);
	if (isset($request['block']) && $request['block'] === true) {
		$should_block_for_cacheing = true;
		unset($request['block']);
	} else {
		$should_block_for_cacheing = false;
	}

}


	


$path = is_dir($data_directory) ? "" : getcwd() . DIRECTORY_SEPARATOR;


	


	


	

class SQM_Info implements JsonSerializable {
	public $name;
	public $latitude;
	public $longitude;
	public $elevation;
	public $time_zone; 
	
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

class SQM_File_Parser {	
	public static function is_valid_sqm_file($file_path) {
		return (new SQM_File_Parser($file_path))->is_valid();
	}
	
	private $file_path;
	private $file_handle;
	private $indexes;
	private $datetime;
	private $max_index;
	private $delimiter;
	private $readings;
	private $data_columns;
	private $datetime_strings;
	
	private $comment_lines;
	private $header_row;
	private $headers;
	private $first_row;
	
	
	public function __construct($file_path) {
		$this->file_path = $file_path;
		$this->comment_lines = array();
		$this->readings = array();
		$this->data_columns = array();
		$this->datetime_strings = array();
		$this->indexes = false;
		if ((!file_exists($file_path)) || (is_dir($file_path))) {
			return;
		}
		$this->file_handle = fopen($file_path,'r');
		if (!$this->file_handle) {
			return;
		}
		$this->indexes = array();
		$this->indexes['reading'] = -1;
		$line = fgets($this->file_handle);
		while ($this->indexes['reading'] < 0) {
			if (!$line) {
				sqm_error_log("Could not determine indexes in data file " . $file_path);
				return;
			}
			if ($line == "") {
				$line = fgets($this->file_handle);
				continue;
			}
			if ($line[0] == "#") {
				array_push($this->comment_lines,$line);
			}
			$lowerline = strtolower($line);
			if (str_contains($lowerline,"msas")) {
				$delimiter = SQM_File_Parser::get_delimiter($lowerline);
				if ($delimiter) {
					$this->header_row = str_getcsv($lowerline,$delimiter);
					$this->headers = array();
					foreach ($this->header_row as $index => $part) {
						if ($part[0] == "#") {
							$part = substr($part,1);
						}
						array_push($this->headers,trim($part));
						if (str_contains($part,"local")) {
							if (str_contains($part,"date")) {
								if (str_contains($part,"time")) {
									$this->indexes['datetime'] = $index;
								} else {
									$this->indexes['date'] = $index;
								}
							} else {
								if (str_contains($part,"time")) {
									$this->indexes['time'] = $index;
								}
							}
						}
						if ((str_contains($part,"msas")) && (!str_contains($part,"msas_avg"))) {
							$this->indexes['reading'] = $index;
						}
					}
				}
			}
			$line = fgets($this->file_handle);
		}
		$this->datetime = isset($this->indexes['datetime']);
		if (!$this->datetime) {
			if (!isset($this->indexes['date']) || !isset($this->indexes['time'])) {
				sqm_error_log("Could not determine datetime indices in file " . $file_path);
				return;
			}
		}
		while (($line == "") || ($line[0] == "#")) {
			$line = fgets($this->file_handle);
		}
		$this->delimiter = SQM_File_Parser::get_delimiter($line);
		while (!$this->delimiter) {
			$line = fgets($this->file_handle);
			if (!$line) {
				sqm_error_log("Could not parse file " . $file_path);
				return;
			}
			$this->delimiter = SQM_File_Parser::get_delimiter($line);
		}
		if ($this->datetime) {
			$this->max_index = max($this->indexes['datetime'],$this->indexes['reading']);
			$this->next_reading_datetime($line);
			$this->first_row = str_getcsv($line,$this->delimiter);
			while (count($this->readings) == 0) {
				$line = fgets($this->file_handle);
				$this->first_row = str_getcsv($line,$this->delimiter);
				if (!$line) {
					sqm_error_log("Could not get valid reading from file " . $file_path);
					return;
				}
				$this->next_reading_datetime($line);
			}
		} else {
			$this->max_index = max(
				$this->indexes['date'],$this->indexes['time'],$this->indexes['reading']
			);
			$this->next_reading_date_and_time($line);
			$this->first_row = str_getcsv($line,$this->delimiter);
			while (count($this->readings) == 0) {
				$line = fgets($this->file_handle);
				$this->first_row = str_getcsv($line,$this->delimiter);
				if (!$line) {
					sqm_error_log("Could not get valid reading from file " . $file_path);
					return;
				}
				$this->next_reading_date_and_time($line);
			}
		}
	}
	
	public function __destruct() {
		if ($this->file_handle) {
			fclose($this->file_handle);
		}
	}

	private static function get_delimiter($string) {
		foreach ([ ", ", ",", ";", "\t", "|" ] as $separator) {
			$parts = explode($separator,$string);
			if (count($parts) > 1) {
				return $separator;
			}
		}
		return false;
	}
	
	private function datetime_string($datetime_string) {
		return (new DateTime($datetime_string))->format("Y-m-d H:i:s");
	}
	
	private function next_reading_datetime($line) {
		$parts = str_getcsv($line,$this->delimiter);
		if (count($parts) >= $this->max_index) {
			$datetime_string = $parts[$this->indexes['datetime']];
			$this->readings[$datetime_string] = $parts[$this->indexes['reading']];
			$this->data_columns[$datetime_string] = $parts;
			$this->datetime_strings[$this->datetime_string($datetime_string)] = $datetime_string;
		}
	}
	
	private function next_reading_date_and_time($line) {
		$parts = str_getcsv($line,$this->delimiter);
		if (count($parts) >= $this->max_index) {
			$datetime_string =
				$parts[$this->indexes['date']] . ' ' . $parts[$this->indexes['time']];
			$this->readings[$datetime_string] = $parts[$this->indexes['reading']];
			$this->data_columns[$datetime_string] = $parts;
			$this->datetime_strings[$this->datetime_string($datetime_string)] = $datetime_string;
		}
	}

	public function is_valid() {
		return count($this->readings) > 0;
	}

	
	public function readings_from() {
		if ($this->datetime) {
			while ($line = fgets($this->file_handle)) {
				$this->next_reading_datetime($line);
			}
		} else {
			while ($line = fgets($this->file_handle)) {
				$this->next_reading_date_and_time($line);
			}
		}
		return $this->readings;
	}
	
	
	public function first_reading_from() {
		if (count($this->readings) > 0) {
			$datetime = array_keys($this->readings)[0];
			return array('datetime'=>$datetime,'value'=>$this->readings[$datetime]);
		}
		return false;
	}
	
	
	public function last_reading_from() {
		$position = ftell($this->file_handle);
		
		$cursor = -1;
		fseek($this->file_handle, $cursor, SEEK_END);
		$char = true;
		while ($char!==false) {
			$line = '';
			$char = fgetc($this->file_handle);
			while ($char === "\n" || $char === "\r") {
				fseek($this->file_handle, $cursor--, SEEK_END);
				$char = fgetc($this->file_handle);
			}
			while ($char !== false && $char !== "\n" && $char !== "\r") {
				$line = $char . $line;
				fseek($this->file_handle, $cursor--, SEEK_END);
				$char = fgetc($this->file_handle);
			}
			$parts = str_getcsv($line,$this->delimiter);
			if (count($parts) > $this->max_index) {
				fseek($this->file_handle,$position,SEEK_SET);
				if ($this->datetime) {
					return array(
						'datetime'=>$parts[$this->indexes['datetime']],
						'value'=>$parts[$this->indexes['reading']]
					);
				} else {
					return array(
						'datetime'=>$parts[$this->indexes['date']] . " " . 
									$parts[$this->indexes['time']],
						'value'=>$parts[$this->indexes['reading']]
					);
				}
			}
		}
		fseek($this->file_handle,$position,SEEK_SET);
		return false;
	}
	
	private static function after_colon($line) {
		$colon_pos = strpos($line,": ");
		if ($colon_pos) {
			return substr($line,$colon_pos+2);
		}
		$colon_pos = strpos($line,":");
		if ($colon_pos) {
			return substr($line,$colon_pos+1);
		}
		return $line;
	}

	
	public function sqm_info_from() {
		$elevation = null;
		$file = fopen($this->file_path,'r');
		foreach ($this->comment_lines as $line) {
			if (str_starts_with($line,"# Data supplier")) {
				$data_supplier = trim(SQM_File_Parser::after_colon($line));
			} elseif (str_starts_with($line,"# Location name: ")) {
				$location_name = trim(SQM_File_Parser::after_colon($line));
			} elseif (str_starts_with($line,"# Name: ")) {
				$name = trim(SQM_File_Parser::after_colon($line));
			} elseif (str_starts_with($line,"# SQM Name: ")) {
				$name = trim(tSQM_File_Parser::after_colon($line));
			} elseif (str_starts_with($line,"# Position")) {
				$position = explode(", ",SQM_File_Parser::after_colon($line));
				if (count($position) >= 2) {
					$latitude = floatval($position[0]);
					$longitude = floatval($position[1]);
				}
				if (count($position) >= 3) {
					$elevation = floatval($position[2]);
				}
			}
		}
		if (!isset($name)) {
			if (isset($data_supplier)) {
				if (isset($location_name)) {
					$name = $data_supplier . " " . $location_name;
				} else {
					$name = $data_supplier;
				}
			} elseif (isset($location_name)) {
				$name = $location_name;
			} else {
				$name = "No name specified";
			}
		}
		if (isset($latitude)) {
			return new SQM_Info($name,$latitude,$longitude,$elevation);
		}
		foreach ($this->header_row as $key => $value) {
			$string = strtolower($value);
			if (str_contains($string,"location")) {
				$name = $this->first_row[$key];
			} elseif (str_contains($string,"lat") && !str_contains($string,'galactic')) {
				$latitude = floatval($this->first_row[$key]);
			} elseif (str_contains($string,"long") && !str_contains($string,'galactic')) {
				$longitude = floatval($this->first_row[$key]);
			}
		}
		if (isset($latitude)) {
			return new SQM_Info($name,$latitude,$longitude,$elevation);
		}
		return false;
	}
	
	
	public function data_columns_for($datetime) {
		$datetime_string = $datetime->format("Y-m-d H:i:s");
		if (isset($this->datetime_strings[$datetime_string])) {
			$data_columns = $this->data_columns[$this->datetime_strings[$datetime_string]];
			$result = array();
			for ($i=0;$i<count($this->headers);$i++) {
				$result[$this->headers[$i]] = trim($data_columns[$i]);
			}
			return $result;
		}
		return null;
	}
}

class SQM_File_Manager extends SQM_Directory {
	private $file_parsers;

	public function __construct($directory) {
		parent::__construct($directory);
		$this->file_parsers = array();
	}
	
	
	public function get_file_list() {
		foreach (SQM_File_Manager::recursive_scandir($this->file_path()) as $file) {
			$parser = new SQM_File_Parser($this->file_path($file));
			if ($parser->is_valid()) {
				$this->file_parsers[$file] = $parser;
			}
		}
		return array_keys($this->file_parsers);
	}
	
	
	public static function recursive_scandir($directory) {
		$result = array();
		if (is_dir($directory)) {
			foreach (SQM_Directory::scandir_no_dotfiles($directory) as $file) {
				if (is_dir($directory.DIRECTORY_SEPARATOR.$file)) {
					foreach (
						SQM_File_Manager::recursive_scandir($directory.DIRECTORY_SEPARATOR.$file)
					as $file_in_dir) {
						array_push($result,$file.DIRECTORY_SEPARATOR.$file_in_dir);
					}
				} else {
					array_push($result,$file);
				}
			}
		}
		return $result;
	}
	
	public function sqm_info_from($file) {
		return $this->file_parsers[$file]->sqm_info_from();
	}
	
	public function readings_from($file) {
		return $this->file_parsers[$file]->readings_from();
	}
	
	
	public function sqm_info_from_dot_info() {
		if ($this->file_exists(".info")) {
			$lines = explode("\n",file_get_contents($this->file_path(".info")));
			$elevation = null;
			$timezone = null;
			foreach ($lines as $line) {
				if (str_starts_with($line,"Name: ")) {
					$name = substr($line,6);
				} elseif (str_starts_with($line,"Latitude: ")) {
					$latitude = floatval(substr($line,10));
				} elseif (str_starts_with($line,"Longitude: ")) {
					$longitude = floatval(substr($line,11));
				} elseif (str_starts_with($line,"Elevation: ")) {
					$elevation = substr($line,11);
				} elseif (str_starts_with($line,"Timezone: ")) {
					try {
						$timezone = new DateTimeZone(substr($line,10));
					} catch (Exception $e) {
						sqm_error_log("Invalid timezone " + substr($line,10));
					}
				}
			}
			if (isset($name) && isset($latitude) && isset($longitude)) {
				return new SQM_Info($name,$latitude,$longitude,$elevation,$timezone);
			}
		}
		return null;
	}
	
	public function first_reading_from($file) {
		return $this->file_parsers[$file]->first_reading_from();
	}
	
	public function last_reading_from($file) {
		return $this->file_parsers[$file]->last_reading_from();
	}
	
	public function data_columns_for($datetime) {
		$result = array('exists' => true);
		foreach ($this->file_parsers as $file => $parser) {
			$data_columns_for = $parser->data_columns_for($datetime);
			if ($data_columns_for) {
				$result = array_merge($result,$data_columns_for);
			}
		}
		if (count($result) > 1) {
			return $result;
		}
		return null;
	}
}


class SQM_File_Manager_Factory {
	protected static $instance;
	protected $data_directory;
	
	public static function initialize($directory) {
		if ((!file_exists($directory)) || !(is_dir($directory))) {
			throw new Exception("Invalid data directory");
		}
		SQM_File_Manager_Factory::$instance = new SQM_File_Manager_Factory($directory);
	}
	
	public static function available_sqm_ids() {
		return SQM_File_Manager_Factory::$instance->find_available_sqm_ids();
	}
	
	
	protected function find_available_sqm_ids() {
		$directory_list = SQM_Directory::scandir_no_dotfiles($this->data_directory);
		if (count(array_filter($directory_list,function ($listing) {
				return SQM_File_Parser::is_valid_sqm_file(
					$this->data_directory . DIRECTORY_SEPARATOR . $listing
				);
			})) > 0) {
			return array('.');
		}
		$sqm_ids = array();
		foreach ($directory_list as $listing) {
			if (is_dir($this->data_directory.DIRECTORY_SEPARATOR.$listing)) {
				array_push($sqm_ids,$listing);
			}
		}
		return $sqm_ids;
	}
	
	protected function __construct($data_directory) {
		$this->data_directory = $data_directory;
	}
	
	public static function create($subdirectory) {
		return SQM_File_Manager_Factory::$instance->build($subdirectory);
	}
	
	protected function build($subdirectory) {
		return new SQM_File_Manager($this->data_directory . DIRECTORY_SEPARATOR . $subdirectory);
	}
}
SQM_File_Manager_Factory::initialize($path . $data_directory);


	

abstract class SQM_Data_Attributes_Module {
	public static function add_attributes_from(
		&$attributes,$datetimes,$values,$sunset,$sunrise,$sqm_sun_moon_info,$fileset,$sqm_id
	) {
	}
	
	public static function add_best_nightly_attributes(
		&$attributes,$date,$key,$datetime,$datetimes,$values,$night_attributes,
		$sqm_sun_moon_info,$fileset,$datetime_keys_at_night
	) {
	}
}


	


	

class SQM_Regression {
	
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

class SQM_Data_Attributes_Number_Readings_Module extends SQM_Data_Attributes_Module {
	public static function add_best_nightly_attributes(
		&$attributes,$date,$key,$datetime,$datetimes,$values,$night_attributes,
		$sqm_sun_moon_info,$fileset,$datetime_keys_at_night
	) {
		$attributes['number_of_readings'] = count($datetime_keys_at_night);
	}
}


class SQM_Data_Attributes_Sun_Moon_Module extends SQM_Data_Attributes_Module {
	public static function add_attributes_from(
		&$attributes,$datetimes,$values,$sunset,$sunrise,$sqm_sun_moon_info,$fileset,$sqm_id
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


class SQM_Data_Attributes_Regression_Analysis_Module extends SQM_Data_Attributes_Module {
	public static function add_attributes_from(
		&$attributes,$datetimes,$values,$sunset,$sunrise,$sqm_sun_moon_info,$fileset,$sqm_id
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
		&$attributes,$datetimes,$values,$sunset,$sunrise,$sqm_sun_moon_info,$fileset,$sqm_id
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


	

class SQM_Data_Attributes_From_Data_Files extends SQM_Data_Attributes_Module {
	public static function add_attributes_from(
		&$attributes,$datetimes,$values,$sunset,$sunrise,$sqm_sun_moon_info,$fileset,$sqm_id
	) {
		foreach ($datetimes as $key => $datetime) {
			$data_columns = $fileset->data_columns_for($datetime);
			if ($data_columns) {
				$attributes[$key]['raw'] = $data_columns;
			} else {
				$attributes[$key]['raw'] = null;
			}
		}
	}
	
	public static function add_best_nightly_attributes(
		&$attributes,$date,$key,$datetime,$datetimes,$values,$night_attributes,
		$sqm_sun_moon_info,$fileset,$datetime_keys_at_night
	) {
		$attributes['raw'] = $night_attributes[$key]['raw'];
	}
}

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
	
	
	private function attributes_from($datetimes,$values,$sunset,$sunrise) {
		$attributes = array_map(function ($datetime) { return array(); },$datetimes);
		foreach (SQM_Data_Attributes::$modules as $module) {
			$module::add_attributes_from(
				$attributes,$datetimes,$values,$sunset,$sunrise,
				$this->sqm_sun_moon_info,$this->fileset,$this->sqm_data->sqmid()
			);
		}
		return $attributes;
	}
	
	
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
SQM_Data_Attributes::initialize();


	


	

abstract class SQM_File_Readings_Cache {
	
	public abstract function set_readings($file,$readings);
	
	
	public function get_readings($file) {
		return array();
	}
	
	
	public function new_files($new_files) {}
	
	
	public function deleted_files($deleted_files) {}
	
	
	public abstract function set_file_load_time($file,$timestamp);
	
	
	public function get_file_load_time($file) {
		return 0;
	}
}


abstract class SQM_File_Readings_Cache_Factory {
	protected static $instance;
	
	public static function exists() {
		return SQM_File_Readings_Cache_Factory::$instance != null;
	}
	
	public static function create($sqm_id) {
		return SQM_File_Readings_Cache_Factory::$instance->build($sqm_id);
	}
	
	protected abstract function build($sqm_id);
}

interface SQM_Fileset {
	
	public function all_files();
	
	
	public function files_for($start_date,$end_date);
	
	
	public function earliest_and_latest_readings();
	
	
	public function files_for_latest();
	public function files_for_earliest();
	
	
	public function new_readings_from(...$files);
	
	
	public function sqm_info();
	
	
	public function data_columns_for($datetime);
}


abstract class SQM_Fileset_Factory {
	protected static $instance;
		
	public static function exists() {
		return SQM_Fileset_Factory::$instance != null;
	}
	
	public static function create($sqm_id) {
		return SQM_Fileset_Factory::$instance->build($sqm_id,
			SQM_File_Readings_Cache_Factory::create($sqm_id),
			SQM_File_Manager_Factory::create($sqm_id)
		);
	}
	
	protected abstract function build($sqm_id,$file_readings_cache,$file_manager);
}

abstract class SQM_Fileset_Implementation implements SQM_Fileset {
	private $sqm_id;
	protected $file_list;
	protected $file_manager;
	
	private $file_readings_cache;
	private $pending_removed_readings;
	private $pending_added_readings;
	
	private $sqm_info;
	
	
	public function __construct($sqm_id,$file_readings_cache,$file_manager) {
		$this->sqm_id = $sqm_id;
		$this->file_manager = $file_manager;
		$this->file_list = $file_manager->get_file_list();
		$this->pending_removed_readings = array();
		$this->pending_added_readings = array();
		$this->file_readings_cache = $file_readings_cache;
		$this->sqm_info = $this->build_sqm_info();
	}
	
	private function build_sqm_info() {
		$sqm_info = $this->file_manager->sqm_info_from_dot_info();
		if ($sqm_info) {
			return $sqm_info;
		}
		foreach ($this->sqm_info_files() as $file) {
			$sqm_info = $this->file_manager->sqm_info_from($file);
			if ($sqm_info) {
				return $sqm_info;
			}
		}
		return new SQM_Info($this->sqm_id,null,null,null);
	}
	
	
	protected function sqm_info_files() {
		return $this->file_list;
	}
	
	private function check_file_additions_deletions() {
		$file_list = $this->file_manager->get_file_list();
		$new_files = array_diff($file_list,$this->file_list);
		$deleted_files = array_diff($this->file_list,$file_list);
		$this->file_readings_cache->new_files($new_files);
		foreach ($deleted_files as $file) {
			$this->pending_removed_readings = 
				array_replace($this->pending_removed_readings,
							  $this->file_readings_cache->get_readings($file)
			);
		}
		$this->file_readings_cache->deleted_files($deleted_files);
		$this->pending_removed_readings = 
			$this->check_readings_still_exist($this->pending_removed_readings);
		$this->file_list = $file_list;
	}
	
	
	private function check_readings_still_exist($potential_removed_readings) {
		$should_be_removed = array();
		foreach ($potential_removed_readings as $datetime => $value) {
			$keep = false;
			foreach ($this->file_list as $file) {
				if (isset($this->file_readings_cache->get_readings($file)[$datetime]) &&
					($this->file_readings_cache->get_readings($file)[$datetime] == $value)) {
						$keep = true;
						break;
				}
			}
			if (!$keep) { $should_be_removed[$datetime] = $value; }
		}
		return $should_be_removed;
	}
	
	
	protected function has_file_changed($file) {
		if (!$this->file_manager->file_exists($file)) {
			return true;
		}
		return $this->file_manager->filemtime($file)
					> $this->file_readings_cache->get_file_load_time($file);
	}
	
	public function all_files() {
		return $this->file_list;
	}
	
	public abstract function earliest_and_latest_readings();
	
	public abstract function files_for($start_date,$end_date);
	
	public abstract function files_for_latest();
	
	public abstract function files_for_earliest();
	
	public function new_readings_from(...$files) {
		$this->check_file_additions_deletions();
		$changed_files = array_filter($files,function ($file) {
			return $this->has_file_changed($file);
		});
		$removed_readings = $this->pending_removed_readings;
		$this->pending_removed_readings = array();
		$added_readings = $this->pending_added_readings;
		$this->pending_added_readings = array();
		if (count($changed_files) == 0) {
			return array('add'=>$added_readings,'remove'=>$removed_readings);
		}
		foreach ($changed_files as $file) {
			$this->file_readings_cache->set_file_load_time($file,time());
			try {
				$new_readings = $this->file_manager->readings_from($file);
				array_push($removed_readings,
					array_diff_assoc($this->file_readings_cache->get_readings($file),$new_readings)
				);
				array_push($added_readings,
					array_diff_assoc($new_readings,$this->file_readings_cache->get_readings($file))
				);
				$this->file_readings_cache->set_readings($file,$new_readings);
			} catch (Exception $e) {
				error_log("Could not get readings from " . $file . " : " . $e->getMessage);
			}
		}
		$removed_readings = $this->check_readings_still_exist(array_replace(...$removed_readings));
		return array('add'=>array_replace(...$added_readings),'remove'=>$removed_readings);
	}
	
	public function sqm_info() {
		return $this->sqm_info;
	}
	
	public function data_columns_for($datetime) {
		return $this->file_manager->data_columns_for($datetime);
	}
}

	


abstract class SQM_Data {
	public abstract function sqmid();

	
	public abstract function dates();
	
	
	public abstract function datetimes_and_values_by_date($date,$with_attributes = true);
	
	
	protected abstract function set_datetimes_and_values_by_date($date,$datetimes_and_values);
	
	
	public abstract function set_attributes_by_date($date,$attributes);
	
	
	public function add($sqm_data) {
		foreach (array_intersect($this->dates(),$sqm_data->dates()) as $date) {
			$current_data = $this->datetimes_and_values_by_date($date);
			$new_data = $sqm_data->datetimes_and_values_by_date($date);
			foreach ($new_data['datetimes'] as $id => $datetime) {
				array_push($current_data['datetimes'],$datetime);
				array_push($current_data['values'],$new_data['values'][$id]);
			}
			$this->set_datetimes_and_values_by_date($date,$current_data);
		}
		foreach (array_diff($sqm_data->dates(),$this->dates()) as $date) {
			$this->set_datetimes_and_values_by_date($date,
				$sqm_data->datetimes_and_values_by_date($date)
			);
		}
	}
	
	
	public function remove($sqm_data) {
		foreach ($sqm_data->dates() as $date) {
			$current_data = $this->datetimes_and_values_by_date($date);
			$data_keys = array_keys($sqm_data->datetimes_and_values_by_date($date)['datetimes']);
			foreach ($sqm_data->datetimes_and_values_by_date($date)['datetimes'] as $datetime) {
				$key = array_search($datetime,$current_data['datetimes']);
				if ($key) {
					unset($current_data['datetimes'][$key]);
					unset($current_data['values'][$key]);
				}
			}
			$current_data['datetimes'] = array_values($current_data['datetimes']);
			$current_data['values'] = array_values($current_data['values']);
			$this->set_datetimes_and_values_by_date($date,$current_data);
		}
	}
	
	
	protected function empty() {
		return array('datetimes'=>array(),'values'=>array(),'attributes'=>array());
	}
	
	
	public static function sort_datetimes_and_values($datetimes_and_values) {
		$new_datetimes = array();
		$new_values = array();
		$new_attributes = array();
		asort($datetimes_and_values['datetimes']);
		foreach ($datetimes_and_values['datetimes'] as $key => $datetime) {
			array_push($new_datetimes,$datetime);
			array_push($new_values,$datetimes_and_values['values'][$key]);
			
			if (isset($datetimes_and_values['attributes'][$key])) {
				array_push($new_attributes,$datetimes_and_values['attributes'][$key]);
			}
		}
		return array(
			'datetimes'=>$new_datetimes,
			'values'=>$new_values,
			'attributes'=>$new_attributes
		);
	}
}


abstract class SQM_Best_Nightly_Data {
	
	public abstract function get_datetimes_and_values();
	
	
	public abstract function set_best_nightly_readings($datetimes,$values,$attributes);
}


abstract class SQM_Data_Factory {
	protected static $instance;
	
	public static function exists() {
		return SQM_Data_Factory::$instance != null;
	}
	
	public static function create_sqm_data($sqm_id) {
		return SQM_Data_Factory::$instance->build_sqm_data($sqm_id);
	}
	
	public static function create_best_nightly_data($sqm_id) {
		return SQM_Data_Factory::$instance->build_best_nightly_data($sqm_id);
	}
	
	protected abstract function build_sqm_data($sqm_id);
	protected abstract function build_best_nightly_data($sqm_id);
}


	

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
	
	
	public static function create_from($readings_string_key_array,$time_zone) {
		$result = new SQM_Data_In_Memory();
		foreach ($readings_string_key_array as $datetime_string => $value_string) {
			
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

/*
 SunCalc is a PHP library for calculating sun/moon position and light phases.
 https://github.com/gregseth/suncalc-php

 Based on Vladimir Agafonkin's JavaScript library.
 https://github.com/mourner/suncalc

 Sun calculations are based on http://aa.quae.nl/en/reken/zonpositie.html
 formulas.

 Moon calculations are based on http://aa.quae.nl/en/reken/hemelpositie.html
 formulas.

 Calculations for illumination parameters of the moon are based on
 http://idlastro.gsfc.nasa.gov/ftp/pro/astro/mphase.pro formulas and Chapter 48
 of "Astronomical Algorithms" 2nd edition by Jean Meeus (Willmann-Bell,
 Richmond) 1998.

 Calculations for moon rise/set times are based on
 http://www.stargazing.net/kepler/moonrise.html article.
*/


// shortcuts for easier to read formulas
define('PI', M_PI);
define('rad', PI / 180);


// date/time constants and conversions
define('daySec', 60 * 60 * 24);
define('J1970', 2440588);
define('J2000', 2451545);
// general calculations for position
define('e', rad * 23.4397); // obliquity of the Earth
define('J0', 0.0009);


function toJulian($date) { return $date->getTimestamp() / daySec - 0.5 + J1970; }
function fromJulian($j, $d)  {
    if (!is_nan($j)) {
        $dt = new \DateTime("@".round(($j + 0.5 - J1970) * daySec));
        $dt->setTimezone($d->getTimezone());
        return $dt;
    }
}
function toDays($date)   { return toJulian($date) - J2000; }

function rightAscension($l, $b) { return atan2(sin($l) * cos(e) - tan($b) * sin(e), cos($l)); }
function declination($l, $b)    { return asin(sin($b) * cos(e) + cos($b) * sin(e) * sin($l)); }

function azimuth($H, $phi, $dec)  { return atan2(sin($H), cos($H) * sin($phi) - tan($dec) * cos($phi)); }
function altitude($H, $phi, $dec) { return asin(sin($phi) * sin($dec) + cos($phi) * cos($dec) * cos($H)); }

function siderealTime($d, $lw) { return rad * (280.16 + 360.9856235 * $d) - $lw; }

// calculations for sun times
function julianCycle($d, $lw) { return round($d - J0 - $lw / (2 * PI)); }

function approxTransit($Ht, $lw, $n) { return J0 + ($Ht + $lw) / (2 * PI) + $n; }
function solarTransitJ($ds, $M, $L)  { return J2000 + $ds + 0.0053 * sin($M) - 0.0069 * sin(2 * $L); }

function hourAngle($h, $phi, $d) { return acos((sin($h) - sin($phi) * sin($d)) / (cos($phi) * cos($d))); }

// returns set time for the given sun altitude
function getSetJ($h, $lw, $phi, $dec, $n, $M, $L) {
    $w = hourAngle($h, $phi, $dec);
    $a = approxTransit($w, $lw, $n);
    return solarTransitJ($a, $M, $L);
}

// general sun calculations
function solarMeanAnomaly($d) { return rad * (357.5291 + 0.98560028 * $d); }
function eclipticLongitude($M) {

    $C = rad * (1.9148 * sin($M) + 0.02 * sin(2 * $M) + 0.0003 * sin(3 * $M)); // equation of center
    $P = rad * 102.9372; // perihelion of the Earth

    return $M + $C + $P + PI;
}

function hoursLater($date, $h) {
    $dt = clone $date;
    return $dt->add( new DateInterval('PT'.round($h*3600).'S') );
}

class DecRa {
    public $dec;
    public $ra;

    function __construct($d, $r) {
        $this->dec = $d;
        $this->ra  = $r;
    }
}

class DecRaDist extends DecRa {
    public $dist;

    function __construct($d, $r, $dist) {
        parent::__construct($d, $r);
        $this->dist = $dist;
    }
}

class AzAlt {
    public $azimuth;
    public $altitude;

    function __construct($az, $alt) {
        $this->azimuth = $az;
        $this->altitude = $alt;
    }
}

class AzAltDist extends AzAlt {
    public $dist;

    function __construct($az, $alt, $dist) {
        parent::__construct($az, $alt);
        $this->dist = $dist;
    }
}

function sunCoords($d) {

    $M = solarMeanAnomaly($d);
    $L = eclipticLongitude($M);

    return new DecRa(
        declination($L, 0),
        rightAscension($L, 0)
    );
}

function moonCoords($d) { // geocentric ecliptic coordinates of the moon

    $L = rad * (218.316 + 13.176396 * $d); // ecliptic longitude
    $M = rad * (134.963 + 13.064993 * $d); // mean anomaly
    $F = rad * (93.272 + 13.229350 * $d);  // mean distance

    $l  = $L + rad * 6.289 * sin($M); // longitude
    $b  = rad * 5.128 * sin($F);     // latitude
    $dt = 385001 - 20905 * cos($M);  // distance to the moon in km

    return new DecRaDist(
        declination($l, $b),
        rightAscension($l, $b),
        $dt
    );
}


class SunCalc {

    var $date;
    var $lat;
    var $lng;

    // sun times configuration (angle, morning name, evening name)
    private $times = [
        [-0.833, 'sunrise',       'sunset'      ],
        [  -0.3, 'sunriseEnd',    'sunsetStart' ],
        [    -6, 'dawn',          'dusk'        ],
        [   -12, 'nauticalDawn',  'nauticalDusk'],
        [   -18, 'nightEnd',      'night'       ],
        [     6, 'goldenHourEnd', 'goldenHour'  ]
    ];

    // adds a custom time to the times config
    private function addTime($angle, $riseName, $setName) {
        $this->times[] = [$angle, $riseName, $setName];
    }

    function __construct($date, $lat, $lng) {
        $this->date = $date;
        $this->lat  = $lat;
        $this->lng  = $lng;
    }

    // calculates sun position for a given date and latitude/longitude
    function getSunPosition() {

        $lw  = rad * -$this->lng;
        $phi = rad * $this->lat;
        $d   = toDays($this->date);

        $c   = sunCoords($d);
        $H   = siderealTime($d, $lw) - $c->ra;

        return new AzAlt(
            azimuth($H, $phi, $c->dec),
            altitude($H, $phi, $c->dec)
        );
    }

    // calculates sun times for a given date and latitude/longitude
    function getSunTimes() {

        $lw = rad * -$this->lng;
        $phi = rad * $this->lat;

        $d = toDays($this->date);
        $n = julianCycle($d, $lw);
        $ds = approxTransit(0, $lw, $n);

        $M = solarMeanAnomaly($ds);
        $L = eclipticLongitude($M);
        $dec = declination($L, 0);

        $Jnoon = solarTransitJ($ds, $M, $L);

        $result = [
            'solarNoon'=> fromJulian($Jnoon, $this->date),
            'nadir'    => fromJulian($Jnoon - 0.5, $this->date)
        ];

        for ($i = 0, $len = count($this->times); $i < $len; $i += 1) {
            $time = $this->times[$i];

            $Jset = getSetJ($time[0] * rad, $lw, $phi, $dec, $n, $M, $L);
            $Jrise = $Jnoon - ($Jset - $Jnoon);

            $result[$time[1]] = fromJulian($Jrise, $this->date);
            $result[$time[2]] = fromJulian($Jset, $this->date);
        }

        return $result;
    }


    function getMoonPosition($date) {
        $lw  = rad * -$this->lng;
        $phi = rad * $this->lat;
        $d   = toDays($date);

        $c = moonCoords($d);
        $H = siderealTime($d, $lw) - $c->ra;
        $h = altitude($H, $phi, $c->dec);

        // altitude correction for refraction
        $h = $h + rad * 0.017 / tan($h + rad * 10.26 / ($h + rad * 5.10));

        return new AzAltDist(
            azimuth($H, $phi, $c->dec),
            $h,
            $c->dist
        );
    }


    function getMoonIllumination() {

        $d = toDays($this->date);
        $s = sunCoords($d);
        $m = moonCoords($d);

        $sdist = 149598000; // distance from Earth to Sun in km

        $phi = acos(sin($s->dec) * sin($m->dec) + cos($s->dec) * cos($m->dec) * cos($s->ra - $m->ra));
        $inc = atan2($sdist * sin($phi), $m->dist - $sdist * cos($phi));
        $angle = atan2(cos($s->dec) * sin($s->ra - $m->ra), sin($s->dec) * cos($m->dec) - cos($s->dec) * sin($m->dec) * cos($s->ra - $m->ra));

        return [
            'fraction' => (1 + cos($inc)) / 2,
            'phase'    => 0.5 + 0.5 * $inc * ($angle < 0 ? -1 : 1) / PI,
            'angle'    => $angle
        ];
    }

    function getMoonTimes($inUTC=false) {
        $t = clone $this->date;
        if ($inUTC) $t->setTimezone(new \DateTimeZone('UTC'));

        $t->setTime(0, 0, 0);

        $hc = 0.133 * rad;
        $h0 = $this->getMoonPosition($t, $this->lat, $this->lng)->altitude - $hc;
        $rise = 0;
        $set = 0;

        // go in 2-hour chunks, each time seeing if a 3-point quadratic curve crosses zero (which means rise or set)
        for ($i = 1; $i <= 24; $i += 2) {
            $h1 = $this->getMoonPosition(hoursLater($t, $i), $this->lat, $this->lng)->altitude - $hc;
            $h2 = $this->getMoonPosition(hoursLater($t, $i + 1), $this->lat, $this->lng)->altitude - $hc;

            $a = ($h0 + $h2) / 2 - $h1;
            $b = ($h2 - $h0) / 2;
            $xe = -$b / (2 * $a);
            $ye = ($a * $xe + $b) * $xe + $h1;
            $d = $b * $b - 4 * $a * $h1;
            $roots = 0;

            if ($d >= 0) {
                $dx = sqrt($d) / (abs($a) * 2);
                $x1 = $xe - $dx;
                $x2 = $xe + $dx;
                if (abs($x1) <= 1) $roots++;
                if (abs($x2) <= 1) $roots++;
                if ($x1 < -1) $x1 = $x2;
            }

            if ($roots === 1) {
                if ($h0 < 0) $rise = $i + $x1;
                else $set = $i + $x1;

            } else if ($roots === 2) {
                $rise = $i + ($ye < 0 ? $x2 : $x1);
                $set = $i + ($ye < 0 ? $x1 : $x2);
            }

            if ($rise != 0 && $set != 0) break;

            $h0 = $h2;
        }

        $result = [];

        if ($rise != 0) $result['moonrise'] = hoursLater($t, $rise);
        if ($set != 0) $result['moonset'] = hoursLater($t, $set);

        if ($rise==0 && $set==0) $result[$ye > 0 ? 'alwaysUp' : 'alwaysDown'] = true;

        return $result;
    }
}

// tests
/*
$test = new SunCalc(new \DateTime(), 48.85, 2.35);
print_r($test->getSunTimes());
print_r($test->getMoonIllumination());
print_r($test->getMoonTimes());
print_r(getMoonPosition(new \DateTime(), 48.85, 2.35));
*/

/* SunCalc is licensed under:

                    GNU GENERAL PUBLIC LICENSE
                       Version 2, June 1991

 Copyright (C) 1989, 1991 Free Software Foundation, Inc., <http://fsf.org/>
 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA
 Everyone is permitted to copy and distribute verbatim copies
 of this license document, but changing it is not allowed.

                            Preamble

  The licenses for most software are designed to take away your
freedom to share and change it.  By contrast, the GNU General Public
License is intended to guarantee your freedom to share and change free
software--to make sure the software is free for all its users.  This
General Public License applies to most of the Free Software
Foundation's software and to any other program whose authors commit to
using it.  (Some other Free Software Foundation software is covered by
the GNU Lesser General Public License instead.)  You can apply it to
your programs, too.

  When we speak of free software, we are referring to freedom, not
price.  Our General Public Licenses are designed to make sure that you
have the freedom to distribute copies of free software (and charge for
this service if you wish), that you receive source code or can get it
if you want it, that you can change the software or use pieces of it
in new free programs; and that you know you can do these things.

  To protect your rights, we need to make restrictions that forbid
anyone to deny you these rights or to ask you to surrender the rights.
These restrictions translate to certain responsibilities for you if you
distribute copies of the software, or if you modify it.

  For example, if you distribute copies of such a program, whether
gratis or for a fee, you must give the recipients all the rights that
you have.  You must make sure that they, too, receive or can get the
source code.  And you must show them these terms so they know their
rights.

  We protect your rights with two steps: (1) copyright the software, and
(2) offer you this license which gives you legal permission to copy,
distribute and/or modify the software.

  Also, for each author's protection and ours, we want to make certain
that everyone understands that there is no warranty for this free
software.  If the software is modified by someone else and passed on, we
want its recipients to know that what they have is not the original, so
that any problems introduced by others will not reflect on the original
authors' reputations.

  Finally, any free program is threatened constantly by software
patents.  We wish to avoid the danger that redistributors of a free
program will individually obtain patent licenses, in effect making the
program proprietary.  To prevent this, we have made it clear that any
patent must be licensed for everyone's free use or not licensed at all.

  The precise terms and conditions for copying, distribution and
modification follow.

                    GNU GENERAL PUBLIC LICENSE
   TERMS AND CONDITIONS FOR COPYING, DISTRIBUTION AND MODIFICATION

  0. This License applies to any program or other work which contains
a notice placed by the copyright holder saying it may be distributed
under the terms of this General Public License.  The "Program", below,
refers to any such program or work, and a "work based on the Program"
means either the Program or any derivative work under copyright law:
that is to say, a work containing the Program or a portion of it,
either verbatim or with modifications and/or translated into another
language.  (Hereinafter, translation is included without limitation in
the term "modification".)  Each licensee is addressed as "you".

Activities other than copying, distribution and modification are not
covered by this License; they are outside its scope.  The act of
running the Program is not restricted, and the output from the Program
is covered only if its contents constitute a work based on the
Program (independent of having been made by running the Program).
Whether that is true depends on what the Program does.

  1. You may copy and distribute verbatim copies of the Program's
source code as you receive it, in any medium, provided that you
conspicuously and appropriately publish on each copy an appropriate
copyright notice and disclaimer of warranty; keep intact all the
notices that refer to this License and to the absence of any warranty;
and give any other recipients of the Program a copy of this License
along with the Program.

You may charge a fee for the physical act of transferring a copy, and
you may at your option offer warranty protection in exchange for a fee.

  2. You may modify your copy or copies of the Program or any portion
of it, thus forming a work based on the Program, and copy and
distribute such modifications or work under the terms of Section 1
above, provided that you also meet all of these conditions:

    a) You must cause the modified files to carry prominent notices
    stating that you changed the files and the date of any change.

    b) You must cause any work that you distribute or publish, that in
    whole or in part contains or is derived from the Program or any
    part thereof, to be licensed as a whole at no charge to all third
    parties under the terms of this License.

    c) If the modified program normally reads commands interactively
    when run, you must cause it, when started running for such
    interactive use in the most ordinary way, to print or display an
    announcement including an appropriate copyright notice and a
    notice that there is no warranty (or else, saying that you provide
    a warranty) and that users may redistribute the program under
    these conditions, and telling the user how to view a copy of this
    License.  (Exception: if the Program itself is interactive but
    does not normally print such an announcement, your work based on
    the Program is not required to print an announcement.)

These requirements apply to the modified work as a whole.  If
identifiable sections of that work are not derived from the Program,
and can be reasonably considered independent and separate works in
themselves, then this License, and its terms, do not apply to those
sections when you distribute them as separate works.  But when you
distribute the same sections as part of a whole which is a work based
on the Program, the distribution of the whole must be on the terms of
this License, whose permissions for other licensees extend to the
entire whole, and thus to each and every part regardless of who wrote it.

Thus, it is not the intent of this section to claim rights or contest
your rights to work written entirely by you; rather, the intent is to
exercise the right to control the distribution of derivative or
collective works based on the Program.

In addition, mere aggregation of another work not based on the Program
with the Program (or with a work based on the Program) on a volume of
a storage or distribution medium does not bring the other work under
the scope of this License.

  3. You may copy and distribute the Program (or a work based on it,
under Section 2) in object code or executable form under the terms of
Sections 1 and 2 above provided that you also do one of the following:

    a) Accompany it with the complete corresponding machine-readable
    source code, which must be distributed under the terms of Sections
    1 and 2 above on a medium customarily used for software interchange; or,

    b) Accompany it with a written offer, valid for at least three
    years, to give any third party, for a charge no more than your
    cost of physically performing source distribution, a complete
    machine-readable copy of the corresponding source code, to be
    distributed under the terms of Sections 1 and 2 above on a medium
    customarily used for software interchange; or,

    c) Accompany it with the information you received as to the offer
    to distribute corresponding source code.  (This alternative is
    allowed only for noncommercial distribution and only if you
    received the program in object code or executable form with such
    an offer, in accord with Subsection b above.)

The source code for a work means the preferred form of the work for
making modifications to it.  For an executable work, complete source
code means all the source code for all modules it contains, plus any
associated interface definition files, plus the scripts used to
control compilation and installation of the executable.  However, as a
special exception, the source code distributed need not include
anything that is normally distributed (in either source or binary
form) with the major components (compiler, kernel, and so on) of the
operating system on which the executable runs, unless that component
itself accompanies the executable.

If distribution of executable or object code is made by offering
access to copy from a designated place, then offering equivalent
access to copy the source code from the same place counts as
distribution of the source code, even though third parties are not
compelled to copy the source along with the object code.

  4. You may not copy, modify, sublicense, or distribute the Program
except as expressly provided under this License.  Any attempt
otherwise to copy, modify, sublicense or distribute the Program is
void, and will automatically terminate your rights under this License.
However, parties who have received copies, or rights, from you under
this License will not have their licenses terminated so long as such
parties remain in full compliance.

  5. You are not required to accept this License, since you have not
signed it.  However, nothing else grants you permission to modify or
distribute the Program or its derivative works.  These actions are
prohibited by law if you do not accept this License.  Therefore, by
modifying or distributing the Program (or any work based on the
Program), you indicate your acceptance of this License to do so, and
all its terms and conditions for copying, distributing or modifying
the Program or works based on it.

  6. Each time you redistribute the Program (or any work based on the
Program), the recipient automatically receives a license from the
original licensor to copy, distribute or modify the Program subject to
these terms and conditions.  You may not impose any further
restrictions on the recipients' exercise of the rights granted herein.
You are not responsible for enforcing compliance by third parties to
this License.

  7. If, as a consequence of a court judgment or allegation of patent
infringement or for any other reason (not limited to patent issues),
conditions are imposed on you (whether by court order, agreement or
otherwise) that contradict the conditions of this License, they do not
excuse you from the conditions of this License.  If you cannot
distribute so as to satisfy simultaneously your obligations under this
License and any other pertinent obligations, then as a consequence you
may not distribute the Program at all.  For example, if a patent
license would not permit royalty-free redistribution of the Program by
all those who receive copies directly or indirectly through you, then
the only way you could satisfy both it and this License would be to
refrain entirely from distribution of the Program.

If any portion of this section is held invalid or unenforceable under
any particular circumstance, the balance of the section is intended to
apply and the section as a whole is intended to apply in other
circumstances.

It is not the purpose of this section to induce you to infringe any
patents or other property right claims or to contest validity of any
such claims; this section has the sole purpose of protecting the
integrity of the free software distribution system, which is
implemented by public license practices.  Many people have made
generous contributions to the wide range of software distributed
through that system in reliance on consistent application of that
system; it is up to the author/donor to decide if he or she is willing
to distribute software through any other system and a licensee cannot
impose that choice.

This section is intended to make thoroughly clear what is believed to
be a consequence of the rest of this License.

  8. If the distribution and/or use of the Program is restricted in
certain countries either by patents or by copyrighted interfaces, the
original copyright holder who places the Program under this License
may add an explicit geographical distribution limitation excluding
those countries, so that distribution is permitted only in or among
countries not thus excluded.  In such case, this License incorporates
the limitation as if written in the body of this License.

  9. The Free Software Foundation may publish revised and/or new versions
of the General Public License from time to time.  Such new versions will
be similar in spirit to the present version, but may differ in detail to
address new problems or concerns.

Each version is given a distinguishing version number.  If the Program
specifies a version number of this License which applies to it and "any
later version", you have the option of following the terms and conditions
either of that version or of any later version published by the Free
Software Foundation.  If the Program does not specify a version number of
this License, you may choose any version ever published by the Free Software
Foundation.

  10. If you wish to incorporate parts of the Program into other free
programs whose distribution conditions are different, write to the author
to ask for permission.  For software which is copyrighted by the Free
Software Foundation, write to the Free Software Foundation; we sometimes
make exceptions for this.  Our decision will be guided by the two goals
of preserving the free status of all derivatives of our free software and
of promoting the sharing and reuse of software generally.

                            NO WARRANTY

  11. BECAUSE THE PROGRAM IS LICENSED FREE OF CHARGE, THERE IS NO WARRANTY
FOR THE PROGRAM, TO THE EXTENT PERMITTED BY APPLICABLE LAW.  EXCEPT WHEN
OTHERWISE STATED IN WRITING THE COPYRIGHT HOLDERS AND/OR OTHER PARTIES
PROVIDE THE PROGRAM "AS IS" WITHOUT WARRANTY OF ANY KIND, EITHER EXPRESSED
OR IMPLIED, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF
MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE.  THE ENTIRE RISK AS
TO THE QUALITY AND PERFORMANCE OF THE PROGRAM IS WITH YOU.  SHOULD THE
PROGRAM PROVE DEFECTIVE, YOU ASSUME THE COST OF ALL NECESSARY SERVICING,
REPAIR OR CORRECTION.

  12. IN NO EVENT UNLESS REQUIRED BY APPLICABLE LAW OR AGREED TO IN WRITING
WILL ANY COPYRIGHT HOLDER, OR ANY OTHER PARTY WHO MAY MODIFY AND/OR
REDISTRIBUTE THE PROGRAM AS PERMITTED ABOVE, BE LIABLE TO YOU FOR DAMAGES,
INCLUDING ANY GENERAL, SPECIAL, INCIDENTAL OR CONSEQUENTIAL DAMAGES ARISING
OUT OF THE USE OR INABILITY TO USE THE PROGRAM (INCLUDING BUT NOT LIMITED
TO LOSS OF DATA OR DATA BEING RENDERED INACCURATE OR LOSSES SUSTAINED BY
YOU OR THIRD PARTIES OR A FAILURE OF THE PROGRAM TO OPERATE WITH ANY OTHER
PROGRAMS), EVEN IF SUCH HOLDER OR OTHER PARTY HAS BEEN ADVISED OF THE
POSSIBILITY OF SUCH DAMAGES.

                     END OF TERMS AND CONDITIONS

            How to Apply These Terms to Your New Programs

  If you develop a new program, and you want it to be of the greatest
possible use to the public, the best way to achieve this is to make it
free software which everyone can redistribute and change under these terms.

  To do so, attach the following notices to the program.  It is safest
to attach them to the start of each source file to most effectively
convey the exclusion of warranty; and each file should have at least
the "copyright" line and a pointer to where the full notice is found.

    {description}
    Copyright (C) {year}  {fullname}

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License along
    with this program; if not, write to the Free Software Foundation, Inc.,
    51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.

Also add information on how to contact you by electronic and paper mail.

If the program is interactive, make it output a short notice like this
when it starts in an interactive mode:

    Gnomovision version 69, Copyright (C) year name of author
    Gnomovision comes with ABSOLUTELY NO WARRANTY; for details type `show w'.
    This is free software, and you are welcome to redistribute it
    under certain conditions; type `show c' for details.

The hypothetical commands `show w' and `show c' should show the appropriate
parts of the General Public License.  Of course, the commands you use may
be called something other than `show w' and `show c'; they could even be
mouse-clicks or menu items--whatever suits your program.

You should also get your employer (if you work as a programmer) or your
school, if any, to sign a "copyright disclaimer" for the program, if
necessary.  Here is a sample; alter the names:

  Yoyodyne, Inc., hereby disclaims all copyright interest in the program
  `Gnomovision' (which makes passes at compilers) written by James Hacker.

  {signature of Ty Coon}, 1 April 1989
  Ty Coon, President of Vice

This General Public License does not permit incorporating your program into
proprietary programs.  If your program is a subroutine library, you may
consider it more useful to permit linking proprietary applications with the
library.  If this is what you want to do, use the GNU Lesser General
Public License instead of this License.

*/

if ($add_sun_moon_info === true) {
	SQM_Data_Attributes::include_module(SQM_Data_Attributes_Sun_Moon_Module::class);
}

if ($perform_regression_analysis === true) {
	SQM_Data_Attributes::include_module(SQM_Data_Attributes_Regression_Analysis_Module::class);
}

if ($add_raw_data === true) {
	SQM_Data_Attributes::include_module(SQM_Data_Attributes_From_Data_Files::class);
}

$cacheing_enabled = false;
if (isset($cache_directory) && $cache_directory) {
	$cache_directory_path = $path . $cache_directory;
	if (is_dir($cache_directory_path)) {
		if (($update_cache_cli_only === true) && (!isset($is_cli) || ($is_cli !== true))) {
			SQM_Cache_Factory::initialize_read_only($cache_directory_path);
			$cacheing_enabled = true;
		} else {
			if (is_writeable($cache_directory_path)) {
				SQM_Cache_Factory::initialize($cache_directory_path,$should_block_for_cacheing);
				$cacheing_enabled = true;
			} else {
				sqm_error_log("Cache directory is not writeable");
			}
		}
	} else {
		sqm_error_log("Cache directory is not a directory");
	}
	if ($cacheing_enabled) {
		if (SQM_Cache_Factory::is_read_only()) {
			$read_only_mode = true;
		}

	


class SQM_Data_On_Disk extends SQM_Data {
	private $sqm_id;
	private $cache;
	
	public function __construct($sqm_id,$cache) {
		$this->sqm_id = $sqm_id;
		$this->cache = $cache;
	}
	
	public function sqmid() {
		return $this->sqm_id;
	}
	
	public function dates() {
		return $this->cache->scandir();
	}
	
	public function datetimes_and_values_by_date($date,$with_attributes = true) {
		$datetimes_and_values = $this->cache->load_from($date);
		if ($datetimes_and_values) {
			return $datetimes_and_values;
		} else {
			return array('datetimes'=>array(),'values'=>array(),'attributes'=>array());
		}
	}
	
	protected function set_datetimes_and_values_by_date($date,$datetimes_and_values) {
		if (count($datetimes_and_values['datetimes']) > 0) {
			$this->cache->save_to($date,SQM_Data::sort_datetimes_and_values($datetimes_and_values));
		} else {
			$this->cache->remove($date);
		}
	}
	
	public function set_attributes_by_date($date,$attributes) {
		$datetimes_and_values = $this->cache->load_from($date);
		if ($datetimes_and_values) {
			$datetimes_and_values['attributes'] = $attributes;
			$this->cache->save_to($date,SQM_Data::sort_datetimes_and_values($datetimes_and_values));
		}
	}
}


class SQM_Best_Nightly_Data_On_Disk extends SQM_Best_Nightly_Data {
	private $cache;
	
	public function __construct($cache) {
		$this->cache = $cache;
	}
	
	public function get_datetimes_and_values() {
		$cached = $this->cache->load_from("best");
		return $cached ? $cached :
						 array('datetimes'=>array(),'values'=>array(),'attributes'=>array());
	}
	
	public function set_best_nightly_readings($datetimes,$values,$attributes) {
		$datetimes_and_values = $this->get_datetimes_and_values();
		foreach ($datetimes as $date => $datetime) {
			$datetimes_and_values['datetimes'][$date] = $datetime;
			$datetimes_and_values['values'][$date] = $values[$date];
			$datetimes_and_values['attributes'][$date] = $attributes[$date];
		}
		
		ksort($datetimes_and_values['datetimes']);
		ksort($datetimes_and_values['values']);
		ksort($datetimes_and_values['attributes']);
		$this->cache->save_to("best",$datetimes_and_values);
	}
}

class SQM_Data_On_Disk_Factory extends SQM_Data_Factory {
	public static function initialize() {
		SQM_Data_Factory::$instance = new SQM_Data_On_Disk_Factory();
	}

	protected function build_sqm_data($sqm_id) {
		return new SQM_Data_On_Disk($sqm_id,SQM_Cache_Factory::create($sqm_id . "_all"));
	}
	
	protected function build_best_nightly_data($sqm_id) {
		return new SQM_Best_Nightly_Data_On_Disk(SQM_Cache_Factory::create($sqm_id . "_best"));
	}
}
		SQM_Data_On_Disk_Factory::initialize();

	


class SQM_File_Readings_Cache_On_Disk extends SQM_File_Readings_Cache {
	private $cache;
	
	public function __construct($cache) {
		$this->cache = $cache;
	}
	
	public function set_readings($file,$readings) {
		$this->cache->save_to($file,$readings);
	}
	
	public function get_readings($file) {
		$readings = $this->cache->load_from($file);
		if ($readings) {
			return $readings;
		} else {
			return parent::get_readings($file);
		}
	}
	
	public function deleted_files($deleted_files) {
		foreach ($deleted_files as $file) {
			$this->cache->remove($file);
		}
	}
	
	public function set_file_load_time($file,$timestamp) {
		
	}
	
	public function get_file_load_time($file) {
		if ($this->cache->file_exists($file)) {
			return $this->cache->filemtime($file);
		} else {
			return parent::get_file_load_time($file);
		}
	}
}

class SQM_File_Readings_Cache_On_Disk_Factory extends SQM_File_Readings_Cache_Factory {
	public static function initialize() {
		SQM_File_Readings_Cache_Factory::$instance = new SQM_File_Readings_Cache_On_Disk_Factory();
	}

	protected function build($sqm_id) {
		return new SQM_File_Readings_Cache_On_Disk(SQM_Cache_Factory::create($sqm_id . "_readings"));
	}
}
		SQM_File_Readings_Cache_On_Disk_Factory::initialize();
		if ($add_sun_moon_info == 'only if cacheing') {
			$add_sun_moon_info = true;
			SQM_Data_Attributes::include_module(SQM_Data_Attributes_Sun_Moon_Module::class);
		}
		if ($perform_regression_analysis === 'only if cacheing') {
			$perform_regression_analysis = true;
			SQM_Data_Attributes::include_module(
				SQM_Data_Attributes_Regression_Analysis_Module::class
			);
		}
		if ($add_raw_data === 'only if cacheing') {
			$add_raw_data = true;
			SQM_Data_Attributes::include_module(
				SQM_Data_Attributes_From_Data_Files::class
			);
		}
	}
}

if ($cacheing_enabled) {
	if (isset($sqm_memory_limit_if_cacheing)) {
		if (ini_set('memory_limit',$sqm_memory_limit_if_cacheing) === false) {
			sqm_error_log("Could not set memory limit");
		}
	}
} else {
	if (isset($sqm_memory_limit_if_not_cacheing)) {
		if (ini_set('memory_limit',$sqm_memory_limit_if_not_cacheing) === false) {
			sqm_error_log("Could not set memory limit");
		}
	}
}

if (($use_images === true) || (($use_images === 'only if cacheing') && $cacheing_enabled)) {
	if (isset($image_directory) && $image_directory) {

	

class SQM_Data_Attributes_Images_Module extends SQM_Data_Attributes_Module {
	private static $image_directory;
	private static $image_directory_url;
	private static $image_file_list;
	private static $image_file_datetimes;
	
	public static function initialize($image_directory,$image_directory_url) {
		SQM_Data_Attributes_Images_Module::$image_directory = $image_directory;
		SQM_Data_Attributes_Images_Module::$image_directory_url = $image_directory_url;
		SQM_Data_Attributes_Images_Module::$image_file_list = array();
		SQM_Data_Attributes_Images_Module::$image_file_datetimes = array();
		SQM_Data_Attributes_Images_Module::$old_time = 
			DateTimeImmutable::createFromFormat("U",0);
	}
	
	
	private static function build_file_list($sqm_id,$date,$timezone) {
		if (!isset(SQM_Data_Attributes_Images_Module::$image_file_datetimes[$sqm_id])) {
			SQM_Data_Attributes_Images_Module::$image_file_datetimes[$sqm_id] = array();
		}
		if (!isset(SQM_Data_Attributes_Images_Module::$image_file_datetimes[$sqm_id][$date])) {
			SQM_Data_Attributes_Images_Module::$image_file_datetimes[$sqm_id][$date] = array();
			$directory = SQM_Data_Attributes_Images_Module::$image_directory . 
				DIRECTORY_SEPARATOR . $sqm_id . DIRECTORY_SEPARATOR . 
				substr($date,0,7) . DIRECTORY_SEPARATOR . $date;
			if (file_exists($directory) && is_dir($directory)) {
				global $image_name_format;
				global $image_name_prefix_length;
				global $image_name_suffix_length;
				foreach (SQM_File_Manager::recursive_scandir($directory) as $file) {
					$file_without_prefix = substr($file,$image_name_prefix_length);
					$date_from_file = substr($file_without_prefix,0,
						strlen($file_without_prefix)-$image_name_suffix_length);
					$datetime = DateTimeImmutable::createFromFormat(
						$image_name_format,$date_from_file,$timezone
					);
					
					
					
					if ($datetime) {
						SQM_Data_Attributes_Images_Module::$image_file_datetimes[$sqm_id][$date]
							[$file] = $datetime;
					} else {
						sqm_error_log("Could not format datetime from " . $file);
					}
				}
			}
		}
	}
	
	private static $old_time;

	public static function add_attributes_from(
		&$attributes,$datetimes,$values,$sunset,$sunrise,$sqm_sun_moon_info,$fileset,$sqm_id
	) {
		$sunset_date = $sunset->format("Y-m-d");
		$sunrise_date = $sunrise->format("Y-m-d");
		SQM_Data_Attributes_Images_Module::build_file_list($sqm_id,$sunset_date,$sunset->getTimezone());
		SQM_Data_Attributes_Images_Module::build_file_list($sqm_id,$sunrise_date,$sunrise->getTimezone());
		foreach ($datetimes as $key => $datetime) {
			if (isset($attributes[$key]['image']) && $attributes[$key]['image']) {
				continue;
			}
			$closest_datetime = SQM_Data_Attributes_Images_Module::$old_time;
			$closest_file = null;
			$closest_date = $sunset_date;
			foreach (SQM_Data_Attributes_Images_Module::$image_file_datetimes[$sqm_id][$sunset_date] as 
					$file => $dt) {
				if (abs(intval($dt->format("U")) - intval($datetime->format("U"))) <
					abs(intval($closest_datetime->format("U")) - intval($datetime->format("U")))) {
						$closest_datetime = $dt;
						$closest_file = $file;
				}
			}
			foreach (SQM_Data_Attributes_Images_Module::$image_file_datetimes[$sqm_id][$sunrise_date] as 
					$file => $dt) {
				if (abs(intval($dt->format("U")) - intval($datetime->format("U"))) <
					abs(intval($closest_datetime->format("U")) - intval($datetime->format("U")))) {
						$closest_datetime = $dt;
						$closest_file = $file;
						$closest_date = $sunrise_date;
				}
			}
			$closest_datetime = $closest_datetime->setTimezone($datetime->getTimezone());
			
			global $image_time_frame;
			if ($closest_file &&
				(abs(intval($closest_datetime->format("U")) - intval($datetime->format("U"))) <
					$image_time_frame)) {
				$attributes[$key]['image'] = 
					SQM_Data_Attributes_Images_Module::$image_directory_url
					. DIRECTORY_SEPARATOR . $sqm_id
					. DIRECTORY_SEPARATOR . substr($closest_date,0,7) . DIRECTORY_SEPARATOR .
					$closest_date . DIRECTORY_SEPARATOR . $closest_file;
			} else {
				$attributes[$key]['image'] = null;
			}
		}
	}
	
	public static function add_best_nightly_attributes(
		&$attributes,$date,$key,$datetime,$datetimes,$values,$night_attributes,
		$sqm_sun_moon_info,$fileset,$datetime_keys_at_night
	) {
		$attributes['image'] = $night_attributes[$key]['image'];
	}
}


class SQM_Data_Attributes_Resize_Images_Module extends SQM_Data_Attributes_Module {
	private static $image_directory;
	private static $directory;
	private static $directory_url;
	private static $create;
	
	
	public static function initialize(
		$image_directory,$resized_directory,$resized_directory_url,$create
	) {
		SQM_Data_Attributes_Resize_Images_Module::$directory_url = $resized_directory_url;
		SQM_Data_Attributes_Resize_Images_Module::$image_directory = $image_directory;
		SQM_Data_Attributes_Resize_Images_Module::$directory = $resized_directory;
		SQM_Data_Attributes_Resize_Images_Module::$create = $create;
	}
	
	
	private static function extract_path($file_path) {
		$parts = explode(DIRECTORY_SEPARATOR,$file_path);
		$path = "";
		for ($i=0;$i<count($parts)-1;$i++) {
			$path = $path . $parts[$i] . DIRECTORY_SEPARATOR;
		}
		return $path;
	}
	
	
	private static function resize($image_path,$resized_path,$new_width) {
		sqm_error_log("Resizing image " . $image_path,2);
		global $extended_time;
		if ($extended_time) {
			set_time_limit(300);
		}
		$path = SQM_Data_Attributes_Resize_Images_Module::extract_path($resized_path);
		if (!is_dir($path)) {
			mkdir($path,0777,true);
		}
		$source_image = imagecreatefromstring(file_get_contents($image_path));
		$width = imagesx($source_image);
		$height = imagesy($source_image);
		$new_height = floor($height * ($new_width / $width));
		$virtual_image = imagecreatetruecolor($new_width, $new_height);
		imagecopyresampled($virtual_image, $source_image, 0, 0, 0, 0,
			$new_width, $new_height, $width, $height
		);
		imagejpeg($virtual_image, $resized_path);
	}
	
	public static function add_attributes_from(
		&$attributes,$datetimes,$values,$sunset,$sunrise,$sqm_sun_moon_info,$fileset,$sqm_id
	) {
		global $resized_widths;
		$resized_name = array_key_first($resized_widths);
		foreach ($datetimes as $key => $datetime) {
			if (isset($attributes[$key][$resized_name]) && $attributes[$key][$resized_name]) {
				continue;
			}
			$image = $attributes[$key]['image'];
			if ($image) {
				$parts = explode(DIRECTORY_SEPARATOR,$image);
				$file = "";
				for ($i=1;$i<count($parts);$i++) {
					$file = $file . DIRECTORY_SEPARATOR . $parts[$i];
				}
				foreach ($resized_widths as $name => $resized_width) {
					$resized_path = SQM_Data_Attributes_Resize_Images_Module::$directory .
						DIRECTORY_SEPARATOR . $name . $file;
					if (file_exists($resized_path)) {
						$attributes[$key][$name] =
							SQM_Data_Attributes_Resize_Images_Module::$directory_url .
							DIRECTORY_SEPARATOR . $name . $file;
					} else {
						if (SQM_Data_Attributes_Resize_Images_Module::$create) {
							SQM_Data_Attributes_Resize_Images_Module::resize(
								SQM_Data_Attributes_Resize_Images_Module::$image_directory . $file,
								$resized_path, $resized_width
							);
							$attributes[$key][$name] =
								SQM_Data_Attributes_Resize_Images_Module::$directory_url .
								DIRECTORY_SEPARATOR . $name . $file;
						} else {
							$attributes[$key][$name] = null;
						}
					}
				}
			} else {
				foreach ($resized_widths as $name => $resized_width) {
					$attributes[$key][$name] = null;
				}
			}
		}
	}
	
	public static function add_best_nightly_attributes(
		&$attributes,$date,$key,$datetime,$datetimes,$values,$night_attributes,
		$sqm_sun_moon_info,$fileset,$datetime_keys_at_night
	) {
		global $resized_widths;
		foreach ($resized_widths as $name => $resized_width) {
			$attributes[$name] = $night_attributes[$key][$name];
		}
	}
}
		$image_directory_path = $path . $image_directory;
		if (is_dir($image_directory_path)) {
			SQM_Data_Attributes_Images_Module::initialize(
				$image_directory_path,$image_directory_url
			);
			SQM_Data_Attributes::include_module(SQM_Data_Attributes_Images_Module::class);
		} else {
			sqm_error_log("Image directory path is not a directory");
		}
		if (isset($resized_directory) && $resized_directory) {
			$resized_directory_path = $path . $resized_directory;
			if (is_dir($resized_directory_path)) {
				if ($resize_images && is_writeable($resized_directory_path)
						&& extension_loaded('gd')) {
					SQM_Data_Attributes_Resize_Images_Module::initialize(
						$image_directory_path,$resized_directory_path,$resized_directory_url,true
					);
				} else {
					SQM_Data_Attributes_Resize_Images_Module::initialize(
						$image_directory_path,$resized_directory_path,$resized_directory_url,false
					);
					if ($resize_images && !is_writeable($resized_directory_path)) {
						sqm_error_log("Resized directory is not writeable");
					}
					if ($resize_images && !extension_loaded('gd')) {
						sqm_error_log("GD extension not found, cannot resize images");
					}
				}
				SQM_Data_Attributes::include_module(
					SQM_Data_Attributes_Resize_Images_Module::class
				);
			} else {
				sqm_error_log("Resized image directory path is not a directory");
			}
		}
	}
}

if (($add_sun_moon_info===true) && ($perform_regression_analysis===true)) {
	SQM_Data_Attributes::include_module(SQM_Data_Attributes_Sun_Moon_Clouds_Module::class);
}



	


class SQM_Fileset_Distrusting extends SQM_Fileset_Implementation {
	public function __construct($sqm_id,$file_readings_cache,$file_manager) {
		parent::__construct($sqm_id,$file_readings_cache,$file_manager);
	}

	
	protected function sqm_info_files() {
		usort($this->file_list,function ($file_a,$file_b) {
			return 
				$this->file_manager->filemtime($file_a) > $this->file_manager->filemtime($file_b)
				? 1 : -1;
		});
		return $this->file_list;
	}
	
	
	public function earliest_and_latest_readings() {
		return null;
	}
	
	
	public function files_for($start_date,$end_date) {
		return $this->file_list;
	}
	
	public function files_for_latest() {
		return $this->file_list;
	}
	
	public function files_for_earliest() {
		return $this->file_list;
	}
	
	
	public function new_readings_from(...$files) {
		foreach ($files as $file) {
			sqm_error_log("Loaded new readings from " . $file,2);
		}
		$new_readings = parent::new_readings_from(...$files);
		ksort($new_readings['add']); 
		return $new_readings;
	}
}

class SQM_Fileset_Distrusting_Factory extends SQM_Fileset_Factory {
	public static function initialize() {
		SQM_Fileset_Factory::$instance = new SQM_Fileset_Distrusting_Factory();
	}

	protected function build($sqm_id,$file_readings_cache,$file_manager) {
		return new SQM_Fileset_Distrusting($sqm_id,$file_readings_cache,$file_manager);
	}
}
if (($trust_files === true) || (($trust_files == 'only if not cacheing') && !$cacheing_enabled)) {

	


class SQM_Fileset_Trusting extends SQM_Fileset_Implementation {
	private $files_by_month;
	
	public function __construct($sqm_id,$file_readings_cache,$file_manager) {
		parent::__construct($sqm_id,$file_readings_cache,$file_manager);
		sort($this->file_list);
		
		$this->files_by_month = array();
		foreach ($this->file_list as $file) {
			$months_from_file_name = $this->months_from_file_name($file);
			foreach ($months_from_file_name as $month) {
				if (!isset($this->files_by_month[$month])) {
					$this->files_by_month[$month] = array();
				}
				array_push($this->files_by_month[$month],$file);
			}
		}
	}
	
	public function has_files_by_month() {
		return count($this->files_by_month) > 0;
	}
	
	
	private function months_from_file_name($filename) {
		$result = array();
		if (strlen($filename) < 6) { return false; }
		$datetime = DateTime::createFromFormat("Ym",substr($filename,0,6));
		if ($datetime) {
			array_push($result,$datetime->format("Y-m"));
		}
		for ($i=strlen($filename)-7;$i >= 0;$i--) {
			$datetime = DateTime::createFromFormat("Ym",substr($filename,$i,6));
			if ($datetime) {
				array_push($result,$datetime->format("Y-m"));
			}
			$datetime = DateTime::createFromFormat("Y-m",substr($filename,$i,7));
			if ($datetime) {
				array_push($result,$datetime->format("Y-m"));
			}
		}
		return $result;
	}

	
	protected function sqm_info_files() {
		return array_reverse($this->file_list);
	}
	
	
	public function files_for($start_date,$end_date) {
		return array_merge(...array_map(function ($month) {
			return isset($this->files_by_month[$month]) ? $this->files_by_month[$month] : [];
		},SQM_Date_Utils::months_in_range($start_date,$end_date)));
	}
	
	
	public function files_for_latest() {
		return [ $this->last_file() ];
	}
	
	public function files_for_earliest() {
		return [ $this->first_file() ];
	}
	
	private function first_file() {
		return current($this->file_list);
	}
	
	private function last_file() {
		$file = end($this->file_list);
		reset($this->file_list);
		return $file;
	}
	
	
	public function earliest_and_latest_readings() {
		try {
			$earliest = $this->file_manager->first_reading_from($this->first_file());
			$latest = $this->file_manager->last_reading_from($this->last_file());
			if ($earliest && $latest) {
				return array('earliest'=>$earliest,'latest'=>$latest);
			}
			return null;
		} catch (Exception $e) {
			sqm_error_log("Could not get first and last readings: " . $e->getMessage());
			return null;
		}
	}
}

class SQM_Fileset_Trusting_Factory extends SQM_Fileset_Factory {
	public static function initialize() {
		SQM_Fileset_Factory::$instance = new SQM_Fileset_Trusting_Factory();
	}

	protected function build($sqm_id,$file_readings_cache,$file_manager) {
		$trusting_fileset = new SQM_Fileset_Trusting($sqm_id,$file_readings_cache,$file_manager);
		if ($trusting_fileset->has_files_by_month()) {
			return $trusting_fileset;
		} else {
			
			
			return new SQM_Fileset_Distrusting($sqm_id,$file_readings_cache,$file_manager);
		}
	}
}
	SQM_Fileset_Trusting_Factory::initialize();
} else {
	SQM_Fileset_Distrusting_Factory::initialize();
}


if ($cacheing_enabled && $read_only_mode) {

	

class SQM_Fileset_No_New extends SQM_Fileset_Implementation {
	public function __construct($sqm_id,$file_readings_cache,$file_manager) {
		parent::__construct($sqm_id,$file_readings_cache,$file_manager);
	}
	
	protected function has_file_changed($file) {
		return false;
	}
	
	
	public function earliest_and_latest_readings() {
		return null;
	}
	
	public function files_for($start_date,$end_date) {
		return $this->file_list;
	}
	
	public function files_for_latest() {
		return $this->file_list;
	}
	
	public function files_for_earliest() {
		return $this->file_list;
	}
	
	public function new_readings_from(...$files) {
		return false;
	}
}

class SQM_Fileset_No_New_Factory extends SQM_Fileset_Factory {
	public static function initialize() {
		SQM_Fileset_Factory::$instance = new SQM_Fileset_No_New_Factory();
	}

	protected function build($sqm_id,$file_readings_cache,$file_manager) {
		return new SQM_Fileset_No_New($sqm_id,$file_readings_cache,$file_manager);
	}
}
	SQM_Fileset_No_New_Factory::initialize();
}


	


	

interface SQM_Dataset_Manager {
	
	public function sqm_info();
	
	
	public function readings_range();
	
	
	public function daily_readings($date);
	
	
	public function nightly_readings($date,$twilight_type);
	
	
	public function all_readings_in_range($start_datetime,$end_datetime);
	
	
	public function best_nightly_readings_in_range($start_date,$end_date);
}


	


	

class SQM_Readings implements JsonSerializable {
	public static $datetime_format;

	
	public $type;
	
	public $datetimes;
	
	public $values;
	
	public $attributes;
	
	public $start_datetime;
	
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
	
	
	public static function format_datetime_json($datetime) {
		return $datetime->format(SQM_Readings::$datetime_format);
	}
}

	

class SQM_Date_Utils {
	
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
	
	
	public static function dates_in_range($start,$end,$format="Y-m-d",$interval='+1 day') {
		return array_map(function ($datetime) use ($format) { return $datetime->format($format); },
			iterator_to_array(new DatePeriod(
				(clone $start)->modify("-12 hours"),
				DateInterval::createFromDateString($interval),
				(clone $end)->modify("-12 hours")->modify($interval))));
	}
	
	
	public static function months_in_range($start_date,$end_date,$format="Y-m") {
		return array_map(function ($datetime) use ($format) { return $datetime->format($format); },
			iterator_to_array(new DatePeriod(
				SQM_Date_Utils::datetime_from_date_string($start_date),
				DateInterval::createFromDateString('+1 month'),
				SQM_Date_Utils::datetime_from_date_string($end_date)->modify("+1 month"))));
	}
}




class SQM_Sun_Moon_Info {
	private $latitude;
	private $longitude;
	private $time_zone; 

	public function __construct($latitude,$longitude,$time_zone) {
		$this->latitude = $latitude;
		$this->longitude = $longitude;
		$this->time_zone = $time_zone;
	}

	
	public function sunset_to_sunrise($date,$twilight_type) {
		$datetime = DateTime::createFromFormat("Y-m-d",$date,$this->time_zone);
		$datetime->setTime(12,0);
		return array(
			'sunset'=>$this->sun_datetimes($datetime,$twilight_type)['sunset'],
			'sunrise'=>$this->sun_datetimes($datetime->modify("+1 day"),$twilight_type)['sunrise']
		);
	}
	
	
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
	
	
	private function sun_datetimes($datetime,$twilight_type) {
		if (!$this->latitude) {
			$fake_sunset = (clone $datetime)->setTime(19,0);
			$fake_sunrise = (clone $datetime)->modify("+10 hours");
			return array('sunset'=>$fake_sunset,'sunrise'=>$fake_sunrise);
		}
		
		$tz = date_default_timezone_get();
		date_default_timezone_set($this->time_zone->getName());
		$sun_info = date_sun_info(
			$datetime->format("U"),$this->latitude,$this->longitude
		);
		date_default_timezone_set($tz);
		$sunset_key = $this->sunset_key($twilight_type);
		$sunrise_key = $this->sunrise_key($twilight_type);
		if (($sun_info[$sunrise_key] === true) || 
				($sun_info[$sunrise_key] === false)) { 
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
	
	
	private function suncalc($datetime) {
		return new SunCalc($datetime,$this->latitude,$this->longitude);
	}
	
	
	public function sun_position($datetime) {
		$sun_position = $this->suncalc($datetime)->getSunPosition();
		return array(
			'altitude' => $sun_position->altitude,
			'azimuth' => $sun_position->azimuth
		);
	}
	
	
	public function moon_position($datetime) {
		$moon_position = $this->suncalc($datetime)->getMoonPosition($datetime);
		return array(
			'altitude' => $moon_position->altitude,
			'azimuth' => $moon_position->azimuth,
			'distance' => $moon_position->dist
		);
	}
	
	
	public function moon_illumination($datetime) {
		return $this->suncalc($datetime)->getMoonIllumination();
	}
}


interface SQM_Dataset {
	
	
	
	public function all_readings_in_range($start_datetime,$end_datetime);
	
	
	public function best_nightly_readings_in_range($start_date = null,$end_date = null);
	
	
	public function all_readings_for_date($date);
	
	
	public function add_readings($readings);
	
	
	public function remove_readings($readings);
	
	
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
	
	
	
	public function set_sqm_sun_moon_info($sqm_sun_moon_info) {
		$this->sqm_sun_moon_info = $sqm_sun_moon_info;
		$this->sqm_data_attributes->set_sqm_sun_moon_info($sqm_sun_moon_info);
	}
	
	
	
	public function set_fileset($fileset) {
		$this->sqm_fileset = $fileset;
		$this->sqm_data_attributes->set_fileset($fileset);
	}
	
	public function all_readings_in_range($start_datetime,$end_datetime) { 
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
	
	public function best_nightly_readings_in_range($start_date=null,$end_date=null) { 
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
	
	
	public function remove_readings($readings) {
		$this->sqm_data->remove($readings);
		foreach ($readings->dates() as $date) {
			$this->sqm_data_attributes->compute_attributes_for_date($date);
		}
		$this->cleanup_best_nightly_readings($readings);
	}
	
	
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
		$this->dataset->set_fileset($this->fileset);
	}
	
	public function sqm_info() {
		return $this->sqm_info;
	}
	
	public function readings_range() {
		$fileset_answer = $this->fileset->earliest_and_latest_readings();
		if ($fileset_answer) { 
			return SQM_Readings::from_earliest_and_latest(
				new DateTimeImmutable($fileset_answer['earliest']['datetime']),
				new DateTimeImmutable($fileset_answer['latest']['datetime']),
				floatval($fileset_answer['earliest']['value']),
				floatval($fileset_answer['latest']['value'])
			);
		}
		
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
	
	public function best_nightly_readings_in_range($start_date,$end_date) { 
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
	
	
	
	private function load_data_from(...$files) {
		global $extended_time;
		foreach ($files as $file) {
			if ($extended_time) {
				set_time_limit(300);
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

class SQM_Dataset_Manager_Factory {
	public static function create($sqm_id) {
		try {
			return new SQM_Dataset_Manager_Implementation(
				new SQM_Dataset_Implementation(
					SQM_Data_Factory::create_sqm_data($sqm_id),
					SQM_Data_Factory::create_best_nightly_data($sqm_id),
					new SQM_Data_Attributes()
				),
				SQM_Fileset_Factory::create($sqm_id,
					SQM_File_Readings_Cache_Factory::create($sqm_id),
					SQM_File_Manager_Factory::create($sqm_id)
				)
			);
		} catch (Exception $e) {
			sqm_error_log("Failed dataset manager for " . $sqm_id . " " . $e->getMessage());
		}
		return null;
	}
}

if (!SQM_Data_Factory::exists()) {
	SQM_Data_In_Memory_Factory::initialize();
}

if (!SQM_File_Readings_Cache_Factory::exists()) {

	


class SQM_File_Readings_Cache_In_Memory extends SQM_File_Readings_Cache {
	private $file_list;
	private $file_load_times;
	private $readings_by_file;
	
	public function __construct() {
		$this->file_list = array();
		$this->file_load_times = array();
		$this->readings_by_file = array();
	}
	
	public function deleted_files($deleted_files) {
		$this->file_list = array_diff($this->file_list,$deleted_files);
		foreach ($deleted_files as $file) {
			unset($this->file_load_times[$file]);
			unset($this->readings_by_file[$file]);
		}
	}
	
	public function get_readings($file) {
		return (isset($this->readings_by_file[$file])) ? 
			$this->readings_by_file[$file] : parent::get_readings($file);
	}
	
	public function set_readings($file,$readings) {
		$this->readings_by_file[$file] = $readings;
	}
	
	public function set_file_load_time($file,$timestamp) {
		$this->file_load_times[$file] = $timestamp;
	}
	
	public function get_file_load_time($file) {
		return (isset($this->file_load_times[$file])) ?
			$this->file_load_times[$file] : parent::get_file_load_time($file);
	}
}

class SQM_File_Readings_Cache_In_Memory_Factory extends SQM_File_Readings_Cache_Factory {
	public static function initialize() {
		SQM_File_Readings_Cache_Factory::$instance =
			new SQM_File_Readings_Cache_In_Memory_Factory();
	}

	protected function build($sqm_id) {
		return new SQM_File_Readings_Cache_In_Memory();
	}
}
	SQM_File_Readings_Cache_In_Memory_Factory::initialize();
}


	


	

abstract class SQM_Request {
	
	protected abstract function process_one($dataset_manager);
	
	
	public abstract function type();
	
	public function process($dataset_managers) {
		return array_map(function ($manager) { 
			return $this->process_one($manager);
		},$dataset_managers);
	}

	
	public static function validate($request) {
		if (!isset($request['type'])) {
			return SQM_Request::bad_request("No request type",$request);
		}
		$type = $request['type'];
		switch ($request['type']) {
			case 'nightly':
			case 'night':
			case 'daily':
			case 'day':
			case 'date':
				$date = SQM_Request::validate_datetimestring($request['date']);
				if (!$date) {
					return SQM_Request::bad_request("Invalid date string",$request);
				}
				break;
			case 'best_nightly_readings':
			case 'all_readings':
				if ((!isset($request['start'])) || (!$request['start'])) {
					$start = null;
				} else {
					$start = SQM_Request::validate_datetimestring($request['start']);
					if (!$start) {
						return SQM_Request::bad_request("Invalid start datetime string",$request);
					}
				}
				if ((!isset($request['end'])) || (!$request['end'])) {
					$end = null;
				} else {
					$end = SQM_Request::validate_datetimestring($request['end']);
					if (!$end) {
						return SQM_Request::bad_request("Invalid end datetime string",$request);
					}
				}
				break;
		}
		switch ($request['type']) {
			case 'info':
				return new SQM_Info_Request();
			case 'readings_range':
				return new SQM_Readings_Range_Request();
			case 'current':
				return new SQM_Current_Request();
			case 'tonight':
				return new SQM_Tonight_Request();
			case 'nightly':
			case 'night':
				if (isset($request['twilightType'])) {
					$twilight_type =
						SQM_Nightly_Request::validate_twilight_type($request['twilightType']);
				}
				if (!isset($twilight_type) || (!$twilight_type)) {
					global $default_twilight_type;
					$twilight_type = $default_twilight_type;
				}
				return new SQM_Nightly_Request($date->format("Y-m-d"),$twilight_type);
			case 'daily':
			case 'day':
			case 'date':
				return new SQM_Daily_Request($date->format("Y-m-d"));
			case 'best_nightly_readings':
				return new SQM_Best_Nightly_Readings_Request(
					$start ? $start->format("Y-m-d") : null,
					$end ? $end->format("Y-m-d") : null
				);
			case 'all_readings':
				if ((!$start) || (!$end)) {
					return SQM_Request::bad_request("No start or end datetime",$request);
				}
				return new SQM_All_Readings_Request($start,$end);
			default:
				return SQM_Request::bad_request("Invalid request type:",$request);
		}
	}
	
	public static function bad_request($message,$request) {
		return new SQM_Bad_Request($message);
	}
	
	protected static function validate_datetimestring($string) {
		$datetime = DateTimeImmutable::createFromFormat("Y-m-d",$string);
		if ($datetime) { return $datetime->setTime(12,0); }
		$datetime = DateTimeImmutable::createFromFormat("Y-m-d H:i",$string);
		if ($datetime) { return $datetime; }
		$datetime = DateTimeImmutable::createFromFormat("Y-m-d H:i:s",$string);
		if ($datetime) { return $datetime; }
		try {
			return new DateTimeImmutable($string);
		} catch (Exception $e) {
			return false;
		}
	}
}


class SQM_Bad_Request extends SQM_Request {
	private $message;
	
	public function __construct($message = "Invalid request") {
		$this->message = $message;
	}

	protected function process_one($dataset_managers) {
		return array('message'=>$this->message);
	}
	
	public function type() {
		return 'failure';
	}
}


class SQM_Info_Request extends SQM_Request {
	protected function process_one($dataset_manager) {
		return $dataset_manager->sqm_info();
	}
	
	public function type() {
		return 'info';
	}
}


class SQM_Readings_Range_Request extends SQM_Request {
	protected function process_one($dataset_manager) {
		return $dataset_manager->readings_range();
	}
	
	public function type() {
		return 'readings_range';
	}
}


class SQM_Nightly_Request extends SQM_Request {
	private $date;
	private $twilight_type;
	
	public function __construct($date,$twilight_type) {
		$this->date = $date;
		$this->twilight_type = $twilight_type;
	}
	
	protected function process_one($dataset_manager) {
		return $dataset_manager->nightly_readings($this->date,$this->twilight_type);
	}
		
	public static function validate_twilight_type($twilight_type) {
		if (in_array($twilight_type,[ 'civil', 'astronomical', 'nautical', 'night' ])) {
			return $twilight_type;
		}
		return null;
	}
	
	public function type() {
		return 'all_readings';
	}
}


class SQM_Daily_Request extends SQM_Request {
	private $date;
	
	public function __construct($date) {
		$this->date = $date;
	}
	
	protected function process_one($dataset_manager) {
		return $dataset_manager->daily_readings($this->date);
	}
	
	public function type() {
		return 'all_readings';
	}
}


class SQM_Best_Nightly_Readings_Request extends SQM_Request {
	private $start;
	private $end;
	
	public function __construct($start,$end) {
		$this->start = $start;
		$this->end = $end;
	}
	
	protected function process_one($dataset_manager) {
		return $dataset_manager->best_nightly_readings_in_range($this->start,$this->end);
	}
	
	public function type() {
		return 'best_nightly_readings';
	}
}


class SQM_All_Readings_Request extends SQM_Request {
	private $start;
	private $end;
	
	public function __construct($start,$end) {
		$this->start = $start;
		$this->end = $end;
	}
	
	protected function process_one($dataset_manager) {
		return $dataset_manager->all_readings_in_range(
			$this->start->setTimezone($dataset_manager->sqm_info->time_zone),
			$this->end->setTimezone($dataset_manager->sqm_info->time_zone)
		);
	}
	
	public function type() {
		return 'all_readings';
	}
}

class SQM_Responder {
	private $dataset_managers;
	
	public function __construct() {
		$this->dataset_managers = array();
	}
	
	
	public function respond_to($post_data) {
		if (!isset($post_data['request'])) {
			$bad_request = SQM_Request::bad_request("No request(s)",$post_data);
			$response = $bad_request->process([null]);
			return array(
				'response'=>array('type'=>$bad_request->type(),'message'=>"No request(s)")
			);
		}
		$request = $post_data['request'];
		SQM_Readings::$datetime_format = SQM_Responder::requested_datetime_format($request);
		$sqm_ids = $this->sqm_ids_from($request);
		$valid_sqm_ids = $sqm_ids['valid'];
		$invalid_sqm_ids = $sqm_ids['invalid'];
		
		$dataset_managers = array();
		foreach ($valid_sqm_ids as $sqm_id) {
			$dataset_managers[$sqm_id] = $this->dataset_manager_for($sqm_id);
			if (!$dataset_managers[$sqm_id]) {
				array_push($invalid_sqm_ids,$sqm_id);
			}
		}
		if (isset($request['queries'])) {
			$responses = array_map(function ($query) use ($dataset_managers,$invalid_sqm_ids) {
				$response = $this->respond_to_one($dataset_managers,$query);
				foreach ($invalid_sqm_ids as $sqm_id) {
					$response[$sqm_id] = null;
				}
				return $response;
			},$request['queries']);
			return array('response'=>$responses);
		}
		$response = $this->respond_to_one($dataset_managers,$post_data['request']);
		foreach ($invalid_sqm_ids as $sqm_id) {
			$response[$sqm_id] = null;
		}
		return array('response'=>$response);
	}
	
	
	public function respond_to_one($dataset_managers,$request) {
		$validated = SQM_Request::validate($request);
		$processed = $validated->process($dataset_managers);
		$processed['type'] = $validated->type();
		return $processed;
	}
	
	
	private function sqm_ids_from($request) {
		if ((isset($request['sqm_ids'])) && (!is_array($request['sqm_ids']))) {
			$request['sqm_ids'] = array($request['sqm_ids']);
		}
		if (isset($request['sqm_id'])) {
			$request['sqm_ids'] = array($request['sqm_id']);
		}
		if ((!isset($request['sqm_ids'])) || (count($request['sqm_ids']) == 0)) {
			$valid_sqm_ids = $this->available_sqms();
			$invalid_sqm_ids = array();
		} else {
			$available_sqm_ids = SQM_File_Manager_Factory::available_sqm_ids();
			$valid_sqm_ids = array_filter($request['sqm_ids'],
				function ($sqm_id) use ($available_sqm_ids) {
					return in_array($sqm_id,$available_sqm_ids);
				}
			);
			$invalid_sqm_ids = array_diff($request['sqm_ids'],$valid_sqm_ids);
		}
		return array('valid'=>$valid_sqm_ids,'invalid'=>$invalid_sqm_ids);
	}
	
	
	private function dataset_manager_for($sqm_id) {
		if ((!isset($this->dataset_managers[$sqm_id])) || (!$this->dataset_managers[$sqm_id])) {
			$this->dataset_managers[$sqm_id] = SQM_Dataset_Manager_Factory::create($sqm_id);
		}
		return $this->dataset_managers[$sqm_id];
	}
	
	private function available_sqms() {
		$available_sqms = array();
		foreach (SQM_File_Manager_Factory::available_sqm_ids() as $sqm_id) {
			$sqm_dataset_manager = $this->dataset_manager_for($sqm_id);
			if ($sqm_dataset_manager) {
				$this->dataset_managers[$sqm_id] = $sqm_dataset_manager;
				array_push($available_sqms,$sqm_id);
			}
		}
		return $available_sqms;
	}
	
	
	
	private static function requested_datetime_format($request) {
		if (isset($request['datetime_format'])) {
			if (in_array($request['datetime_format'],[ "Y-m-d H:i", "Y-m-d H:i:s", "U", "U.u" ])) {
				return $request['datetime_format'];
			}
		}
		return "Y-m-d H:i:s";
	}
}

$sqm_responder = new SQM_Responder();

if (isset($info_and_readings_preload) && $info_and_readings_preload === true) {

	$responses = array();
	foreach ($requests as $key => $request) {
		$responses[$key] = $sqm_responder->respond_to(array('request' => $request));
	}
	if (!isset($responses['response']) || ($responses['response']['fail'] !== true)) {
		echo '<script type="text/javascript">';
		echo 'SQMRequest.preloadRequest(' . json_encode($info_and_readings_request) . ',' .  json_encode($responses['info_and_readings']) . ');';
		echo' </script>';
	}

} else {

	$response = $sqm_responder->respond_to($request);
	echo json_encode($response);

}
/*
                    GNU AFFERO GENERAL PUBLIC LICENSE
                       Version 3, 19 November 2007

 Copyright (C) 2007 Free Software Foundation, Inc. <https://fsf.org/>
 Everyone is permitted to copy and distribute verbatim copies
 of this license document, but changing it is not allowed.

                            Preamble

  The GNU Affero General Public License is a free, copyleft license for
software and other kinds of works, specifically designed to ensure
cooperation with the community in the case of network server software.

  The licenses for most software and other practical works are designed
to take away your freedom to share and change the works.  By contrast,
our General Public Licenses are intended to guarantee your freedom to
share and change all versions of a program--to make sure it remains free
software for all its users.

  When we speak of free software, we are referring to freedom, not
price.  Our General Public Licenses are designed to make sure that you
have the freedom to distribute copies of free software (and charge for
them if you wish), that you receive source code or can get it if you
want it, that you can change the software or use pieces of it in new
free programs, and that you know you can do these things.

  Developers that use our General Public Licenses protect your rights
with two steps: (1) assert copyright on the software, and (2) offer
you this License which gives you legal permission to copy, distribute
and/or modify the software.

  A secondary benefit of defending all users' freedom is that
improvements made in alternate versions of the program, if they
receive widespread use, become available for other developers to
incorporate.  Many developers of free software are heartened and
encouraged by the resulting cooperation.  However, in the case of
software used on network servers, this result may fail to come about.
The GNU General Public License permits making a modified version and
letting the public access it on a server without ever releasing its
source code to the public.

  The GNU Affero General Public License is designed specifically to
ensure that, in such cases, the modified source code becomes available
to the community.  It requires the operator of a network server to
provide the source code of the modified version running there to the
users of that server.  Therefore, public use of a modified version, on
a publicly accessible server, gives the public access to the source
code of the modified version.

  An older license, called the Affero General Public License and
published by Affero, was designed to accomplish similar goals.  This is
a different license, not a version of the Affero GPL, but Affero has
released a new version of the Affero GPL which permits relicensing under
this license.

  The precise terms and conditions for copying, distribution and
modification follow.

                       TERMS AND CONDITIONS

  0. Definitions.

  "This License" refers to version 3 of the GNU Affero General Public License.

  "Copyright" also means copyright-like laws that apply to other kinds of
works, such as semiconductor masks.

  "The Program" refers to any copyrightable work licensed under this
License.  Each licensee is addressed as "you".  "Licensees" and
"recipients" may be individuals or organizations.

  To "modify" a work means to copy from or adapt all or part of the work
in a fashion requiring copyright permission, other than the making of an
exact copy.  The resulting work is called a "modified version" of the
earlier work or a work "based on" the earlier work.

  A "covered work" means either the unmodified Program or a work based
on the Program.

  To "propagate" a work means to do anything with it that, without
permission, would make you directly or secondarily liable for
infringement under applicable copyright law, except executing it on a
computer or modifying a private copy.  Propagation includes copying,
distribution (with or without modification), making available to the
public, and in some countries other activities as well.

  To "convey" a work means any kind of propagation that enables other
parties to make or receive copies.  Mere interaction with a user through
a computer network, with no transfer of a copy, is not conveying.

  An interactive user interface displays "Appropriate Legal Notices"
to the extent that it includes a convenient and prominently visible
feature that (1) displays an appropriate copyright notice, and (2)
tells the user that there is no warranty for the work (except to the
extent that warranties are provided), that licensees may convey the
work under this License, and how to view a copy of this License.  If
the interface presents a list of user commands or options, such as a
menu, a prominent item in the list meets this criterion.

  1. Source Code.

  The "source code" for a work means the preferred form of the work
for making modifications to it.  "Object code" means any non-source
form of a work.

  A "Standard Interface" means an interface that either is an official
standard defined by a recognized standards body, or, in the case of
interfaces specified for a particular programming language, one that
is widely used among developers working in that language.

  The "System Libraries" of an executable work include anything, other
than the work as a whole, that (a) is included in the normal form of
packaging a Major Component, but which is not part of that Major
Component, and (b) serves only to enable use of the work with that
Major Component, or to implement a Standard Interface for which an
implementation is available to the public in source code form.  A
"Major Component", in this context, means a major essential component
(kernel, window system, and so on) of the specific operating system
(if any) on which the executable work runs, or a compiler used to
produce the work, or an object code interpreter used to run it.

  The "Corresponding Source" for a work in object code form means all
the source code needed to generate, install, and (for an executable
work) run the object code and to modify the work, including scripts to
control those activities.  However, it does not include the work's
System Libraries, or general-purpose tools or generally available free
programs which are used unmodified in performing those activities but
which are not part of the work.  For example, Corresponding Source
includes interface definition files associated with source files for
the work, and the source code for shared libraries and dynamically
linked subprograms that the work is specifically designed to require,
such as by intimate data communication or control flow between those
subprograms and other parts of the work.

  The Corresponding Source need not include anything that users
can regenerate automatically from other parts of the Corresponding
Source.

  The Corresponding Source for a work in source code form is that
same work.

  2. Basic Permissions.

  All rights granted under this License are granted for the term of
copyright on the Program, and are irrevocable provided the stated
conditions are met.  This License explicitly affirms your unlimited
permission to run the unmodified Program.  The output from running a
covered work is covered by this License only if the output, given its
content, constitutes a covered work.  This License acknowledges your
rights of fair use or other equivalent, as provided by copyright law.

  You may make, run and propagate covered works that you do not
convey, without conditions so long as your license otherwise remains
in force.  You may convey covered works to others for the sole purpose
of having them make modifications exclusively for you, or provide you
with facilities for running those works, provided that you comply with
the terms of this License in conveying all material for which you do
not control copyright.  Those thus making or running the covered works
for you must do so exclusively on your behalf, under your direction
and control, on terms that prohibit them from making any copies of
your copyrighted material outside their relationship with you.

  Conveying under any other circumstances is permitted solely under
the conditions stated below.  Sublicensing is not allowed; section 10
makes it unnecessary.

  3. Protecting Users' Legal Rights From Anti-Circumvention Law.

  No covered work shall be deemed part of an effective technological
measure under any applicable law fulfilling obligations under article
11 of the WIPO copyright treaty adopted on 20 December 1996, or
similar laws prohibiting or restricting circumvention of such
measures.

  When you convey a covered work, you waive any legal power to forbid
circumvention of technological measures to the extent such circumvention
is effected by exercising rights under this License with respect to
the covered work, and you disclaim any intention to limit operation or
modification of the work as a means of enforcing, against the work's
users, your or third parties' legal rights to forbid circumvention of
technological measures.

  4. Conveying Verbatim Copies.

  You may convey verbatim copies of the Program's source code as you
receive it, in any medium, provided that you conspicuously and
appropriately publish on each copy an appropriate copyright notice;
keep intact all notices stating that this License and any
non-permissive terms added in accord with section 7 apply to the code;
keep intact all notices of the absence of any warranty; and give all
recipients a copy of this License along with the Program.

  You may charge any price or no price for each copy that you convey,
and you may offer support or warranty protection for a fee.

  5. Conveying Modified Source Versions.

  You may convey a work based on the Program, or the modifications to
produce it from the Program, in the form of source code under the
terms of section 4, provided that you also meet all of these conditions:

    a) The work must carry prominent notices stating that you modified
    it, and giving a relevant date.

    b) The work must carry prominent notices stating that it is
    released under this License and any conditions added under section
    7.  This requirement modifies the requirement in section 4 to
    "keep intact all notices".

    c) You must license the entire work, as a whole, under this
    License to anyone who comes into possession of a copy.  This
    License will therefore apply, along with any applicable section 7
    additional terms, to the whole of the work, and all its parts,
    regardless of how they are packaged.  This License gives no
    permission to license the work in any other way, but it does not
    invalidate such permission if you have separately received it.

    d) If the work has interactive user interfaces, each must display
    Appropriate Legal Notices; however, if the Program has interactive
    interfaces that do not display Appropriate Legal Notices, your
    work need not make them do so.

  A compilation of a covered work with other separate and independent
works, which are not by their nature extensions of the covered work,
and which are not combined with it such as to form a larger program,
in or on a volume of a storage or distribution medium, is called an
"aggregate" if the compilation and its resulting copyright are not
used to limit the access or legal rights of the compilation's users
beyond what the individual works permit.  Inclusion of a covered work
in an aggregate does not cause this License to apply to the other
parts of the aggregate.

  6. Conveying Non-Source Forms.

  You may convey a covered work in object code form under the terms
of sections 4 and 5, provided that you also convey the
machine-readable Corresponding Source under the terms of this License,
in one of these ways:

    a) Convey the object code in, or embodied in, a physical product
    (including a physical distribution medium), accompanied by the
    Corresponding Source fixed on a durable physical medium
    customarily used for software interchange.

    b) Convey the object code in, or embodied in, a physical product
    (including a physical distribution medium), accompanied by a
    written offer, valid for at least three years and valid for as
    long as you offer spare parts or customer support for that product
    model, to give anyone who possesses the object code either (1) a
    copy of the Corresponding Source for all the software in the
    product that is covered by this License, on a durable physical
    medium customarily used for software interchange, for a price no
    more than your reasonable cost of physically performing this
    conveying of source, or (2) access to copy the
    Corresponding Source from a network server at no charge.

    c) Convey individual copies of the object code with a copy of the
    written offer to provide the Corresponding Source.  This
    alternative is allowed only occasionally and noncommercially, and
    only if you received the object code with such an offer, in accord
    with subsection 6b.

    d) Convey the object code by offering access from a designated
    place (gratis or for a charge), and offer equivalent access to the
    Corresponding Source in the same way through the same place at no
    further charge.  You need not require recipients to copy the
    Corresponding Source along with the object code.  If the place to
    copy the object code is a network server, the Corresponding Source
    may be on a different server (operated by you or a third party)
    that supports equivalent copying facilities, provided you maintain
    clear directions next to the object code saying where to find the
    Corresponding Source.  Regardless of what server hosts the
    Corresponding Source, you remain obligated to ensure that it is
    available for as long as needed to satisfy these requirements.

    e) Convey the object code using peer-to-peer transmission, provided
    you inform other peers where the object code and Corresponding
    Source of the work are being offered to the general public at no
    charge under subsection 6d.

  A separable portion of the object code, whose source code is excluded
from the Corresponding Source as a System Library, need not be
included in conveying the object code work.

  A "User Product" is either (1) a "consumer product", which means any
tangible personal property which is normally used for personal, family,
or household purposes, or (2) anything designed or sold for incorporation
into a dwelling.  In determining whether a product is a consumer product,
doubtful cases shall be resolved in favor of coverage.  For a particular
product received by a particular user, "normally used" refers to a
typical or common use of that class of product, regardless of the status
of the particular user or of the way in which the particular user
actually uses, or expects or is expected to use, the product.  A product
is a consumer product regardless of whether the product has substantial
commercial, industrial or non-consumer uses, unless such uses represent
the only significant mode of use of the product.

  "Installation Information" for a User Product means any methods,
procedures, authorization keys, or other information required to install
and execute modified versions of a covered work in that User Product from
a modified version of its Corresponding Source.  The information must
suffice to ensure that the continued functioning of the modified object
code is in no case prevented or interfered with solely because
modification has been made.

  If you convey an object code work under this section in, or with, or
specifically for use in, a User Product, and the conveying occurs as
part of a transaction in which the right of possession and use of the
User Product is transferred to the recipient in perpetuity or for a
fixed term (regardless of how the transaction is characterized), the
Corresponding Source conveyed under this section must be accompanied
by the Installation Information.  But this requirement does not apply
if neither you nor any third party retains the ability to install
modified object code on the User Product (for example, the work has
been installed in ROM).

  The requirement to provide Installation Information does not include a
requirement to continue to provide support service, warranty, or updates
for a work that has been modified or installed by the recipient, or for
the User Product in which it has been modified or installed.  Access to a
network may be denied when the modification itself materially and
adversely affects the operation of the network or violates the rules and
protocols for communication across the network.

  Corresponding Source conveyed, and Installation Information provided,
in accord with this section must be in a format that is publicly
documented (and with an implementation available to the public in
source code form), and must require no special password or key for
unpacking, reading or copying.

  7. Additional Terms.

  "Additional permissions" are terms that supplement the terms of this
License by making exceptions from one or more of its conditions.
Additional permissions that are applicable to the entire Program shall
be treated as though they were included in this License, to the extent
that they are valid under applicable law.  If additional permissions
apply only to part of the Program, that part may be used separately
under those permissions, but the entire Program remains governed by
this License without regard to the additional permissions.

  When you convey a copy of a covered work, you may at your option
remove any additional permissions from that copy, or from any part of
it.  (Additional permissions may be written to require their own
removal in certain cases when you modify the work.)  You may place
additional permissions on material, added by you to a covered work,
for which you have or can give appropriate copyright permission.

  Notwithstanding any other provision of this License, for material you
add to a covered work, you may (if authorized by the copyright holders of
that material) supplement the terms of this License with terms:

    a) Disclaiming warranty or limiting liability differently from the
    terms of sections 15 and 16 of this License; or

    b) Requiring preservation of specified reasonable legal notices or
    author attributions in that material or in the Appropriate Legal
    Notices displayed by works containing it; or

    c) Prohibiting misrepresentation of the origin of that material, or
    requiring that modified versions of such material be marked in
    reasonable ways as different from the original version; or

    d) Limiting the use for publicity purposes of names of licensors or
    authors of the material; or

    e) Declining to grant rights under trademark law for use of some
    trade names, trademarks, or service marks; or

    f) Requiring indemnification of licensors and authors of that
    material by anyone who conveys the material (or modified versions of
    it) with contractual assumptions of liability to the recipient, for
    any liability that these contractual assumptions directly impose on
    those licensors and authors.

  All other non-permissive additional terms are considered "further
restrictions" within the meaning of section 10.  If the Program as you
received it, or any part of it, contains a notice stating that it is
governed by this License along with a term that is a further
restriction, you may remove that term.  If a license document contains
a further restriction but permits relicensing or conveying under this
License, you may add to a covered work material governed by the terms
of that license document, provided that the further restriction does
not survive such relicensing or conveying.

  If you add terms to a covered work in accord with this section, you
must place, in the relevant source files, a statement of the
additional terms that apply to those files, or a notice indicating
where to find the applicable terms.

  Additional terms, permissive or non-permissive, may be stated in the
form of a separately written license, or stated as exceptions;
the above requirements apply either way.

  8. Termination.

  You may not propagate or modify a covered work except as expressly
provided under this License.  Any attempt otherwise to propagate or
modify it is void, and will automatically terminate your rights under
this License (including any patent licenses granted under the third
paragraph of section 11).

  However, if you cease all violation of this License, then your
license from a particular copyright holder is reinstated (a)
provisionally, unless and until the copyright holder explicitly and
finally terminates your license, and (b) permanently, if the copyright
holder fails to notify you of the violation by some reasonable means
prior to 60 days after the cessation.

  Moreover, your license from a particular copyright holder is
reinstated permanently if the copyright holder notifies you of the
violation by some reasonable means, this is the first time you have
received notice of violation of this License (for any work) from that
copyright holder, and you cure the violation prior to 30 days after
your receipt of the notice.

  Termination of your rights under this section does not terminate the
licenses of parties who have received copies or rights from you under
this License.  If your rights have been terminated and not permanently
reinstated, you do not qualify to receive new licenses for the same
material under section 10.

  9. Acceptance Not Required for Having Copies.

  You are not required to accept this License in order to receive or
run a copy of the Program.  Ancillary propagation of a covered work
occurring solely as a consequence of using peer-to-peer transmission
to receive a copy likewise does not require acceptance.  However,
nothing other than this License grants you permission to propagate or
modify any covered work.  These actions infringe copyright if you do
not accept this License.  Therefore, by modifying or propagating a
covered work, you indicate your acceptance of this License to do so.

  10. Automatic Licensing of Downstream Recipients.

  Each time you convey a covered work, the recipient automatically
receives a license from the original licensors, to run, modify and
propagate that work, subject to this License.  You are not responsible
for enforcing compliance by third parties with this License.

  An "entity transaction" is a transaction transferring control of an
organization, or substantially all assets of one, or subdividing an
organization, or merging organizations.  If propagation of a covered
work results from an entity transaction, each party to that
transaction who receives a copy of the work also receives whatever
licenses to the work the party's predecessor in interest had or could
give under the previous paragraph, plus a right to possession of the
Corresponding Source of the work from the predecessor in interest, if
the predecessor has it or can get it with reasonable efforts.

  You may not impose any further restrictions on the exercise of the
rights granted or affirmed under this License.  For example, you may
not impose a license fee, royalty, or other charge for exercise of
rights granted under this License, and you may not initiate litigation
(including a cross-claim or counterclaim in a lawsuit) alleging that
any patent claim is infringed by making, using, selling, offering for
sale, or importing the Program or any portion of it.

  11. Patents.

  A "contributor" is a copyright holder who authorizes use under this
License of the Program or a work on which the Program is based.  The
work thus licensed is called the contributor's "contributor version".

  A contributor's "essential patent claims" are all patent claims
owned or controlled by the contributor, whether already acquired or
hereafter acquired, that would be infringed by some manner, permitted
by this License, of making, using, or selling its contributor version,
but do not include claims that would be infringed only as a
consequence of further modification of the contributor version.  For
purposes of this definition, "control" includes the right to grant
patent sublicenses in a manner consistent with the requirements of
this License.

  Each contributor grants you a non-exclusive, worldwide, royalty-free
patent license under the contributor's essential patent claims, to
make, use, sell, offer for sale, import and otherwise run, modify and
propagate the contents of its contributor version.

  In the following three paragraphs, a "patent license" is any express
agreement or commitment, however denominated, not to enforce a patent
(such as an express permission to practice a patent or covenant not to
sue for patent infringement).  To "grant" such a patent license to a
party means to make such an agreement or commitment not to enforce a
patent against the party.

  If you convey a covered work, knowingly relying on a patent license,
and the Corresponding Source of the work is not available for anyone
to copy, free of charge and under the terms of this License, through a
publicly available network server or other readily accessible means,
then you must either (1) cause the Corresponding Source to be so
available, or (2) arrange to deprive yourself of the benefit of the
patent license for this particular work, or (3) arrange, in a manner
consistent with the requirements of this License, to extend the patent
license to downstream recipients.  "Knowingly relying" means you have
actual knowledge that, but for the patent license, your conveying the
covered work in a country, or your recipient's use of the covered work
in a country, would infringe one or more identifiable patents in that
country that you have reason to believe are valid.

  If, pursuant to or in connection with a single transaction or
arrangement, you convey, or propagate by procuring conveyance of, a
covered work, and grant a patent license to some of the parties
receiving the covered work authorizing them to use, propagate, modify
or convey a specific copy of the covered work, then the patent license
you grant is automatically extended to all recipients of the covered
work and works based on it.

  A patent license is "discriminatory" if it does not include within
the scope of its coverage, prohibits the exercise of, or is
conditioned on the non-exercise of one or more of the rights that are
specifically granted under this License.  You may not convey a covered
work if you are a party to an arrangement with a third party that is
in the business of distributing software, under which you make payment
to the third party based on the extent of your activity of conveying
the work, and under which the third party grants, to any of the
parties who would receive the covered work from you, a discriminatory
patent license (a) in connection with copies of the covered work
conveyed by you (or copies made from those copies), or (b) primarily
for and in connection with specific products or compilations that
contain the covered work, unless you entered into that arrangement,
or that patent license was granted, prior to 28 March 2007.

  Nothing in this License shall be construed as excluding or limiting
any implied license or other defenses to infringement that may
otherwise be available to you under applicable patent law.

  12. No Surrender of Others' Freedom.

  If conditions are imposed on you (whether by court order, agreement or
otherwise) that contradict the conditions of this License, they do not
excuse you from the conditions of this License.  If you cannot convey a
covered work so as to satisfy simultaneously your obligations under this
License and any other pertinent obligations, then as a consequence you may
not convey it at all.  For example, if you agree to terms that obligate you
to collect a royalty for further conveying from those to whom you convey
the Program, the only way you could satisfy both those terms and this
License would be to refrain entirely from conveying the Program.

  13. Remote Network Interaction; Use with the GNU General Public License.

  Notwithstanding any other provision of this License, if you modify the
Program, your modified version must prominently offer all users
interacting with it remotely through a computer network (if your version
supports such interaction) an opportunity to receive the Corresponding
Source of your version by providing access to the Corresponding Source
from a network server at no charge, through some standard or customary
means of facilitating copying of software.  This Corresponding Source
shall include the Corresponding Source for any work covered by version 3
of the GNU General Public License that is incorporated pursuant to the
following paragraph.

  Notwithstanding any other provision of this License, you have
permission to link or combine any covered work with a work licensed
under version 3 of the GNU General Public License into a single
combined work, and to convey the resulting work.  The terms of this
License will continue to apply to the part which is the covered work,
but the work with which it is combined will remain governed by version
3 of the GNU General Public License.

  14. Revised Versions of this License.

  The Free Software Foundation may publish revised and/or new versions of
the GNU Affero General Public License from time to time.  Such new versions
will be similar in spirit to the present version, but may differ in detail to
address new problems or concerns.

  Each version is given a distinguishing version number.  If the
Program specifies that a certain numbered version of the GNU Affero General
Public License "or any later version" applies to it, you have the
option of following the terms and conditions either of that numbered
version or of any later version published by the Free Software
Foundation.  If the Program does not specify a version number of the
GNU Affero General Public License, you may choose any version ever published
by the Free Software Foundation.

  If the Program specifies that a proxy can decide which future
versions of the GNU Affero General Public License can be used, that proxy's
public statement of acceptance of a version permanently authorizes you
to choose that version for the Program.

  Later license versions may give you additional or different
permissions.  However, no additional obligations are imposed on any
author or copyright holder as a result of your choosing to follow a
later version.

  15. Disclaimer of Warranty.

  THERE IS NO WARRANTY FOR THE PROGRAM, TO THE EXTENT PERMITTED BY
APPLICABLE LAW.  EXCEPT WHEN OTHERWISE STATED IN WRITING THE COPYRIGHT
HOLDERS AND/OR OTHER PARTIES PROVIDE THE PROGRAM "AS IS" WITHOUT WARRANTY
OF ANY KIND, EITHER EXPRESSED OR IMPLIED, INCLUDING, BUT NOT LIMITED TO,
THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR
PURPOSE.  THE ENTIRE RISK AS TO THE QUALITY AND PERFORMANCE OF THE PROGRAM
IS WITH YOU.  SHOULD THE PROGRAM PROVE DEFECTIVE, YOU ASSUME THE COST OF
ALL NECESSARY SERVICING, REPAIR OR CORRECTION.

  16. Limitation of Liability.

  IN NO EVENT UNLESS REQUIRED BY APPLICABLE LAW OR AGREED TO IN WRITING
WILL ANY COPYRIGHT HOLDER, OR ANY OTHER PARTY WHO MODIFIES AND/OR CONVEYS
THE PROGRAM AS PERMITTED ABOVE, BE LIABLE TO YOU FOR DAMAGES, INCLUDING ANY
GENERAL, SPECIAL, INCIDENTAL OR CONSEQUENTIAL DAMAGES ARISING OUT OF THE
USE OR INABILITY TO USE THE PROGRAM (INCLUDING BUT NOT LIMITED TO LOSS OF
DATA OR DATA BEING RENDERED INACCURATE OR LOSSES SUSTAINED BY YOU OR THIRD
PARTIES OR A FAILURE OF THE PROGRAM TO OPERATE WITH ANY OTHER PROGRAMS),
EVEN IF SUCH HOLDER OR OTHER PARTY HAS BEEN ADVISED OF THE POSSIBILITY OF
SUCH DAMAGES.

  17. Interpretation of Sections 15 and 16.

  If the disclaimer of warranty and limitation of liability provided
above cannot be given local legal effect according to their terms,
reviewing courts shall apply local law that most closely approximates
an absolute waiver of all civil liability in connection with the
Program, unless a warranty or assumption of liability accompanies a
copy of the Program in return for a fee.

                     END OF TERMS AND CONDITIONS

            How to Apply These Terms to Your New Programs

  If you develop a new program, and you want it to be of the greatest
possible use to the public, the best way to achieve this is to make it
free software which everyone can redistribute and change under these terms.

  To do so, attach the following notices to the program.  It is safest
to attach them to the start of each source file to most effectively
state the exclusion of warranty; and each file should have at least
the "copyright" line and a pointer to where the full notice is found.

    <one line to give the program's name and a brief idea of what it does.>
    Copyright (C) <year>  <name of author>

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.

    You should have received a copy of the GNU Affero General Public License
    along with this program.  If not, see <https://www.gnu.org/licenses/>.

Also add information on how to contact you by electronic and paper mail.

  If your software can interact with users remotely through a computer
network, you should also make sure that it provides a way for users to
get its source.  For example, if your program is a web application, its
interface could display a "Source" link that leads users to an archive
of the code.  There are many ways you could offer source, and different
solutions will be better for different programs; see section 13 for the
specific requirements.

  You should also get your employer (if you work as a programmer) or school,
if any, to sign a "copyright disclaimer" for the program, if necessary.
For more information on this, and how to apply and follow the GNU AGPL, see
<https://www.gnu.org/licenses/>.
*/

?>
