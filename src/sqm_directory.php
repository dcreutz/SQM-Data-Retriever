<?php
/*	sqm_directory.php
	SQM Data Retriever
	(c) 2024 Darren Creutz
	Licensed under the GNU AFFERO GENERAL PUBLIC LICENSE v3 */
	
/*	Represents a directory in the filesystem containing sqm data files or cache files

	abstracts away file path management so that if the cache folder is moved (and config.php
	modified accordingly), nothing breaks */
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
?>
