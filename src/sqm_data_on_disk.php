<?php
/*	sqm_data_on_disk.php
	SQM Data Retriever
	(c) 2024 Darren Creutz
	Licensed under the GNU AFFERO GENERAL PUBLIC LICENSE */
	
/*	Implementation of SQM_Data that stores data in the cache

	stores the data as a serialized triple of arrays (datetimes,values,attributes)
	each date has its own cache file */
require_once('sqm_data.php');
require_once('sqm_cache.php');

class SQM_Data_On_Disk extends SQM_Data {
	private $cache;
	
	public function __construct($cache) {
		$this->cache = $cache;
	}
	
	public function dates() {
		return $this->cache->scandir();
	}
	
	public function datetimes_and_values_by_date($date,$with_attributes = true) {
		$datetimes_and_values = $this->cache->load_from($date);
		if ($datetimes_and_values) {
			return $datetimes_and_values;
		} else {
			return array('datetimes'=>array(),'values'=>array(),'attributes'=>array());
		}
	}
	
	protected function set_datetimes_and_values_by_date($date,$datetimes_and_values) {
		if (count($datetimes_and_values['datetimes']) > 0) {
			$this->cache->save_to($date,SQM_Data::sort_datetimes_and_values($datetimes_and_values));
		} else {
			$this->cache->remove($date);
		}
	}
	
	public function set_attributes_by_date($date,$attributes) {
		$datetimes_and_values = $this->cache->load_from($date);
		if ($datetimes_and_values) {
			$datetimes_and_values['attributes'] = $attributes;
			$this->cache->save_to($date,SQM_Data::sort_datetimes_and_values($datetimes_and_values));
		}
	}
}

/*	Stores best nightly readings in a cache file */
class SQM_Best_Nightly_Data_On_Disk extends SQM_Best_Nightly_Data {
	private $cache;
	
	public function __construct($cache) {
		$this->cache = $cache;
	}
	
	public function get_datetimes_and_values() {
		$cached = $this->cache->load_from("best");
		return $cached ? $cached :
						 array('datetimes'=>array(),'values'=>array(),'attributes'=>array());
	}
	
	public function set_best_nightly_readings($datetimes,$values,$attributes) {
		$datetimes_and_values = $this->get_datetimes_and_values();
		foreach ($datetimes as $date => $datetime) {
			$datetimes_and_values['datetimes'][$date] = $datetime;
			$datetimes_and_values['values'][$date] = $values[$date];
			$datetimes_and_values['attributes'][$date] = $attributes[$date];
		}
		// sort by the date strings which are the array keys
		ksort($datetimes_and_values['datetimes']);
		ksort($datetimes_and_values['values']);
		ksort($datetimes_and_values['attributes']);
		$this->cache->save_to("best",$datetimes_and_values);
	}
}

class SQM_Data_On_Disk_Factory extends SQM_Data_Factory {
	public static function initialize() {
		SQM_Data_Factory::$instance = new SQM_Data_On_Disk_Factory();
	}

	protected function build_sqm_data($sqm_id) {
		return new SQM_Data_On_Disk(SQM_Cache_Factory::create($sqm_id . "_all"));
	}
	
	protected function build_best_nightly_data($sqm_id) {
		return new SQM_Best_Nightly_Data_On_Disk(SQM_Cache_Factory::create($sqm_id . "_best"));
	}
}
?>
