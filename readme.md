
Included Files
==============

GIS
---

Included in this folder are a few examples of the work I've done with with maps. All of the tools I've developed relied on the excellent Google Maps API for display and manipulation of actual maps, with data being stored in MySQL databases.  PostgreSQL actually has much better tools for GIS work, but all of the tools I've built were on systems that were already using MySQL, and so I've had to make up for some of MySQL's shortcomings in that regard.

#### class.geometry.php -
This class provided various tools for working with geometric shapes and converting from PHP arrays of points to a format that could be inserted into MySQL POLYGON datatypes, and vice versa. It also handles point-in-polygon checks, tests for polygon intersection, finds the distance between two points, as well as some other utility methods for working with geometries.

#### gMap.js -
This is a javascript library that handled mapping for [Park.com](http://www.park.com), a project that I worked on at Resolute Digital. The site allowed users to search for parking garages in their area (using HTML5 Geolocation) or for a given address and would display results using Google Maps. It featured lookahead autocompletion for search using Google Places Autocomplete and geolocation via IP address. This library provided the basic front-end functionality for mapping search results as well as fencing the initial site's results to Manhattan.  Google's API has since been updated to support geo-targeting search results, but I achieved that effect here by hard-coding a polygon around Manhattan for targeting results.

#### poly_doc -
In this folder please view the `poly_tool.html` file. This was a quick training doc that I put together for managers at Delivery.com, showing some of the functionality of the delivery range tool that I created. It was essentially a 2D vector drawing app built on top of Google Maps. This allowed for much easier entry and editing of delivery ranges, and combined with better formatting of the data behind the scenes (saving as MySQL POLYGON types rather than arrays of points) allowed for much faster and more accurate searching.

#### zctaConverter.php -
In the poly_doc example I showed a zip code delivery range. We used zip code polygons for easy entry of delivery ranges for those merchants who delivered to all of a particular zip code, and also for reports and banner ad pricing. This script shows part of how we imported zip codes into the system. The US Census Bureau publishes the Zip Code Tabulation Area (ZCTA) boundary files, which while next exactly the same as US Postal Zip Codes, were close enough for our purposes. After downloading the data files, they would need to be converted into a format that could be imported into MySQL as polygons. This script does the heavy lifting for that process, parsing the data files and matching the zip code data to the defined boundaries.


image transform
---------------

#### nginx-image-transform / parse_post.lua -
This bit of code is still a work in progress, but is something I hope to release as an open source project this summer.  It was very much inspired by the [MediaCrush](https://github.com/MediaCrush/MediaCrush) project, but attempts to do with OpenResty (Nginx/Lua) what they've done with Python. It basically works as an image server and manipulator/optimizer. Images can be manipulated (ie, resized, cropped, rotated) and optimized, with animated GIFs being converted to HTML5 videos for much smaller file sizes. Images are cached locally on the image server and put in long term storage on Amazon S3.


twitter_widget
--------------
I created this tool for Business Insider. The idea behind the project was to give editors on the site the ability to set up a page with its own permanent URL as well as a widget that would live on the right rail of the selected pages for a specified period of time.  During that time, tweets from specified twitter users and/or a specified hashtag would be recorded and displayed.  It would basically allow for liveblogging events using twitter, although it also allowed for a bit more flexibility than that. There are pieces missing from what I've included, but there should be enough here to get a sense of style and mechanics.  

#### live_feed.js -
This is just a simple object wrapper for an ajax call in a singleton pattern (only one feed per page).  A call is made to initialize the object and it updates itself every minute to supply new tweets to the page/widget.  Tweets get truncated at a certain point to prevent it from overloading the browser with thousands of tweets.

#### Twitterfeed.php
I used Zend Framework's Twitter service to query Twitter for new tweets and stored the results in MongoDB documents (Business Insider runs on Mongo).  Each subsequent update should grab new tweets based on the ID sent in the query, but we do a sanity check to make sure there are no duplicates, only adding new tweets to the database.  A cron job runs every minute which calls on this code to get new tweets and the front end simply calls the records from the db via the ajax call.


other scripts
-------------

#### Pager.php -
A simple, bare bones pagination class that I used with CodeIgniter.  It eschews any of the fancy features of the pager classes that I had found and just handles simple pagination math/link generation. It could use some abstraction as far as the output template, but I used it in a few projects because I felt like the libraries I was seeing had too many bells and whistles for what I needed.

#### ScheduledTasksShell.php -
This is a script that I wrote recently for the CakePHP framework. It allows you to run scripts on a scheduled basis using a database, giving admin users the ability to control when/what should run.  I use `cron` to trigger this script, which runs once every minute, and checks the DB for any new jobs to run, spawning child processes for each script and reporting any output via email.  It uses iCal Recurrence Rules ([RFC 2445](http://tools.ietf.org/html/rfc2445)), allowing for a user friendly front end that is similar to iCal/Outlook appointment scheduling. I've since been working on a way to do a similar type of scheduling using Amazon's SQS instead of a MySQL database.


Public Github Files
===================

#### Github Account
My personal Github account is available [here](https://github.com/arclyte), and my work-specific account is available [here](https://github.com/JamesAlday), although not much is public under that account.

#### codeigniter-forensics -

The CodeIgniter framework has a nice code profiler feature that outputs basic debug information that can be helpful in development, but it's layout leaves something to be desired.  I found a very useful 'upgrade' to the basic CI profiler in Lonnie Ezzell's 'CodeIgniter-Forensics' library.  It still had a few rough edges, so I made some updates to the code which can be seen [in the repository](https://github.com/lonnieezell/codeigniter-forensics/commits/master) under my personal account, `arclyte`.

Those changes have since been incorporated into the [Bonfire](http://cibonfire.com/) [project](https://github.com/ci-bonfire/Bonfire).
