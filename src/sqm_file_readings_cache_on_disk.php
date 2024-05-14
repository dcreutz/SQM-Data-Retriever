<?php
/*	sqm_file_readings_cache_on_disk.php
	SQM Data Retriever
	(c) 2024 Darren Creutz
	Licensed under the GNU AFFERO GENERAL PUBLIC LICENSE v3 */
	
/*	SQM_File_Readings_Cache implementation using the cache on disk */
require_once('sqm_file_readings_cache.php');
require_once('sqm_cache.php');

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
		// trust the actual mtime over when this is called so do nothing
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
?>
