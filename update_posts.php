<?php

@set_time_limit ( 0);//Prevent 120 sec execution time limit (check also on live server!)	
date_default_timezone_set("Europe/Amsterdam");
if (! defined('BVS_UPDATE_POSTS_LOG')) define( 'BVS_UPDATE_POSTS_LOG', dirname(__FILE__).'/logs/'.'log_update_posts_'.date('yymdHis').'.log' );
if (! defined('BVS_UPDATE_POSTS_PATH')) define( 'BVS_UPDATE_POSTS_PATH', dirname(__FILE__) );
if (! defined('PATH_WP_LOAD')) define('PATH_WP_LOAD',dirname(__FILE__) . '/../' . 'wp-load.php'); //one directory above this dir
if ( file_exists(PATH_WP_LOAD) ) {	require_once( PATH_WP_LOAD );}else { echo 'NOT FOUND: '.PATH_WP_LOAD;	exit;}
//Note that after initializing the WP environment $wpdb is available as global
if (! defined('PATH_BVS_BSR')) define('PATH_BVS_BSR',BVS_UPDATE_POSTS_PATH.'/includes/class-bsr-db.php');
if ( file_exists(PATH_BVS_BSR) ) {	require_once( PATH_BVS_BSR ); } else { echo 'NOT FOUND: '.PATH_BVS_BSR;exit;}

$bvs_update_posts_args=array(
	'dry_run'=>'on',  //'on' or 'off'
	'verbose'=>true,
	'csv_revision_info'=>'csv/citiesexample-updates.csv',
	'query'=>
	// Should be a valid query to select the posts to process.
	// Note in this case the use of $wpdb which is only available once the WP environment is set up.
	"SELECT * FROM $wpdb->posts WHERE 
			post_name LIKE 'cities-%' AND
			post_type='page' AND post_status='publish' ORDER BY post_name ASC",			
	'check_serialize_precision'=>true  //Check serialized double and float values, true/false
	//,'user_login'    => '',    //User login name one uses to access the WP Admin Panel. Not necessary to set since we update the DB directly 
    //'user_password' => ''    //password one uses to access the WP Admin Panel. Not necessary to set since we update the DB directly 			
);

$verbose = $bvs_update_posts_args['verbose'] ; 

// Initialize the (adapted) Better Search Replace DB class offering the search and replace functonality
$bvs_bsr_db   = new BVS_BSR_DB();

//TODO Not necessary to logon as WP user since we change directly in the DB.
//Note: this would be necessary if you want to use e.g. wp_update_post to save the user name and date update
if ( isset($bvs_update_posts_args['user_login']) ){
	$creds = array(
        'user_login'    => $bvs_update_posts_args['user_login'],
        'user_password' => $bvs_update_posts_args['user_password'],
        'remember'      => false
    );
	$user = wp_signon( $creds, false );	
}

$query = $wpdb->prepare( $bvs_update_posts_args['query'] ,null);
$posts=$wpdb->get_results( $query );

$csv_revisions= import_tab_csv_large(getcwd().'/'.$bvs_update_posts_args['csv_revision_info']);

