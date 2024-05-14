<?php
/*	sqm_cache.php
	SQM Data Retriever
	(c) 2024 Darren Creutz
	Licensed under the GNU AFFERO GENERAL PUBLIC LICENSE v3 */
	
/*	Manages the actual cache files on disk

	each sqm dataset has its own cache directory, named the same as its data directory
	an instance of SQM_Cache is created for each dataset to manage its cache */
require_once('sqm_directory.php');

class SQM_Cache extends SQM_Directory {	
	public function __construct($directory) {
		parent::__construct($directory);
		$this->initialize();
	}
	
	protected function initialize() {
		if (!$this->file_exists()) {
			$this->mkdir();
		}
	}
	
	public function load_from($file) {
		if ($this->file_exists($file)) {
			return unserialize(file_get_contents($this->file_path($file)));
		} else {
			return null;
		}
	}
	
	public function save_to($file,$data) {
		file_put_contents($this->file_path($file),serialize($data));
	}
	
	public function remove($file) {
		if ($this->file_exists($file)) {
			unlink($this->file_path($file));
		}
	}
}

/*	Implementation of SQM_Cache that never modifies the cached data */
class SQM_Cache_Read_Only extends SQM_Cache {
	public function __construct($directory) {
		parent::__construct($directory);
	}
	
	protected function initialize() {
	}
	
	public function save_to($file,$data) {
	}
	
	public function remove($file) {
	}
}

/*	Creates SQM_Cache objects

	to prevent cache writes by simultaneously running instances, require that a process
	wishing to write to the cache must obtain a file lock
	
	if a process does not obtain the lock, meaning some other process has it, the process
	without the lock is given a read only cache, unless should_block is set to true */
class SQM_Cache_Factory {
	protected static $instance;
	private $cache_directory;
	private $file_handle;
	private $has_lock;
	
	public static function initialize($cache_directory,$should_block) {
		SQM_Cache_Factory::$instance = new SQM_Cache_Factory($cache_directory,$should_block);
	}
	
	// returns true if this process did not obtain write access to the cache
	public static function is_read_only() {
		return !SQM_Cache_Factory::$instance->has_lock;
	}
	
	private function __construct($cache_directory,$should_block) {
		$this->has_lock = false;
		$this->cache_directory = $cache_directory;
		$this->file_handle =
			fopen($this->cache_directory . DIRECTORY_SEPARATOR . ".sqm_cache_lock_file","w");
		if ($should_block) {
			if (flock($this->file_handle,LOCK_EX)) {
				$this->has_lock = true;
			} else {
				fclose($this->file_handle);
			}
		} else {
			if (flock($this->file_handle,LOCK_EX|LOCK_NB)) {
				$this->has_lock = true;
			} else {
				fclose($this->file_handle);
			}
		}
	}
	
	public function __destruct() {
		if ($this->has_lock) {
			fclose($this->file_handle);
		}
	}
	
	public static function create($sqm_id) {
		return SQM_Cache_Factory::$instance->build($sqm_id);
	}
	
	protected function build($sqm_id) {
		global $read_only_mode;
		if ($this->has_lock && !$read_only_mode) {
			return new SQM_Cache($this->cache_directory . DIRECTORY_SEPARATOR . $sqm_id);
		} else {
			return new SQM_Cache_Read_Only($this->cache_directory . DIRECTORY_SEPARATOR . $sqm_id);
		}
	}
	
	/*	clears the cache completely
		only takes effect if the process has the cache lock */
	public static function clear_cache() {
		if (SQM_Cache_Factory::$instance) {
			SQM_Cache_Factory::$instance->clear();
		}
	}
	
	private function clear() {
		if ($this->has_lock && file_exists($this->cache_directory)) {
			$di = new RecursiveDirectoryIterator(
				$this->cache_directory, FilesystemIterator::SKIP_DOTS
			);
			$ri = new RecursiveIteratorIterator($di, RecursiveIteratorIterator::CHILD_FIRST);
			foreach ( $ri as $file ) {
				$file->isDir() ?  rmdir($file) : unlink($file);
			}
		} else {
			if ($this->has_lock) {
				sqm_error_log("Could not clear cache as directory does not exist");
			}
			if (!$this->has_lock) {
				sqm_error_log("Could not clear cache due to not having the cache lock");
			}
		}
	}
}
?>
