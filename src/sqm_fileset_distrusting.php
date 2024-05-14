<?php
/*	sqm_fileset_distrusting.php
	SQM Data Retriever
	(c) 2024 Darren Creutz
	Licensed under the GNU AFFERO GENERAL PUBLIC LICENSE v3 */
	
/*	SQM_Fileset class which does not make assumptions about filenames or chronological order

	intended for use with cacheing since it can be somewhat slow
	reads every file looking for new readings regardless of what the request was */
require_once('sqm_fileset.php');

class SQM_Fileset_Distrusting extends SQM_Fileset_Implementation {
	public function __construct($sqm_id,$file_readings_cache,$file_manager) {
		parent::__construct($sqm_id,$file_readings_cache,$file_manager);
	}

	// use the newest file in terms of modification time
	protected function sqm_info_files() {
		usort($this->file_list,function ($file_a,$file_b) {
			return 
				$this->file_manager->filemtime($file_a) > $this->file_manager->filemtime($file_b)
				? 1 : -1;
		});
		return $this->file_list;
	}
	
	// do not guess as we are going to read all the data anyway
	public function earliest_and_latest_readings() {
		return null;
	}
	
	// always look at all files
	public function files_for($start_date,$end_date) {
		return $this->file_list;
	}
	
	public function files_for_latest() {
		return $this->file_list;
	}
	
	public function files_for_earliest() {
		return $this->file_list;
	}
	
	// sort the readings by datetime
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
?>
