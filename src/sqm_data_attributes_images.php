<?php
/*	sqm_data_attributes_images.php
	SQM Data Retriever
	(c) 2024 Darren Creutz
	Licensed under the GNU AFFERO GENERAL PUBLIC LICENSE v3 */
	
/*	SQM_Data_Attributes_Module for attaching images to the data

	determines which image file is closest in time to each reading and associates the path
	to the image file (relative URL) to the data by assigning the 'image' attribute
	
	see config.php for configuration options */
class SQM_Data_Attributes_Images_Module extends SQM_Data_Attributes_Module {
	private static $image_directory;
	private static $image_directory_url;
	private static $image_file_list;
	private static $image_file_datetimes;
	
	public static function initialize($image_directory,$image_directory_url) {
		SQM_Data_Attributes_Images_Module::$image_directory = $image_directory;
		SQM_Data_Attributes_Images_Module::$image_directory_url = $image_directory_url;
		SQM_Data_Attributes_Images_Module::$image_file_list = array();
		SQM_Data_Attributes_Images_Module::$image_file_datetimes = array();
		SQM_Data_Attributes_Images_Module::$old_time = 
			DateTimeImmutable::createFromFormat("U",0);
	}
	
	// build a list of all files in the image directory
	private static function build_file_list($sqm_id,$date,$timezone) {
		if (!isset(SQM_Data_Attributes_Images_Module::$image_file_datetimes[$sqm_id])) {
			SQM_Data_Attributes_Images_Module::$image_file_datetimes[$sqm_id] = array();
		}
		if (!isset(SQM_Data_Attributes_Images_Module::$image_file_datetimes[$sqm_id][$date])) {
			SQM_Data_Attributes_Images_Module::$image_file_datetimes[$sqm_id][$date] = array();
			$directory = SQM_Data_Attributes_Images_Module::$image_directory . 
				DIRECTORY_SEPARATOR . $sqm_id . DIRECTORY_SEPARATOR . 
				substr($date,0,7) . DIRECTORY_SEPARATOR . $date;
			if (file_exists($directory) && is_dir($directory)) {
				global $image_name_format;
				global $image_name_prefix_length;
				global $image_name_suffix_length;
				foreach (SQM_File_Manager::recursive_scandir($directory) as $file) {
					$file_without_prefix = substr($file,$image_name_prefix_length);
					$date_from_file = substr($file_without_prefix,0,
						strlen($file_without_prefix)-$image_name_suffix_length);
					$datetime = DateTimeImmutable::createFromFormat(
						$image_name_format,$date_from_file,$timezone
					);
					// if the config.php options are set correctly and files are named correctly
					// then $datetime should be a validly formatted $datetime
					// createFromFormat returns false if it cannot parse the string
					if ($datetime) {
						SQM_Data_Attributes_Images_Module::$image_file_datetimes[$sqm_id][$date]
							[$file] = $datetime;
					} else {
						sqm_error_log("Could not format datetime from " . $file);
					}
				}
			}
		}
	}
	
	private static $old_time;

	public static function add_attributes_from(
		&$attributes,$datetimes,$values,$sunset,$sunrise,$sqm_sun_moon_info,$fileset,$sqm_id
	) {
		$sunset_date = $sunset->format("Y-m-d");
		$sunrise_date = $sunrise->format("Y-m-d");
		SQM_Data_Attributes_Images_Module::build_file_list($sqm_id,$sunset_date,$sunset->getTimezone());
		SQM_Data_Attributes_Images_Module::build_file_list($sqm_id,$sunrise_date,$sunrise->getTimezone());
		foreach ($datetimes as $key => $datetime) {
			if (isset($attributes[$key]['image']) && $attributes[$key]['image']) {
				continue;
			}
			$closest_datetime = SQM_Data_Attributes_Images_Module::$old_time;
			$closest_file = null;
			$closest_date = $sunset_date;
			foreach (SQM_Data_Attributes_Images_Module::$image_file_datetimes[$sqm_id][$sunset_date] as 
					$file => $dt) {
				if (abs(intval($dt->format("U")) - intval($datetime->format("U"))) <
					abs(intval($closest_datetime->format("U")) - intval($datetime->format("U")))) {
						$closest_datetime = $dt;
						$closest_file = $file;
				}
			}
			foreach (SQM_Data_Attributes_Images_Module::$image_file_datetimes[$sqm_id][$sunrise_date] as 
					$file => $dt) {
				if (abs(intval($dt->format("U")) - intval($datetime->format("U"))) <
					abs(intval($closest_datetime->format("U")) - intval($datetime->format("U")))) {
						$closest_datetime = $dt;
						$closest_file = $file;
						$closest_date = $sunrise_date;
				}
			}
			$closest_datetime = $closest_datetime->setTimezone($datetime->getTimezone());
			// if we found a file we think is closest in time, make sure it is in the time range
			global $image_time_frame;
			if ($closest_file &&
				(abs(intval($closest_datetime->format("U")) - intval($datetime->format("U"))) <
					$image_time_frame)) {
				$attributes[$key]['image'] = 
					SQM_Data_Attributes_Images_Module::$image_directory_url
					. DIRECTORY_SEPARATOR . $sqm_id
					. DIRECTORY_SEPARATOR . substr($closest_date,0,7) . DIRECTORY_SEPARATOR .
					$closest_date . DIRECTORY_SEPARATOR . $closest_file;
			} else {
				$attributes[$key]['image'] = null;
			}
		}
	}
	
