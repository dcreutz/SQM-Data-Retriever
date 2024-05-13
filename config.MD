# SQM Data Retriever Configuration

The SQM Data Retriever configuration can be customized by editing the file config.php in the same directory as sqm.php.

All options in config.php are optional.

## config.php

### Data directory

```$data_directory = <string>```

The directory relative to sqm.php containing sqm data files.
		
If using data from a single station, data files should be placed in this directory. If using data from multiple stations, each station should have a subdirectory inside the data directory for its files, e.g. data/sqm1/sqm1.dat.  Stations will be identitifed internally by the names of the subdirectories.

If a file named .info is placed in the directory with the data files formatted as
```
	Name: <name>
	Latitude: <latitude>
	Longitude: <longitude>
	Elevation: <elevation>
```
then this information will be used to describe the station.

If no such file exists, the data files will be parsed looking for the same information.  Creating such files is recommended as naming a station from data files is unreliable.

Default is to expect the data files to be in a directory 'data' in the same directory as sqm.php.

### Cache directory

```$cache_directory = <string>```

The directory relative to sqm.php to use for cacheing.  Set this to null to not cache.  If the cache directory is not writeable by the web server user, no cacheing will occur.

Default is the directory 'cache' in the same directory as sqm.php.
		
### Trust or distrust files

```$trust_files = true | false | 'only if cacheing'```

Whether data files should be trusted to be named including YYYY-MM or YYYY-MM-DD, to have the data in chronological order and to end in newline characters.

This option should not be enabled unless cacheing cannot be enabled and even then should only be enabled if the dataset is so large that not enabling it causes the application to be too slow.
	
This option should only be used without cacheing and may result in unexpected behavior
if combined with cacheing.

Options are true, false or 'only if not cacheing', and 'only if not cacheing' is the preferred choice.

Default is 'only if not cacheing'.

### Add raw data

```$add_raw_data = true | false | 'only if cacheing'```

Whether to return the raw csv data along with the readings.  If enabled, each reading will have attached to it a 'raw' value which is a key value array keyed by csv column headings.

Options are true, false and 'only if cacheing'.  Default is 'only if cacheing'.

### Add sun moon info

```$add_sun_moon_info = true | false | 'only if cacheing'```

Whether the backend should add sun and moon location information to the data.
		
Options are true, false or 'only if cacheing', 'only if cacheing' is the preferred choice as this can take time.

Default is 'only if cacheing'.

### Regression analysis

```$perform_regression_analysis = true | false | 'only if cacheing'```

Whether to perform linear regression on the data to attempt to determine cloudiness.  The algorithm used is essentially the same as that due to Bill Kowalik which is used in the Unihedron software's Sun-Moon-MW-Clouds calculation.

Options are true, false or 'only if cacheing'.

Default is 'only if cacheing'.

$regression_time_range specifies the time range in minutes to perform regression over.  $regression_averaging_time_range specifies the time range in minutes to average the regression values over.  $regression_time_shift is the time in minutes after sunset/before sunrise to begin performing regression analysis.

### Filtering

```$filter_mean_r_squared = <number>
$filter_sun_elevation = <number>
$filter_moon_elevation = <number>
$filter_moon_illumination = <number>```

If regression analysis is enabled, $filter_mean_r_squared is the value which any reading with mean r^2 above is marked as filtered out.  For best nightly readings, both the best overall and the best of those not filtered out is returned.

If adding sun and moon information is also enabled, $filter_sun_elevation will filter readings when the sun is above that value in degrees.  $filter_moon_elevation and $filter_moon_illumination in conjunction will filter data based on when the moon is both above that elevation and has illumination greater than the filter value.

In all cases, all readings are returned; the filtering simply means that readings will be marked as filtered and that best nightly readings will include both the best overall and the best of the unfiltered.

### Images

```$use_images = true | false | 'only if cacheing'```
```$image_directory = <string>```
```$image_name_prefix_length = <number>```
```$image_name_suffix_length = <number>```
```$image_time_frame = <number>```

If set to true and $image_directory is set to a directory (default is 'images' in the same directory as sqm.php), the retriever will scan the images folder looking for the image closest in time to the reading and attach the name of that file to the reading.

The directory structure must be images/YYYY-MM/YYYY-MM-DD/image-file-name where YYYY is the four digit year, MM is the two digit month and DD is the teo digit day.

$image_directory_url is the url of the images directory (which is simply 'images' in the default case).  $image_name_format is the datetime format of the datetime stamp in the image file name; valid values are those accepted by [PHP Datetime](https://www.php.net/manual/en/datetime.createfromformat.php).

$image_name_prefix_length and $image_name_suffix_length indicate how many characters precede and follow the datetime stamp in the image file names.

For example, the [AllSky software](https://github.com/AllskyTeam/allsky) generates images named e.g. image-20240303175102.jpg so the prefix length is 6, the suffix length is 4 and the format is YmdHis.

$image_time_frame is the time in seconds between when a reading was taken and an image was taken for which the image ought to be considered as connected to the reading; among the images within this time period of a reading, the closest in time will be used.

$use_images may be true, false or 'only if cacheing'.  By default, 'only if cacheing' is used and the AllSky naming conventions are.  If $image_directory is not set or the directory does not exist, this is the same as $use_images being false.

### Resizing images

```$resize_images = true | false```
```$resized_images_directory = <string>```
```$resized_widths = array(<string> => <number)```

The retriever can automatically resize images to create thumbnails.

If $resize_images is set to true and the $resized_images_directory is a writeable directory (default is 'resized_images'), the retriever will resize each image to widths defined in $resized_widths.  $resized_widths should be a key value array with entries such as 'thumbnail'=>200 indicating that a directory named 'thumbnail' inside 'resized_images' should contain an image with the same name as each image in 'images' but resized to a width of 200 pixels.

$resized_directory_url is the url of the resized images directory.

Default is false.

### Clear cache on errors

```$clear_cache_on_errors = true | false```

If set to true, the cache will automatically be cleared when an error in the retriever occurs.  The most likely source of errors in the retriever is cache corruption so this option should be enabled if the application is likely to be used by multiple users.

Alternatively, the included scripts for clearing the cache can be used manually if an error occurs and persists.

Default is false.

### Time and memory limits

```$extended_time = true | false```
```$sqm_memory_limit_if_cacheing = '<number>MB'```
```$sqm_memory_limit_if_not_cacheing = '<number>MB'```

$extended_time can be set to true to allow the retriever to attempt to override the server's built-in timeout for php scripts.  Especially when resizing images, the initial run can be time consuming
and this option is designed to avoid script timeouts.

$sqm_memory_limit_if_cacheing and $sqm_memory_limit_if_not_cacheing specify how much memory the retriever should request from the server.  If not set, the retriever will work with the memory limit proscribed by the server.

### Logging

```$enable_logging = true | false```

If $enable_logging is true, the retriever will log recoverable errors such as directories not being writeable or data files not being parseable to the server's error log.