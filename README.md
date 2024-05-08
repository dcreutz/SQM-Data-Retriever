# SQM Data Retriever

An open-source server-side backend for retrieving data from Unihedron Sky Quality Meter data files.

Works with files in the 'International Dark Sky Association (IDA) NSBM Community Standards for Reporting Skyglow Observations' format [http://www.darksky.org/night-sky-conservation/248](http://www.darksky.org/night-sky-conservation/248) and files in the format output by the Unihedron software feature 'sun-moon-mw-clouds'.

The retriever can be configured to add attributes to the data including sun and moon position and illumination, regression analysis and image files.

Designed as the backend for the [SQM Visualizer](https://github.com/dcreutz/SQM-Visualizer), it provides a full API (see src/sqm_responder.php for information).

The software is licensed under the GNU Affero General Public License version 3, or (at your option) any later version.  The sofware was designed and developed by Darren Creutz.

## Installation

1. Download the SQM Data Retriever by clicking on the Code button at the top right and choosing Download Zip.

2. Extract the zip file and move the contents of the subfolder dist to the location on your web server you want it.  That is, the directory on your server should have the same contents as the dist folder.

3. Copy (or symlink) your SQM data files into the data directory, default is a directory named data in the same directory as sqm.php.

4. [Recommended] Create the cache directory, default is a directory called cache in the same directory as data.  Make the cache directory writeable by the web server user.  On shared hosting, this step usually isn't necessary; on a typical linux system, this means running chown to set the owner of the cache folder to www-data or www.

5. [Optional] If you have a camera taking images of the sky, copy (or symlink) the images in to the images directory.  The directory structure expected is iamges/YYYY-MM/YYYY-MM-DD/image-file-name, see the configuration section for more information.

6. [Optional] If you have images and would like the backend to automatically create thumbnails, make the resized_images directory writeable by the web server user.

7. [Optional] Edit config.php to customize your installation.  See the configuration section for more information.

8. [Optional] If using a large dataset, particularly if regression analysis and images are involved, run the included command line script bin/update_cache_cli.php (see below).

9. [Optional] Create .info files in each data directory specifying information about the SQM.  The file should be named .info and have the same structure as the sample.info file included with the code.

## Data directory structure

The backend can work with a single SQM's data or with that of multiple SQMs.  If using a single SQM< simply place the data files in the data directory.  If working with multiple SQMs, create a subdirectory inside the data directory for each SQM and place its data files in that directory.

If cacheing is not enabled, the backend relies on the data files to be named in such a way that indicates the month(s) of data they contain.  Therefore, they must be named in such a way as to include YYYY-MM or YYYYMM somewhere in their name for each month they have data for.  If cacheing is enabled, the names can be anything.

## Included utilities

### clear_cache.php

Browser callable script to clear the cache.  Disabled by default, to enable it, edit the .php file and change $disable = true to $disable = false.  Ideally an unnecessary script but included since cache corruption can occur.

### bin/clear_cache_cli.php

Command line script to clear the cache manually.

### bin/update_cache_cli.php

Command line script to manually update the cache.  When working with large datasets, and most especially when resizing images, this script should be used prior to the first browser call to the backend.

Note that this script must be run as the same server user as the web server runs as or after running it, the cache must be manually set to be readable and writeable by the web user.

Optionally, a cron job can be configured to periodically update the cache.

## Configuration options

config.php specifies how the backend should operate.  See the comments in config.php for explanations of the options available and to change the directory locations of the data, cache, images, etc.

## Data collection

The software works well in conjunction with [PySQM](https://github.com/mireianievas/PySQM) performing the actual data collection.

## Thanks

Thanks to Bill Kowalik for sharing his regression analysis code which ours is loosely based on.

Thanks to [suncalc-php](https://github.com/gregseth/suncalc-php).