	public static function add_best_nightly_attributes(
		&$attributes,$date,$key,$datetime,$datetimes,$values,$night_attributes,
		$sqm_sun_moon_info,$fileset,$datetime_keys_at_night
	) {
		$attributes['image'] = $night_attributes[$key]['image'];
	}
}

/*	SQM_Data_Attributes_Module for resizing images and attaching them to the data

	each resized name specified in config.php will beused as an attribute name
	and the resulting resized image attached to it */
class SQM_Data_Attributes_Resize_Images_Module extends SQM_Data_Attributes_Module {
	private static $image_directory;
	private static $directory;
	private static $directory_url;
	private static $create;
	
	// $create is a boolean specifying whether images should be created or just searched for
	public static function initialize(
		$image_directory,$resized_directory,$resized_directory_url,$create
	) {
		SQM_Data_Attributes_Resize_Images_Module::$directory_url = $resized_directory_url;
		SQM_Data_Attributes_Resize_Images_Module::$image_directory = $image_directory;
		SQM_Data_Attributes_Resize_Images_Module::$directory = $resized_directory;
		SQM_Data_Attributes_Resize_Images_Module::$create = $create;
	}
	
	// extract the path from a filename
	private static function extract_path($file_path) {
		$parts = explode(DIRECTORY_SEPARATOR,$file_path);
		$path = "";
		for ($i=0;$i<count($parts)-1;$i++) {
			$path = $path . $parts[$i] . DIRECTORY_SEPARATOR;
		}
		return $path;
	}
	
	// actually resize an image and save it
	private static function resize($image_path,$resized_path,$new_width) {
		sqm_error_log("Resizing image " . $image_path,2);
		global $extended_time;
		if ($extended_time) {
			set_time_limit(300);
		}
		$path = SQM_Data_Attributes_Resize_Images_Module::extract_path($resized_path);
		if (!is_dir($path)) {
			mkdir($path,0777,true);
		}
		$source_image = imagecreatefromstring(file_get_contents($image_path));
		$width = imagesx($source_image);
		$height = imagesy($source_image);
		$new_height = floor($height * ($new_width / $width));
		$virtual_image = imagecreatetruecolor($new_width, $new_height);
		imagecopyresampled($virtual_image, $source_image, 0, 0, 0, 0,
			$new_width, $new_height, $width, $height
		);
		imagejpeg($virtual_image, $resized_path);
	}
	
	public static function add_attributes_from(
		&$attributes,$datetimes,$values,$sunset,$sunrise,$sqm_sun_moon_info,$fileset,$sqm_id
	) {
		global $resized_widths;
		$resized_name = array_key_first($resized_widths);
		foreach ($datetimes as $key => $datetime) {
			if (isset($attributes[$key][$resized_name]) && $attributes[$key][$resized_name]) {
				continue;
			}
			$image = $attributes[$key]['image'];
			if ($image) {
				$parts = explode(DIRECTORY_SEPARATOR,$image);
				$file = "";
				for ($i=1;$i<count($parts);$i++) {
					$file = $file . DIRECTORY_SEPARATOR . $parts[$i];
				}
				foreach ($resized_widths as $name => $resized_width) {
					$resized_path = SQM_Data_Attributes_Resize_Images_Module::$directory .
						DIRECTORY_SEPARATOR . $name . $file;
					if (file_exists($resized_path)) {
						$attributes[$key][$name] =
							SQM_Data_Attributes_Resize_Images_Module::$directory_url .
							DIRECTORY_SEPARATOR . $name . $file;
					} else {
						if (SQM_Data_Attributes_Resize_Images_Module::$create) {
							SQM_Data_Attributes_Resize_Images_Module::resize(
								SQM_Data_Attributes_Resize_Images_Module::$image_directory . $file,
								$resized_path, $resized_width
							);
							$attributes[$key][$name] =
								SQM_Data_Attributes_Resize_Images_Module::$directory_url .
								DIRECTORY_SEPARATOR . $name . $file;
						} else {
							$attributes[$key][$name] = null;
						}
					}
				}
			} else {
				foreach ($resized_widths as $name => $resized_width) {
					$attributes[$key][$name] = null;
				}
			}
		}
	}
	
	public static function add_best_nightly_attributes(
		&$attributes,$date,$key,$datetime,$datetimes,$values,$night_attributes,
		$sqm_sun_moon_info,$fileset,$datetime_keys_at_night
	) {
		global $resized_widths;
		foreach ($resized_widths as $name => $resized_width) {
			$attributes[$name] = $night_attributes[$key][$name];
		}
	}
}
?>
