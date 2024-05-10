<?php
/*	initialize_sqm_responder.php
	SQM Data Retriever
	(c) 2024 Darren Creutz
	Licensed under the GNU AFFERO GENERAL PUBLIC LICENSE */
	
/*	This script creates the actual SQM_Responder object

	based on the config.php options, it constructs the appropriate factories
	then creates an SQM_Responder which calls the factory methods */

$path = is_dir($data_directory) ? "" : getcwd() . DIRECTORY_SEPARATOR;

require_once('sqm_file_manager.php');
SQM_File_Manager_Factory::initialize($path . $data_directory);

require_once('sqm_data_attributes.php');
SQM_Data_Attributes::initialize();

require_once('sqm_fileset.php');
require_once('sqm_data.php');

require_once('../contrib/suncalc/suncalc.php');

if ($add_sun_moon_info === true) {
	SQM_Data_Attributes::include_module(SQM_Data_Attributes_Sun_Moon_Module::class);
}

if ($perform_regression_analysis === true) {
	SQM_Data_Attributes::include_module(SQM_Data_Attributes_Regression_Analysis_Module::class);
}

if ($add_raw_data === true) {
	SQM_Data_Attributes::include_module(SQM_Data_Attributes_From_Data_Files::class);
}

$cacheing_enabled = false;
if (isset($cache_directory) && $cache_directory) {
	$cache_directory_path = $path . $cache_directory;
	if ((is_dir($cache_directory_path)) && (is_writeable($cache_directory_path))) {
		$cacheing_enabled = true;
		require_once('sqm_cache.php');
		SQM_Cache_Factory::initialize($cache_directory_path,$should_block_for_cacheing);
		if (SQM_Cache_Factory::is_read_only()) {
			$read_only_mode = true;
		}
		require_once('sqm_data_on_disk.php');
		SQM_Data_On_Disk_Factory::initialize();
		require_once('sqm_file_readings_cache_on_disk.php');
		SQM_File_Readings_Cache_On_Disk_Factory::initialize();
		if ($add_sun_moon_info == 'only if cacheing') {
			$add_sun_moon_info = true;
			SQM_Data_Attributes::include_module(SQM_Data_Attributes_Sun_Moon_Module::class);
		}
		if ($perform_regression_analysis === 'only if cacheing') {
			$perform_regression_analysis = true;
			SQM_Data_Attributes::include_module(
				SQM_Data_Attributes_Regression_Analysis_Module::class
			);
		}
		if ($add_raw_data === 'only if cacheing') {
			$add_raw_data = true;
			SQM_Data_Attributes::include_module(
				SQM_Data_Attributes_From_Data_Files::class
			);
		}
	} else {
		sqm_error_log("SQM Cache directory is not a directory or is not writeable");
	}
}

if ($cacheing_enabled) {
	if (isset($sqm_memory_limit_if_cacheing)) {
		if (ini_set('memory_limit',$sqm_memory_limit_if_cacheing) === false) {
			sqm_error_log("Could not set memory limit");
		}
	}
} else {
	if (isset($sqm_memory_limit_if_not_cacheing)) {
		if (ini_set('memory_limit',$sqm_memory_limit_if_not_cacheing) === false) {
			sqm_error_log("Could not set memory limit");
		}
	}
}

if (($use_images === true) || (($use_images === 'only if cacheing') && $cacheing_enabled)) {
	if (isset($image_directory) && $image_directory) {
		require_once('sqm_data_attributes_images.php');
		$image_directory_path = $path . $image_directory;
		if (is_dir($image_directory_path)) {
			SQM_Data_Attributes_Images_Module::initialize(
				$image_directory_path,$image_directory_url
			);
			SQM_Data_Attributes::include_module(SQM_Data_Attributes_Images_Module::class);
		} else {
			sqm_error_log("Image directory path is not a directory");
		}
		if (isset($resized_directory) && $resized_directory) {
			$resized_directory_path = $path . $resized_directory;
			if (is_dir($resized_directory_path)) {
				if ($resize_images && is_writeable($resized_directory_path)
						&& extension_loaded('gd')) {
					SQM_Data_Attributes_Resize_Images_Module::initialize(
						$image_directory_path,$resized_directory_path,$resized_directory_url,true
					);
				} else {
					SQM_Data_Attributes_Resize_Images_Module::initialize(
						$image_directory_path,$resized_directory_path,$resized_directory_url,false
					);
					if ($resize_images && !is_writeable($resized_directory_path)) {
						sqm_error_log("Resized directory is not writeable");
					}
					if ($resize_images && !extension_loaded('gd')) {
						sqm_error_log("GD extension not found, cannot resize images");
					}
				}
				SQM_Data_Attributes::include_module(
					SQM_Data_Attributes_Resize_Images_Module::class
				);
			} else {
				sqm_error_log("Resized image directory path is not a directory");
			}
		}
	}
}

if (($add_sun_moon_info===true) && ($perform_regression_analysis===true)) {
	SQM_Data_Attributes::include_module(SQM_Data_Attributes_Sun_Moon_Clouds_Module::class);
}

// load the distrusting version regardless since it is used as a fallback when trusting
require_once('sqm_fileset_distrusting.php');
if (($trust_files === true) || (($trust_files == 'only if not cacheing') && !$cacheing_enabled)) {
	require_once('sqm_fileset_trusting.php');
	SQM_Fileset_Trusting_Factory::initialize();
} else {
	SQM_Fileset_Distrusting_Factory::initialize();
}

// if we have no access to the cache (and cacheing is enabled), never look at the files
if ($cacheing_enabled && $read_only_mode) {
	require_once('sqm_fileset_no_new.php');
	SQM_Fileset_No_New_Factory::initialize();
}

require_once('sqm_dataset_manager_factory.php');

if (!SQM_Data_Factory::exists()) {
	require_once('sqm_data_in_memory.php');
	SQM_Data_In_Memory_Factory::initialize();
}

if (!SQM_File_Readings_Cache_Factory::exists()) {
	require_once('sqm_file_readings_cache_in_memory.php');
	SQM_File_Readings_Cache_In_Memory_Factory::initialize();
}

require_once('sqm_responder.php');

$sqm_responder = new SQM_Responder();
?>