foreach($csv_revisions as $row)
{
	$search=$row['search_for'];
	$replace=$row['replace_with'];
	$post_name_csv=$row['post_name']; //Name of post for which revision is primarily intended (indicated in csv line starting with 'Link') 
	$count_posts_updated=0;
	if ($verbose) echo '<br><br><strong>Search: </strong><br>'.esc_html($search).'<br>';
	if ($verbose) echo '<strong>Revision: </strong><br>'.esc_html($replace).'<br>';		
	$update_table_args['search_for']=$search;
	$update_table_args['replace_with']=$replace;	
	$update_table_args['dry_run']=$bvs_update_posts_args['dry_run']; //'on' or 'off'
	$update_table_args['check_serialize_precision']=$bvs_update_posts_args['check_serialize_precision'];
	
	foreach($posts as $post){
		if ($row['this_post_only']) {
			if ($post->post_name!==$row['post_name']) continue;
		}
		//echo '<br><strong>Post: </strong>'.$post->post_name.'<br>';//debug
		
		//Update table _posts
		$so_search='';
		$so_replace='';
		unset($result);
		$addslashes='';
		if (is_site_origin_active()){//debug
			//In post_content slashes etc. are not escaped by backslash when creating copy of a post with Duplicate This. Only after update in Admin panel these are added.
			//This can cause problems when switching between SO Editor and basic editor (will empty panel)
			//TODO How to force programatically update done in Admin panel?
			$addslashes  = ( strpos($post->post_content,htmlentities('<\/'))!==false ) ? true:false; 
			$so_search=str_so_bvs($search,$addslashes); //convert to SiteOrigin encoding
			$so_replace=str_so_bvs($replace,$addslashes);		
		}	
		
		$update_table_args['search_for']= empty($so_search) ? $search: $so_search;
		$update_table_args['replace_with']= empty($so_replace) ? $replace: $so_replace;
		$update_table_args['table']=$wpdb->prefix.'posts';//!!adding prefix is necessary	
		$update_table_args['where']='ID='.$post->ID;	
		$update_table_args['only_column']='post_content';

		//For the principal post where revision is expected also report and log when search term is not found in the post but the replace term is.
		//This could indicating a possible update in earlier run
		$update_table_args['report_repl_found']= ($post->post_name===$post_name_csv) ? true:false;

		$result=bvs_update_table($update_table_args,!$addslashes); //if slashes are added to search and replace terms do not strip them 
		
		if ($result){
			if ($result['change']) {
				$count_posts_updated++; //NB Only count if found in 'wp_posts'	
				if ($verbose) echo "<br><strong>$post->post_name</strong>". ' updated';
			}
			log_update($post->ID,$post->post_name,$result);
		}
		else {
			if ($post->post_name===$post_name_csv) log_no_update($row,'NOT_FOUND_IN_MAIN_POST',$wpdb->prefix.'posts');
		}
		
		//Update table _postmeta
		$update_table_args['search_for']=$search;
		$update_table_args['replace_with']=$replace;	
		$update_table_args['table']=$wpdb->prefix.'postmeta';//!!adding prefix is necessary	
		$update_table_args['where']='post_id='.$post->ID;
		$update_table_args['only_column']='';
		$result=bvs_update_table($update_table_args);
		log_update($post->ID,$post->post_name,$result);
	}
	if (!$count_posts_updated) log_no_update($row,'NOT_FOUND_IN_ANY_POST',$wpdb->prefix.'posts');
}
exit;

function bvs_update_table($args,$stripslashes=true) {
	//Using BVS_BSR_DB::srdb directly to update a complete table.
	//Class BVS_BSR_DB is defined in /includes/class-bsr-db.php, an adapted version of the WP plugin Better Search Replace DB class
	//See https://wordpress.org/plugins/better-search-replace/

	$debug=false;
	
	//Adapted version of how arguments are passed in BSR_AJAX::process_search_replace() in package Better_Search_Replace/includes/class-bsr-ajax.php 
	$args = array(
		'table' 	=> isset( $args['table'] ) ? $args['table'] : array(), //BVS Added, if set only this table is processed
		'case_insensitive' 	=> isset( $args['case_insensitive'] ) ? $args['case_insensitive'] : 'off',  //'on' or 'off'
		'replace_guids' 	=> isset( $args['replace_guids'] ) ? $args['replace_guids'] : 'off',
		'dry_run' 			=> isset( $args['dry_run'] ) ? $args['dry_run'] : 'on',
		//ORG: 'search_for' 		=> isset( $args['search_for'] ) ? stripslashes( $args['search_for'] ) : '',
		//ORG: 'replace_with' 		=> isset( $args['replace_with'] ) ? stripslashes( $args['replace_with'] ) : '',
		//BvS We don't strip slashes here yet
		'search_for' 		=> isset( $args['search_for'] ) ? $args['search_for']  : '',
		'replace_with' 		=> isset( $args['replace_with'] ) ? $args['replace_with']  : '',		
		'where' 		=> isset( $args['where'] ) ? $args['where']  : '',  //BvS Added to enable filter on e.g. post_id in _postmeta
		'only_column' 		=> isset( $args['only_column'] ) ? $args['only_column']  : '',  //BvS Added to enable filter on one column
		//BvS Added. See comment in class-bsr-db.php :
		'check_serialize_precision' => isset( $args['check_serialize_precision'] ) ? $args['check_serialize_precision']  : false, 
		'report_repl_found'=> isset( $args['report_repl_found'] ) ? $args['report_repl_found']  : false
		//ORG: 'completed_pages' 	=> isset( $args['completed_pages'] ) ? absint( $args['completed_pages'] ) : 0,
		//ORG: 'step'				=> isset( $args['step'] ) ? absint( $args['step'] ) : 0  
	);	
	
	if ($stripslashes){
		$args['search_for']= stripslashes( $args['search_for'] );
		$args['replace_with']= stripslashes( $args['replace_with'] ) ;
	}

	global $bvs_bsr_db;
	
	$pages=$bvs_bsr_db->get_pages_in_table($args['table']);
	
	//Do search replace for each page and create result report for all pages in the table summed up.
	for($i=0;$i<$pages;$i++   ){
		unset($result);
		unset($page_result);
		$result= $bvs_bsr_db->srdb( $args['table'], $i, $args );
		if ( isset($result) ) {
			$page_result= $result['table_report'];
			if ($page_result['updates']) $table_report['updates']+=$page_result['updates']; 
			if ($page_result['details']['change'] ){
				$table_report['change']+=$page_result['change'];
				foreach ($page_result['details']['change'] as $value){
					$table_report['details']['change'][]=$value;
				}	
			}
			if ($page_result['details']['repl_found'] ){
				$table_report['repl_found']+=$page_result['repl_found'];			
				foreach ($page_result['details']['repl_found'] as $value){
					$table_report['details']['repl_found'][]=$value;
				}	
			}			
		}
	} 
	if ($debug) var_dump($table_report['details']);
	return $table_report;
}

