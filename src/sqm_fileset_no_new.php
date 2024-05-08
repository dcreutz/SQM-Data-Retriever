<?php
/*	sqm_fileset_no_new.php
	SQM Data Retriever
	(c) 2024 Darren Creutz
	Licensed under the GNU AFFERO GENERAL PUBLIC LICENSE */
	
/*	SQM_Fileset object that never returns new readings

	intended for use with cacheing when another instance of the responder is already cacheing
	this class does not actually read any files, it is meant to represent that the dataset should
	be asked all the questions */
require_once('sqm_fileset.php');
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
?>
