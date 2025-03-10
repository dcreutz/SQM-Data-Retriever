<?php
/*	sqm_data_attributes_from_data_files.php
	SQM Data Retriever
	(c) 2024 Darren Creutz
	Licensed under the GNU AFFERO GENERAL PUBLIC LICENSE v3 */
	
/*	SQM_Data_Attributes_Module for including the raw data from the csv files */
class SQM_Data_Attributes_From_Data_Files extends SQM_Data_Attributes_Module {
	public static function add_attributes_from(
		&$attributes,$datetimes,$values,$sunset,$sunrise,$sqm_sun_moon_info,$fileset,$sqm_id
	) {
		foreach ($datetimes as $key => $datetime) {
			$data_columns = $fileset->data_columns_for($datetime);
			if ($data_columns) {
				$attributes[$key]['raw'] = $data_columns;
			} else {
				$attributes[$key]['raw'] = null;
			}
		}
	}
	
	public static function add_best_nightly_attributes(
		&$attributes,$date,$key,$datetime,$datetimes,$values,$night_attributes,
		$sqm_sun_moon_info,$fileset,$datetime_keys_at_night
	) {
		$attributes['raw'] = $night_attributes[$key]['raw'];
	}
}
?>