function is_site_origin_active(){
	if (get_plugins()['siteorigin-panels/siteorigin-panels.php']){
		if (is_plugin_active('siteorigin-panels/siteorigin-panels.php')){
			return true;
		}
	}
	return false;
}

function str_so_bvs($html,$addslashes=false){
	//Convert string with HTML tags to how SiteOrigin encodes and saves HTML code in post_content.
	if($html != strip_tags($html)){  //if contains HTML tags
		if ($addslashes) $html = addcslashes(addslashes($html),'/'); //Note: addslashes does not escape forward slashes
		$html= htmlspecialchars($html);  //replace html tags by HTML entities e.g. &lt;a href=\&quot;\/slug-post\/\&quot;&gt;where to find ticket shop&lt;\/a&gt;
		//NOT necessary?? //$html= htmlspecialchars($html); //once more to replace & => &amp;		
	}
	return $html;
}

function log_update($post_id,$post_name,$table_result){
	/* Log returned array from bvs_update_table()
	* bvs_update_table() calls class-bsr-db::srdb.
	* class-bsr-db::srdb could be called several times in bvs_update_table() if there exist several pages.
	* All page results are joined together by bvs_update_table() and it returns a result for the whole table. 
	*
	* Fields in log are separated by |
	* The log consists of the following fields:
	* Name field:		Explanation:	 
	* post_id_csv		wp_posts->ID of post in revisions csv.  
	* post_name_csv		The name of the post is found on the line in the csv containing 'Link'
	* table				Name of table e.g. 'wp_posts'
	* column			Name of column(field) in table where revision is to be made
	* primary_key		Name of primary key
	* val_pr_key		Value primary key
	* meta_key			Value of meta_key for current record (if field exists, i.e. in table postmeta)
	* rev_status		'FOUND','NOT_FOUND','REPLACE-TERM_FOUND_IN_FIELD
	* search			Search term
	* replace			Replace term
	*/

	$debug=false;
	if ($table_result['details']['change']){
		foreach($table_result['details']['change'] as $details){
			unset($line);		
			$line[]=$post_id;
			$line[]=$post_name;
			$line[]=$details['table'];
			$line[]=$details['column'];
			$line[]=$details['primary_key'];
			$line[]=$details[$details['primary_key']];
			$line[]=$details['meta_key'];
			$line[]='FOUND';
			$line[]=$details['search_for'];
			$line[]=$details['replace_with'];
			if ($debug) var_dump($line);
			write_to_log(BVS_UPDATE_POSTS_LOG,$line);
		}
	}
	
	if ($table_result['details']['repl_found']){
		foreach($table_result['details']['repl_found'] as $details){
			unset($line);
			$line[]=$post_id;
			$line[]=$post_name;
			$line[]=$details['table'];
			$line[]=$details['column'];
			$line[]=$details['primary_key'];
			$line[]=$details[$details['primary_key']];
			$line[]=$details['meta_key'];
			$line[]='REPLACE_FOUND_IN_FIELD';
			$line[]=$details['search_for'];
			$line[]=$details['replace_with'];
			if ($debug) var_dump($line);
			write_to_log(BVS_UPDATE_POSTS_LOG,$line);			
		}		
	}
}

