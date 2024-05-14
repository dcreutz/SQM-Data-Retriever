<?php
/*	sqm_fileset_trusting.php
	SQM Data Retriever
	(c) 2024 Darren Creutz
	Licensed under the GNU AFFERO GENERAL PUBLIC LICENSE v3 */
	
/*	Implements SQM_Fileset trusting that files are named including dates or months
	and that files are in chronological order
	
	intended only to be used when cacheing is not available, see config.php for more information */
require_once('sqm_fileset.php');
require_once('sqm_fileset_distrusting.php');

class SQM_Fileset_Trusting extends SQM_Fileset_Implementation {
	private $files_by_month;
	
	public function __construct($sqm_id,$file_readings_cache,$file_manager) {
		parent::__construct($sqm_id,$file_readings_cache,$file_manager);
		sort($this->file_list);
		// attempt to determine which files correspond to which months
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
	
	// scan filenames looking for date or month strings in various formats
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

	// use the file which should be the most recent
	protected function sqm_info_files() {
		return array_reverse($this->file_list);
	}
	
	// return the files we think correspond to the requested range
	public function files_for($start_date,$end_date) {
		return array_merge(...array_map(function ($month) {
			return isset($this->files_by_month[$month]) ? $this->files_by_month[$month] : [];
		},SQM_Date_Utils::months_in_range($start_date,$end_date)));
	}
	
	// return the last file in the list which should be chronologically newest
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
	
	// attempt to get the earliest and latest readings
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
			// if our attempt to match files to months failed then the files are not named
			// correctly so we have no choice but to fallback to distrusting the files
			return new SQM_Fileset_Distrusting($sqm_id,$file_readings_cache,$file_manager);
		}
	}
}
?>
