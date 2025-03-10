<?php
/*	sqm_file_manager.php
	SQM Data Retriever
	(c) 2024 Darren Creutz
	Licensed under the GNU AFFERO GENERAL PUBLIC LICENSE v3 */
	
/*	Handles the actual interaction with the files on disk */
require_once('sqm_directory.php');
require_once('sqm_file_parser.php');

class SQM_File_Manager extends SQM_Directory {
	private $file_parsers;

	public function __construct($directory) {
		parent::__construct($directory);
		$this->file_parsers = array();
	}
	
	// return an array of SQM_File_Parsers for each valid data file in the directory
	public function get_file_list() {
		foreach (SQM_File_Manager::recursive_scandir($this->file_path()) as $file) {
			$parser = new SQM_File_Parser($this->file_path($file));
			if ($parser->is_valid()) {
				$this->file_parsers[$file] = $parser;
			}
		}
		return array_keys($this->file_parsers);
	}
	
	// returns an array of all files in a given directory including those in subdirectories
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
	
	/*	if a file named .info is in the directory for a given dataset
		and contains name and position information then use it rather than parse data files */
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

// factory pattern here so we can determine which sqm stations are available
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
	
	// look for subdirectories of the data directory
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
?>
