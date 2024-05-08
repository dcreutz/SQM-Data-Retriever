<?php
/*	sqm_fileset.php
	SQM Data Retriever
	(c) 2024 Darren Creutz
	Licensed under the GNU AFFERO GENERAL PUBLIC LICENSE */
	
/*	Represents the actual collection of files for a given sqm dataset

	Various subclasses are designed to ensure responsiveness of the application regardless
	of whether cacheing is enabled */
require_once('sqm_file_manager.php');
require_once('sqm_file_readings_cache.php');
require_once('sqm_info.php');

interface SQM_Fileset {
	/*	returns an array of all files known to the fileset
		note that said files can only be accessed via this object and that the array entriies
			are not the actual paths to files on disk */
	public function all_files();
	
	/*	returns an array of files for data in the given date range
		$start_date and $end_date are stirngs in YYYY-mm-dd format */
	public function files_for($start_date,$end_date);
	
	/*	returns the earliest and latest readings taken
		this is allowed to return null if the fileset prefers the dataset to determine this
		the fileset is allowed to guess the answers in the noncacheing setup */
	public function earliest_and_latest_readings();
	
	/*	returns an array of files likely to include the latest/earliest reading
		may return all files or guess */
	public function files_for_latest();
	public function files_for_earliest();
	
	/*	returns readings from the array of files passed in which are new since the last access
		if cacheing is disabled, this is all readings in the files
		
		returns an array of ('add'=>readings,'remove'=>readings)
		where each readings is an array of values keyed by the datetime strings from the file */
	public function new_readings_from(...$files);
	
	/* returns an SQM_Info object for this fileset */
	public function sqm_info();
}

// factory pattern to allow injection of different subclasses depending on cacheing
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
	
	/*	$file_readings_cache is an SQM_File_Readings_Cache object
		$file_manager is an SQM_File_Manager object */
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
	
	// may be overridden
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
	
	// removal is allowed to be slow
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
	
	// may be overridden
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
}
?>
