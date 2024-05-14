<?php
/*	load_config.php
	SQM Data Retriever
	(c) 2024 Darren Creutz
	Licensed under the GNU AFFERO GENERAL PUBLIC LICENSE */
	
/*	Wrapper script for loading config.php

	this script ensures that all config options are set to sensible defaults
	if they are not set in the config.php file */

include('..' . DIRECTORY_SEPARATOR . 'config.php');
if (!isset($data_directory)) {
	$data_directory = "data";
}
if (!isset($read_only_mode)) {
	$read_only_mode = false;
}
if (!isset($trust_files)) {
	$trust_files = 'only if not cacheing';
}
if (!isset($default_twilight_type)) {
	$default_twilight_type = 'nautical';
}
if (!isset($extended_time)) {
	$extended_time = true;
}
if (!isset($add_raw_data)) {
	$add_raw_data = 'only if cacheing';
}
if (!isset($add_sun_moon_info)) {
	$add_sun_moon_info = 'only if cacheing';
}
if (!isset($perform_regression_analysis)) {
	$perform_regression_analysis = 'only if cacheing';
}
if (!isset($regression_time_range)) {
	$regression_time_range = 90;
}
if (!isset($regression_averaging_time_range)) {
	$regression_averaging_time_range = 30;
}
if (!isset($regression_time_shift)) {
	$regression_time_shift = 60;
}
if (!isset($filter_mean_r_squared)) {
	$filter_mean_r_squared = 0.04;
}
if (!isset($filter_sun_elevation)) {
	$filter_sun_elevation = -12;
}
if (!isset($filter_moon_elevation)) {
	$filter_moon_elevation = -10;
}
if (!isset($filter_moon_illumination)) {
	$filter_moon_illumination = 0.1;
}
if (!isset($use_images)) {
	$use_images = 'only if cacheing';
}
if (!isset($image_name_format)) {
	$image_name_format = "YmdHis";
}
if (!isset($image_name_prefix_length)) {
	$image_name_prefix_length = 0;
}
if (!isset($image_name_suffix_length)) {
	$image_name_suffix_length = 4;
}
if (!isset($image_time_frame)) {
	$image_time_frame = 600;
}
if (!isset($resize_images)) {
	$resize_images = false;
}
if (!isset($resized_width)) {
	$resized_widths = $resized_widths = array('display_image'=>800,'thumbnail'=>200);;
}
if (!isset($clear_cache_on_errors)) {
	$clear_cache_on_errors = true;
}
if (!isset($debug)) {
	$debug = false;
}
if (!isset($logging_enabled)) {
	$logging_enabled = false;
}
?>
