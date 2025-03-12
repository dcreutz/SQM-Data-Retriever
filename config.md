# SQM Data Retriever Configuration

The SQM Data Retriever can be customized by editing the file config.php.

All entries in config.php are optional.

## config.php

### Data directory

```$data_directory = <string>;```

The directory relative to sqm.php containing the data files.
		
If using data from a single station, data files should be placed in this directory. If using data from multiple stations, each station should have a subdirectory inside the data directory for its files, e.g. data/sqm1/sqm1_2024-04.dat, data/sqm2/sqm2_2023-08.dat etc.

If a file named .info is placed in the directory with the data files formatted as
```
	Name: My SQM Station
	Latitude: 30.56
	Longitude: -110.23
	Elevation: 80.1
	Timezone: America/Los_Angeles
```
then this information will be used to describe the station.

If no such file exists, the data files will be parsed looking for the same information.  Creating such files is recommended as determining information for a station from data files may be unreliable.

Default is to expect the data files to be in a directory 'data' in the same directory as sqm.php.

### Cache directory

```$cache_directory = <string> | null;```

The directory relative to sqm.php to use for cacheing.  Set this to null to not cache.  If the cache directory is not writeable by the web server user, no cacheing will occur.

Default is the directory 'cache' in the same directory as sqm.php.
		
### Trust or distrust files

```$trust_files = true | false | 'only if not cacheing';```

Whether data files should be trusted to be named including YYYY-MM or YYYY-MM-DD, to have the data in chronological order and to end in newline characters.

This option should not be enabled unless cacheing cannot be enabled and even then should only be enabled if the dataset is so large that not enabling it causes the response time to be too slow.
	
This option should only be used without cacheing and will result in unexpected behavior
if combined with cacheing.

Options are true, false or 'only if not cacheing'.

Default is 'only if not cacheing'.

### Add raw data

```$add_raw_data = true | false | 'only if cacheing';```

Whether to return the raw csv data along with the readings.  If enabled, each reading will have attached to it a 'raw' value which is a key value array keyed by csv column headings.

Options are true, false and 'only if cacheing'.  Default is 'only if cacheing'.

### Add sun moon info

```$add_sun_moon_info = true | false | 'only if cacheing';```

Whether to add sun and moon location information to the data.
		
Options are true, false or 'only if cacheing'; 'only if cacheing' is the preferred choice as this can take time.

Default is 'only if cacheing'.

### Regression analysis

```
$perform_regression_analysis = true | false | 'only if cacheing';
$regression_time_range = <number>;
$regression_averaging_time_range = <number>;
$regression_time_shift = <number>;
```

$perform_regression_analysis controls whether to perform linear regression on the data to attempt to determine cloudiness.  The algorithm used is based on similar logic to that of the algorithm due to Bill Kowalik and adapted by Anthony Tekatch in the Unihedron software's Sun-Moon-MW-Clouds calculation.

Options are true, false or 'only if cacheing'.

Default is 'only if cacheing'.

$regression_time_range specifies the time range in minutes to perform regression over.  $regression_averaging_time_range specifies the time range in minutes to average the regression values over.  $regression_time_shift is the time in minutes after sunset/before sunrise to begin performing regression analysis.

### Filtering

```
$filter_mean_r_squared = <number>;
$filter_sun_elevation = <number>;
$filter_moon_elevation = <number>;
$filter_moon_illumination = <number>;
```

If regression analysis is enabled, $filter_mean_r_squared is the value which any reading with mean $r^2$ above is marked as filtered.  For best nightly readings, both the best overall and the best of those not filtered are returned.

If adding sun and moon information is also enabled, $filter_sun_elevation will filter readings when the sun is above that value in degrees.  $filter_moon_elevation and $filter_moon_illumination in conjunction will filter data based on when the moon is both above that elevation and has illumination greater than the filter value.

