<?php
/*	sqm_dataset_manager_factory.php
	SQM Data Retriever
	(c) 2024 Darren Creutz
	Licensed under the GNU AFFERO GENERAL PUBLIC LICENSE v3 */
	
/*	Manages the interactions between the various factories required to construct an
	SQM_Dataset_Manager object */
require_once('sqm_dataset_manager.php');
require_once('sqm_dataset.php');
require_once('sqm_data.php');
require_once('sqm_data_attributes.php');
require_once('sqm_file_readings_cache.php');
require_once('sqm_fileset.php');
require_once('sqm_file_manager.php');

class SQM_Dataset_Manager_Factory {
	public static function create($sqm_id) {
		try {
			return new SQM_Dataset_Manager_Implementation(
				new SQM_Dataset_Implementation(
					SQM_Data_Factory::create_sqm_data($sqm_id),
					SQM_Data_Factory::create_best_nightly_data($sqm_id),
					new SQM_Data_Attributes()
				),
				SQM_Fileset_Factory::create($sqm_id,
					SQM_File_Readings_Cache_Factory::create($sqm_id),
					SQM_File_Manager_Factory::create($sqm_id)
				)
			);
		} catch (Exception $e) {
			sqm_error_log("Failed dataset manager for " . $sqm_id . " " . $e->getMessage());
		}
		return null;
	}
}
?>