function log_no_update($csv_row,$status='',$table=''){
	/*
	* Log when no post was found for the search term.
	* This signals a major problem with a search term since this should never happen provided that
	* the used posts query covers at least the csv which contains the list with search terms
	*/

	//NB Trim to remove trailing CRLF (??)
	$post_name=trim($csv_row['post_name']); //The post_name indicated in the csv in which the replacement should be made principally
	//id of this post
	if ($post_name){
		$args = array(
		  'name'        => $post_name,
		  'post_type'   => 'page',
		  'post_status' => 'publish',
		  'numberposts' => 1
		);
		$my_posts = get_posts($args);
		$line[]=isset($my_posts)? $my_posts[0]->ID : '';
	}
	else {
		$line[]='';
	}

	$line[]= $post_name; 
	array_push($line,$table ,'','','','',$status);
	$line[]=$csv_row['search_for'];
	$line[]=$csv_row['replace_with'];
	write_to_log(BVS_UPDATE_POSTS_LOG,$line);
}

function write_to_log($path,$info){
	if(!is_file($path)){
		// Write column headers to file
		$headers=array(	'post_id_csv','post_name_csv','table','column','primary_key','val_pr_key','meta_key','rev_status','search','replace');		
		file_put_contents($path, implode("|",$headers).PHP_EOL, FILE_APPEND);
	}
	if (is_array($info)) $info=implode("|",$info); 
	return file_put_contents($path, $info.PHP_EOL, FILE_APPEND);
}

function import_tab_csv_large($path_csv,$num_columns=3){
	// Get the CSV file and turn it into a array
	$debug=false;
	if ($debug) echo $path_csv.'<br>';
	$csv=[];
	$handle = fopen($path_csv, "rb");
	if ($handle) {
		while ( ($line =  fgets($handle,4096) )  !== false) { 
			//NB Without trim new line is also included!
			//$line=utf8_encode($line);
			$csv[]=array_chunk(explode("\t", trim($line)),$num_columns)[0]; 
		}
		fclose($handle);
	} else {
		echo "Error reading ".$path_csv;
	}
	
	$post_name='';
	
	foreach($csv as $key=>$row){
		if (strpos(trim($row[0]),'Link')===0) {
			//CSV should contain lines which consist of Link and the url of the post in which the search terms in the next lines are found  
			$post_name = basename($row[1]); //save the current link, only the slug of the post
			unset($csv[$key]);
			continue;
		}

		//Enables only reading part of the file for debugging.
		if (strpos(trim($row[0]),'[EXIT]')!==false) $unset_next_keys=true;
		
		if ($unset_next_keys){
			unset($csv[$key]);
			continue;
		} 
		else {
			//TODO Note that on windows a file is not saved as UTF-8.
			//Probably this causes the first line to contain extra first not printable characters (239??)
			//This causes  (strpos(trim($row[0]),'#')===0) always to be false on the FIRST line.
			//Even if present at the start of the string, it is found on pos 3
			//Using strpos(trim($row[0]),'#') will return 1
			//See also:
			//https://stackoverflow.com/questions/15829554/strlen-php-function-giving-the-wrong-length-of-unicode-characters
			//https://stackoverflow.com/questions/3800292/working-with-files-and-utf8-in-php
		
			if (strpos(trim($row[0]),'[HEADER]')!==false) {unset($csv[$key]);continue;}
			if ( strpos(trim($row[0]),'#')===0 ) {unset($csv[$key]);continue;} //Ignore row starting with # (indicating comment)
			
		}
		if ($row[0]==null) { unset($csv[$key]);continue; } //if csv contains empty lines
		if ( empty(trim($row[0])) ) { unset($csv[$key]);continue; } //Without this, line existing only of tab can produce $row[0] ='''' (length 2)???
		$csv[$key]['post_name'] =$post_name;
	}
	$csv=array_values($csv); //rearrange index (not strictly necessary?)
	
	foreach($csv as $row){
		$csv_assoc[]=array('search_for'=>$row[0],
						'replace_with'=>$row[1],
						'this_post_only'=>$row[2],
						'post_name'=>$row['post_name']);
	}
	
	if ($debug) wp_die(var_dump($csv_assoc));
	return $csv_assoc;
}

// For debugging. Reset post_content
function test_set_search(&$search,&$replace,$post_id){
	global $wpdb;
	$sql = "SELECT * FROM $wpdb->posts WHERE id = $post_id";
	$query=$wpdb->prepare($sql,null);
	$post=$wpdb->get_row($query);
	if ($post){
		if (strpos($post->post_content,$search)===false){
			$temp=$search;
			$search=$replace;
			$replace=$temp;
			echo '<br>Swapped $replace->$search:.'.$search.'<br>';
			echo '<br>Swapped $search->$replace:.'.$replace.'<br>';
		}	
	}
}

