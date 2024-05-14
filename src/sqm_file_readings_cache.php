<?php
/*	sqm_file_readings_cache.php
	SQM Data Retriever
	(c) 2024 Darren Creutz
	Licensed under the GNU AFFERO GENERAL PUBLIC LICENSE v3 */
	
/*	Keeps track of which readings per file have already been seen
	
	this can mean either readings read during this execution or readings stored in the cache */
abstract class SQM_File_Readings_Cache {
	// set the readings seen in $file
	public abstract function set_readings($file,$readings);
	
	// return a pair of arrays, datetimes and values, with shared keys
	public function get_readings($file) {
		return array();
	}
	
	// called when new files have appeared
	public function new_files($new_files) {}
	
	// called when files have been removed
	public function deleted_files($deleted_files) {}
	
	// the timestamp in unix epoch of the last time the file was read
	public abstract function set_file_load_time($file,$timestamp);
	
	// return the last time a file was read, default to 0 (never)
	public function get_file_load_time($file) {
		return 0;
	}
}

// factory pattern allowing injection based on cacheing
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
?>