In all cases, all readings are returned; the filtering simply means that readings will be marked as filtered and that best nightly readings will include both the best overall and the best of the unfiltered.

### Images

```
$use_images = true | false | 'only if cacheing';
$image_directory = <string>;
$image_directory_url = <string>;
$image_name_format = <string>;
$image_name_prefix_length = <number>;
$image_name_suffix_length = <number>;
$image_time_frame = <number>;
```

If $use_images is set to true (or to 'only if cacheing' and caching is enabled) and $image_directory is set to a directory (default is 'images' in the same directory as sqm.php), the retriever will scan the images folder looking for the image closest in time to the reading and attach the name of that file to the reading.

The directory structure must be images/SQM/YYYY-MM/YYYY-MM-DD/image-file-name where YYYY is the four digit year, MM is the two digit month and DD is the two digit day.  SQM is the name of the corresponding data folder.

$image_directory_url is the url of the images directory (which is simply 'images' in the default case).  $image_name_format is the datetime format of the datetime stamp in the image file name; valid values are those accepted by [PHP Datetime](https://www.php.net/manual/en/datetime.createfromformat.php).

$image_name_prefix_length and $image_name_suffix_length indicate how many characters precede and follow the datetime stamp in the image file names.

For example, the [AllSky software](https://github.com/AllskyTeam/allsky) generates images named e.g. image-20240303175102.jpg so the prefix length is 6, the suffix length is 4 and the format is YmdHis.

$image_time_frame is the time in seconds between when a reading was taken and an image was taken for which the image ought to be considered as connected to the reading; among the images within this time period of a reading, the closest in time will be used.

$use_images may be true, false or 'only if cacheing'.  By default, 'only if cacheing' and the AllSky naming conventions are used.  If $image_directory is not set or the directory does not exist, this is equivalent to $use_images being false.

### Resizing images

```
$resize_images = true | false;
$resized_images_directory = <string>;
$resized_widths = [ <string> => <number>, ... ];
$resized_directory_url = <string>;
```

The retriever can automatically resize images to create e.g. thumbnails.

If $resize_images is set to true and the $resized_images_directory is a writeable directory (default is 'resized_images'), the retriever will resize each image to widths defined by $resized_widths.  $resized_widths is a key value array with entries such as 'thumbnail'=>200 indicating that a directory named 'thumbnail' inside 'resized_images' should be created and contain an image with the same name as each image in 'images' but resized to a width of 200 pixels.

$resized_directory_url is the url of the resized images directory.

Default is false.

### Clear cache on errors

```$clear_cache_on_errors = true | false;```

If set to true, the cache will automatically be cleared when an error in the retriever occurs.  The most likely source of errors in the retriever is cache corruption so this option should be enabled if the application is for public use.

Alternatively, the included scripts for clearing the cache can be used manually if an error occurs and persists.

Default is true.

### Update cache from command line only

```$update_cache_cli_only = true | false;```

If set to true, the cache will only be updated by the command line utility and web access will not trigger reading the files for new data.  Only enable this if you have arranged a cron job or the equivalent to run the command line utility to update the cache.

### Time and memory limits

```
$extended_time = true | false;
$sqm_memory_limit_if_cacheing = '<number>MB';
$sqm_memory_limit_if_not_cacheing = '<number>MB';
```

$extended_time can be set to true to allow the retriever to attempt to override the server's built-in timeout for php scripts.  Especially when resizing images, the initial run can be time consuming
and this option is designed to avoid script timeouts.

$sqm_memory_limit_if_cacheing and $sqm_memory_limit_if_not_cacheing specify how much memory the retriever should request from the server.  If not set, the retriever will work with the memory limit proscribed by the server.

Note that on shared hosting, the server may not allow the retriever to request extended time or extra memory.

### Logging

```$enable_logging = true | false;```

If $enable_logging is true, the retriever will log recoverable errors such as directories not being writeable or data files not being parseable to the server's error log.