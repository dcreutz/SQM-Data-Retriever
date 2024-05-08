<?php
/*	sqm_file_parser.php
	SQM Data Retriever
	(c) 2024 Darren Creutz
	Licensed under the GNU AFFERO GENERAL PUBLIC LICENSE */
	
/*	Class representing an actual data file

	attempts to parse data files as csv with various delimiters
	parses data files in the standard darksky format
	parses data files in the format output by the SunMoonClouds functions in the unihedron software
	attempts parse other data files by guessing from the header row */
require_once('sqm_info.php');
require_once('sqm_file_manager.php');

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
	
	private $comment_lines;
	private $header_row;
	private $first_row;
	
	// read through the first data entry in the file to determine the indices of msas and datetime
	public function __construct($file_path) {
		$this->file_path = $file_path;
		$this->comment_lines = array();
		$this->readings = array();
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
					foreach ($this->header_row as $index => $part) {
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
	
	private function next_reading_datetime($line) {
		$parts = str_getcsv($line,$this->delimiter);
		if (count($parts) >= $this->max_index) {
			$this->readings[$parts[$this->indexes['datetime']]] = $parts[$this->indexes['reading']];
		}
	}
	
	private function next_reading_date_and_time($line) {
		$parts = str_getcsv($line,$this->delimiter);
		if (count($parts) >= $this->max_index) {
			$this->readings[
				$parts[$this->indexes['date']] . ' ' . $parts[$this->indexes['time']]
			] = $parts[$this->indexes['reading']];
		}
	}

	public function is_valid() {
		return count($this->readings) > 0;
	}

	// actually return the readings from the file as an array of values keyed by datetime strings
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
	
	// return just the first reading
	public function first_reading_from() {
		if (count($this->readings) > 0) {
			$datetime = array_keys($this->readings)[0];
			return array('datetime'=>$datetime,'value'=>$this->readings[$datetime]);
		}
		return false;
	}
	
	// get the last reading from a file without reading the entire file
	public function last_reading_from() {
		$position = ftell($this->file_handle);
		// assumes file ends in newline character
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

	// extract an SQM_Info object from the file if possible
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
}
?>
