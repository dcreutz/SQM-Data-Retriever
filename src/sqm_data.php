<?php
/*	sqm_data.php
	SQM Data Retriever
	(c) 2024 Darren Creutz
	Licensed under the GNU AFFERO GENERAL PUBLIC LICENSE */
	
/*	Represents the actual data, including msas values and other attributes */

abstract class SQM_Data {
	//	returns an array of strings formatted YYYY-mm-dd listing all dates data exists for
	public abstract function dates();
	
	/*	returns an array keyed by 'datetimes' and 'values'
		each of which points to an array which are paired by key
		
		$date is a string formatted YYYY-mm-dd
		$with_attributes specifies whether attributes should be included
		(they may be included even if false) */
	public abstract function datetimes_and_values_by_date($date,$with_attributes = true);
	
	//	implemented by subclasses to actually store the data somewhere
	protected abstract function set_datetimes_and_values_by_date($date,$datetimes_and_values);
	
	//	set the additional attributes of the data
	public abstract function set_attributes_by_date($date,$attributes);
	
	/*	add the SQM_Data object $sqm_data to this SQM_Data object
	
		$sqm_data is guaranteed to be genuinely new data to this object */
	public function add($sqm_data) {
		foreach (array_intersect($this->dates(),$sqm_data->dates()) as $date) {
			$current_data = $this->datetimes_and_values_by_date($date);
			$new_data = $sqm_data->datetimes_and_values_by_date($date);
			foreach ($new_data['datetimes'] as $id => $datetime) {
				array_push($current_data['datetimes'],$datetime);
				array_push($current_data['values'],$new_data['values'][$id]);
			}
			$this->set_datetimes_and_values_by_date($date,$current_data);
		}
		foreach (array_diff($sqm_data->dates(),$this->dates()) as $date) {
			$this->set_datetimes_and_values_by_date($date,
				$sqm_data->datetimes_and_values_by_date($date)
			);
		}
	}
	
	/*	remove the SQM_Data object $sqm_data from this SQM_Data object
	
		$sqm_data is guaranteed to be data genuinely in this object */
	public function remove($sqm_data) {
		foreach ($sqm_data->dates() as $date) {
			$current_data = $this->datetimes_and_values_by_date($date);
			$data_keys = array_keys($sqm_data->datetimes_and_values_by_date($date)['datetimes']);
			foreach ($sqm_data->datetimes_and_values_by_date($date)['datetimes'] as $datetime) {
				$key = array_search($datetime,$current_data['datetimes']);
				if ($key) {
					unset($current_data['datetimes'][$key]);
					unset($current_data['values'][$key]);
				}
			}
			$current_data['datetimes'] = array_values($current_data['datetimes']);
			$current_data['values'] = array_values($current_data['values']);
			$this->set_datetimes_and_values_by_date($date,$current_data);
		}
	}
	
	//	return datetimes_and_values_by_date when there are no readings
	protected function empty() {
		return array('datetimes'=>array(),'values'=>array(),'attributes'=>array());
	}
	
	//	sort everything by datetime and repair the keys
	public static function sort_datetimes_and_values($datetimes_and_values) {
		$new_datetimes = array();
		$new_values = array();
		$new_attributes = array();
		asort($datetimes_and_values['datetimes']);
		foreach ($datetimes_and_values['datetimes'] as $key => $datetime) {
			array_push($new_datetimes,$datetime);
			array_push($new_values,$datetimes_and_values['values'][$key]);
			// if adding to existing, new records don't yet have attributes attached
			if (isset($datetimes_and_values['attributes'][$key])) {
				array_push($new_attributes,$datetimes_and_values['attributes'][$key]);
			}
		}
		return array(
			'datetimes'=>$new_datetimes,
			'values'=>$new_values,
			'attributes'=>$new_attributes
		);
	}
}

/*	Represents the best nightly readings data
	subclasses store the actual data somewhere */
abstract class SQM_Best_Nightly_Data {
	/*	return ('datetimes'=>array,'values'=>array,'attributes'=>array) for all data
		the three arrays have shared keys being the date formatted as a YYYY-mm-dd string */
	public abstract function get_datetimes_and_values();
	
	/*	set the best nightly readings for this object
		this replaces any existing data
		
		the three arrays are keyed by dates formatted as YYYY-mm-dd */
	public abstract function set_best_nightly_readings($datetimes,$values,$attributes);
}

//	factory pattern to allow for cacheing
abstract class SQM_Data_Factory {
	protected static $instance;
	
	public static function exists() {
		return SQM_Data_Factory::$instance != null;
	}
	
	public static function create_sqm_data($sqm_id) {
		return SQM_Data_Factory::$instance->build_sqm_data($sqm_id);
	}
	
	public static function create_best_nightly_data($sqm_id) {
		return SQM_Data_Factory::$instance->build_best_nightly_data($sqm_id);
	}
	
	protected abstract function build_sqm_data($sqm_id);
	protected abstract function build_best_nightly_data($sqm_id);
}

require_once('sqm_data_in_memory.php');
?>
