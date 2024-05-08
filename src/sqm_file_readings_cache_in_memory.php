<?php
/*	sqm_file_readings_cache_in_memory.php
	SQM Data Retriever
	(c) 2024 Darren Creutz
	Licensed under the GNU AFFERO GENERAL PUBLIC LICENSE */
	
/*	SQM_File_Readings_Cache implementation stored purely in memory */
require_once('sqm_file_readings_cache.php');

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
?>
