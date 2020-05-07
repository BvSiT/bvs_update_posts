## What is the purpose of bvs_update_posts?

This PHP script reads a csv file containing a list of search and replace terms allowing bulk operations. It uses code from the WordPress plugin Better Search Replace https://wordpress.org/plugins/better-search-replace/ which in turn uses code from 
https://github.com/interconnectit/Search-Replace-DB

## Why was it created?

I worked on a WordPress website with hundreds of pages  that needed editing for almost every sentence. The site used the SiteOrigin Page Builder plugin.
To do these updates directly in each page in WP Admin was a very time-consuming and tedious process. One has to access all the panels separately, it takes a lot of clicking and closing, etc.

So finally I exported all content to a text file. Working from this file I created a new Word file with a table as follows:

* first column original text
* second column updated tekst
* third column label ONLY_THIS_POST if revision should only be made to this post
* for each next post a starting row with in the first column 'Link' and the second column the url to the post.

This process also allowed to find repeating changes in the source text file by using Word macros. This avoided repeated work if the same set of words existed in several posts. 

Once the table creation was finished, it was exported to a tab separated csv file. See example csv file in /csv 

This file is read and processed by *update_posts.php* All changes are reported in a very detailed log file created for each run. A dry run is possible.

## How to use?

* Copy the folder *bvs_update_posts* to a folder under the root of the WP website. NB Needless to say that this folder should be removed once the update operation is completed!
* Copy the csv to be processed to /csv . See the example file for the correct syntax.
* Adapt the settings in bvs_update_args at the top of *bvs_update_posts.php*
* Run */bvs_update_posts/update_posts.php*
* Log will be made in /logs

You can run the script several times. Only posts that have not been updated yet will be updated. Corrections that have been done before will be marked in the log as SEARCH_TERM_FOUND_IN_FIELD

Look for log entries marked as NOT_FOUND This could indicate a major problem with a search term.

This script was created especially for a WP website using the SiteOrigin Page Builder plugin. Note that SO saves all data in wp_postmeta in a serialized way and not primarily in wp_post->post_content. *update_posts.php* will also correctly adapt search terms that contain HTML tags. 

Limitations: In the SO panel one can use non-HTML (\n like) line breaks. These are ignored by bvs_update.php . As a consequence if line breaks are present in the SO panel a search term will not be found. There are several solutions possible. You could break up the searched paragraphs into several separate search terms in the csv. You could also simple remove the line breaks in the SiteOrigin panel. HTML line breaks `<br>` are processed correctly.

The current version concentrates on updating content in posts, although  it does update wp_postmeta completely for all keys. Somewhat adapted I used it also for updating other keys in wp_postmeta e.g. keys storing  data for the Yoast plugin.

# Adaptations to the class-bsr-db.php  of the plugin better-search-replace

I created my own version of the original *class-bsr-db.php*. See *bvs_update_posts\includes\class-bsr-db.php* . I tried to leave *class-bsr-db.php* as much intact as possible. However there were some changes:

* class was renamed BVS_BSR_DB (so distinguishing itself  from possibly loaded original  plugin class)
* most code changes are made in function BVS_BSR_DB::srdb()
* added the option to limit the search by adding a ['where'] argument 
* added teh option to limit the search to one column.
* adapted the reporting to suit better the creation of a detailed log.
* prevent false search positives for serialized data if the serialization_precision was changed (a minor bug in Better Search Replace). See bvs_update_posts\includes\class-bsr-db.php  

* Search in the code for 'BvS' for changes.
