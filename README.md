# SQM Data Retriever

Open-source server-side backend for managing data collected by Unihedron Sky Quality Meters and attaching attributes such as sun and moon position information, linear regression analysis and image files.

Works with files in the 'International Dark Sky Association (IDA) NSBM Community Standards for Reporting Skyglow Observations' [format](https://darksky.org/app/uploads/bsk-pdf-manager/47_SKYGLOW_DEFINITIONS.PDF) and files in the format output by the Unihedron software feature 'sun-moon-mw-clouds'.

Designed as the backend for the [SQM Visualizer](https://github.com/dcreutz/SQM-Visualizer), it provides a full API (see [src/sqm_responder.php](src/sqm_responder.php) for information) and is designed to be (relatively) easily extensible (see [src/sqm_data_attributes.php](src/sqm_data_attributes.php) and its subclasses, e.g. [src/sqm_data_attributes_computer.php](src/sqm_data_attributes_computer.php), for details).

The SQM Data Retriever software is free and open-source, provided as-is and licensed under the GNU Affero General Public License version 3, or (at your option) any later version.  The sofware was designed and developed by Darren Creutz.

PHP version 8 or higher is required.

## Installation

1. Download the SQM Data Retriever by clicking on the Code button at the top right and choosing Download Zip.

2. Extract the zip file and move the contents of the subfolder dist to a location on your web server of your choice.  That is, the directory on your server should have (at least) the same contents as the extracted dist folder.

3. Create a directory 'data' in the same directory as sqm.php, then copy (or symlink) your SQM data files into the data directory.  (The location of the data directory can be changed in config.php, see the [configuration instructions](config.md) for more information).

4. [Recommended] Create a directory 'cache' in the same directory.  Make the cache directory writeable by the web server user.  On a typical linux system, this means running chown to set the owner of the cache folder to www or www-data.

5. [Optional] If you have a camera taking images of the sky, create an 'images' directory, then copy (or symlink) the images in to the images directory.  The directory structure expected is images/YYYY-MM/YYYY-MM-DD/image-YYYYMMDDHHiiss.jpg, see the [configuration instructions](config.md) for information.

6. [Optional] If you have images and would like the backend to automatically create thumbnails, create a 'resized_images' directory writeable by the web server user.

7. [Optional] Edit config.php to customize your installation.  See the [configuration instructions](config.MD) for more information.

8. [Optional] If using a large dataset, particularly if regression analysis and images are involved, run the included command line script bin/update_cache_cli.php (see below).

9. [Optional] Create .info files in each data directory specifying information about the SQM.  The file should be named .info and have the structure
```
	Name: My SQM Station
	Latitude: 30.23
	Longitude: -110.45
	Elevation: 100.67
	Timezone: America/Los_Angeles
```

## Data directory structure

The backend can work with a single SQM's data or with that of multiple SQMs.  If using a single SQM, simply place the data files in the data directory.  If working with multiple SQMs, create a subdirectory inside the data directory for each SQM and place its data files in that directory.

If cacheing is not enabled, the backend relies on the data files to be named in such a way that indicates the month(s) of data they contain.  Therefore, they must be named in such a way as to include YYYY-MM or YYYYMM somewhere in their name for each month they have data for.

If cacheing is enabled, the file names can be anything.

## Included utilities

### clear_cache.php

Browser callable script to clear the cache.  Disabled by default, to enable it, edit the .php file and change the line ```$disable = true;``` to ```$disable = false;```.  Ideally an unnecessary script but included since cache corruption can occur when server connectivity is interrupted.

### bin/clear_cache_cli.php

Command line script to clear the cache.  This script can only be run when in the directory containing sqm.php.

### bin/update_cache_cli.php

Command line script to update the cache.  This script can only be run when in the directory containing sqm.php.

When working with large datasets, and most especially when resizing images, this script should be used prior to the first browser call to the backend.

Note that this script must be run as the same server user as the web server runs as or after running it, the cache must be manually set to be writeable by the web user.

Optionally, a cron job can be configured to periodically update the cache using a cron.d file (or crontab entry) similar to

```5 * * * * www-data cd /var/www/html; ./bin/update_cache_cli.php```

If a cron job is configured, optionally edit config.php to prevent cacheing based on web requests

```$update_cache_cli_only = true;```

## Data collection

The software works well in conjunction with [PySQM](https://github.com/mireianievas/PySQM) performing the actual data collection.

## Acknowledgements

Thanks to Bill Kowalik for sharing his regression analysis and milky way code which ours is loosely based on.
Thanks to [suncalc-php](https://github.com/gregseth/suncalc-php).