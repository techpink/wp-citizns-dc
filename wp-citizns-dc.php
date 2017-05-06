<?php

/**
Plugin Name: WP Citizns Democracy Club
Description: Access to the Democracy Club APIs
Version: 0.1
Author: tchpnk
Author URI: http://tchpnk.eu/
License: GPLv2 or later
Text Domain: citiznsdc
*/

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

require_once 'vendor/autoload.php';


add_shortcode( 'citiznsdc_candidates', 'citiznsdc_candidates_func' );
function citiznsdc_candidates_func($atts) {
	$postcode = $_GET['postcode'];
	if (isset($postcode) and strlen($postcode) >= 6 and strlen($postcode) <= 8) {
		try {
			//$wmc_code = citiznsdc_postcode_to_wmc_code($postcode);
			$wmc_code = "E14000753"; // E14001019 = CV311NJ
			
			citiznsdc_store_query_metadata($wmc_code);

			return citiznsdc_posts($wmc_code);
		} catch (GuzzleHttp\Exception\ClientException $e) {
			$message = "<div><p>No information could be found for postcode: ";
			$message .= urldecode($postcode);
			$message .= "</p></div>";
			$message .= citiznsdc_postcode_search_func("Please enter a valid UK postcode");
			
			return $message;
		} catch (GuzzleHttp\Exception\RequestException $e) {
			echo dump($e);
			
			$message = "<div><p>An unexpected error occurred. Please try again later.</p></div>";
			
			return $message;
		}
	}

	$message = "<div><p>No information could be found for postcode: ";
	$message .= urldecode($postcode);
	$message .= "</p></div>";
			
	return $message . citiznsdc_postcode_search_func("Please enter a valid UK postcode");
}


function citiznsdc_postcode_to_wmc_code($postcode) {
	$client = new GuzzleHttp\Client(
		['base_uri' => get_option( "citiznsdc_pstc_buri"), 'timeout' => 2.0]
	);

	$response = $client->get($postcode);

	$data = json_decode($response->getBody(), true);

	$wmc = $data['shortcuts']['WMC'];
	if (isset($wmc)) {
		$wmc = (string) $wmc;

		$area = $data['areas'][$wmc]['codes']['gss'];
		if (isset($area)) {
			return $area;
		}
	}
}


function citiznsdc_posts($wmc_code) {
	$client = new GuzzleHttp\Client(
		['base_uri' => get_option("citiznsdc_cand_buri"), 'timeout' => 5.0]
	);
	$response = $client->get('WMC%3A' . $wmc_code);

	$data = json_decode($response->getBody(), true);

	$candidates_locked = $data['candidates_locked'];
	$area = $data['area'];
	$election = $data['elections'][0];
	$candidates = $data['memberships'];

	$return = '<h3>';

	$return .= ($candidates_locked) ? 'Confirmed' : 'Known';
	$return .= ' candidates for ';

	$return .= create_link($area['url'], $area['name']);

	$return .= '</h3>';
	//$return .= '<div class="colored-line-left"></div>';
	//$return .= '<div class="clearfix"></div>';
	
	if (empty($candidates)) {
		$return .= 'There are currently no declared candidates.';
	} else {
		$return .= '<ul>';

		foreach($candidates as $candidate) {
			$url = 'https://whocanivotefor.co.uk/person/';
			$url .= $candidate['person']['id'];
			$url .= '/';
			$url .= str_ireplace(' ', '-', strtolower($candidate['person']['name']));
			
			$link = create_link($url, $candidate['person']['name']);
			$return .= "<li>{$link} - {$candidate['on_behalf_of']['name']}</li>";
		}
	
		$return .= '</ul>';
	}

	return $return;
}


function citiznsdc_store_query_metadata($area_id) {
	$geoipInfo = geoip_detect2_get_info_from_current_ip();
	if (isset($geoipInfo->country->isoCode) and isset($area_id)) {
		$source = $geoipInfo->country->isoCode;
	
		global $wpdb;
		$table_name = $wpdb->prefix . 'citiznsdc_requests';
		
		$wpdb->insert ( 
			$table_name, 
			array ( 
				'source' => $source, 
				'constituency' => $area_id 
			) 
		);
	}
}


add_shortcode( 'citiznsdc_postcode_search', 'citiznsdc_postcode_search_func' );
function citiznsdc_postcode_search_func($atts) {
   $placeholder = (!empty($atts)) ? $atts :"Please enter a UK postcode";
	
	echo "<form class='search-form' action='".get_admin_url()."admin-post.php' method='post'>";

		echo "<input type='hidden' name='action' value='citiznsdc-postcode-form' />";

		echo "<p>";
		echo "<input class='form-control' type='text' required name='citiznsdc_postcode' placeholder='" . $placeholder . "'/>";
		echo "</p>";

		echo "<input class='button button-primary' type='submit' value='FIND' />";

	echo "</form>";
}


function create_link($link, $link_text) {
	return "<a href=\"{$link}\" target=\"_blank\">{$link_text}</a>";
}


function dump($var) {
	echo "<pre>";
	var_dump($var);
	echo "</pre>";
}


// If the user is logged in
add_action('admin_post_citiznsdc-postcode-form', 'citiznsdc_handle_form_action');
// If the user in not logged in
add_action('admin_post_nopriv_citiznsdc-postcode-form', 'citiznsdc_handle_form_action');
function citiznsdc_handle_form_action(){
	$postcode = (!empty($_POST["citiznsdc_postcode"])) ? $_POST["citiznsdc_postcode"] : NULL;
	
	// remove any whitespace
	//$postcode = preg_replace('/\s/', '', $postcode);
	$postcode = urlencode(trim($postcode));
	
	$url = esc_url(add_query_arg("postcode", $postcode,
		home_url( '/' . get_option("citiznsdc_cand_slug") . '/' )));
	
	wp_redirect($url);
	exit;
}


// Register the menu
add_action( "admin_menu", "citiznsdc_plugin_menu_func" );
function citiznsdc_plugin_menu_func() {
	add_submenu_page(
   		"options-general.php",		// Which menu parent
		"citizns Democracy Club",	// Page title
		"Democracy Club API",		// Menu title
		"manage_options",			// Minimum capability (manage_options is an easy way to target administrators)
		"citiznsdc",				// Menu slug
		"citiznsdc_plugin_options"	// Callback that prints the markup
	);
}


// Print the markup for the page
function citiznsdc_plugin_options() {
	if ( !current_user_can( "manage_options" ) )  {
		wp_die( __( "You do not have sufficient permissions to access this page." ) );
	}

	if ( isset($_GET['status']) && $_GET['status']=='success') { 
	?>
		<div id="message" class="updated notice is-dismissible">
			<p>Settings updated!</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>
		</div>
	<?php
	}

	?>
	<form method="post" action="<?php echo admin_url( 'admin-post.php'); ?>">

		<input type="hidden" name="action" value="update_citiznsdc_settings" />

		<h3>MapIt API Info</h3>
		<p>
		<label>Postcode API Base URI</label>
		<input class="" type="text" size="45" name="citiznsdc_pstc_buri" value="<?php echo get_option('citiznsdc_pstc_buri'); ?>" />
		</p>

		<h3>Democracy Club API Info</h3>
		<p>
		<label>Candidates API Base URI</label>
		<input class="" type="text" size="45" name="citiznsdc_cand_buri" value="<?php echo get_option('citiznsdc_cand_buri'); ?>" />
		</p>

		<h3>Candidates Page Info</h3>
		<p>
		<label>Candidates Page Slug</label>
		<input class="" type="text" name="citiznsdc_cand_slug" value="<?php echo get_option('citiznsdc_cand_slug'); ?>" />
		</p>

		<input class="button button-primary" type="submit" value="Save" />

	</form>

	<?php
}


add_action( 'admin_post_update_citiznsdc_settings', 'citiznsdc_handle_save' );
function citiznsdc_handle_save() {

   // Get the options that were sent
   $pstc_buri = (!empty($_POST["citiznsdc_pstc_buri"])) ? $_POST["citiznsdc_pstc_buri"] : NULL;
   $cand_buri = (!empty($_POST["citiznsdc_cand_buri"])) ? $_POST["citiznsdc_cand_buri"] : NULL;
   $cand_slug = (!empty($_POST["citiznsdc_cand_slug"])) ? $_POST["citiznsdc_cand_slug"] : NULL;

   // Validation would go here

   // Update the values
   update_option( "citiznsdc_pstc_buri", $pstc_buri, TRUE );
   update_option("citiznsdc_cand_buri", $cand_buri, TRUE);
   update_option("citiznsdc_cand_slug", $cand_slug, TRUE);

   // Redirect back to settings page
   // The ?page=citiznsdc corresponds to the "slug" 
   // set in the fourth parameter of add_submenu_page() above.
   $redirect_url = get_bloginfo("url") . "/wp-admin/options-general.php?page=citiznsdc&status=success";
   header("Location: ".$redirect_url);
   exit;
}


global $citiznsdc_db_version;
$citiznsdc_db_version = '1.0';

register_activation_hook( __FILE__, 'citiznsdc_install' );
function citiznsdc_install() {
	global $wpdb;
	global $citiznsdc_db_version;

	$table_name = $wpdb->prefix . 'citiznsdc_requests';
	
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table_name (
		id mediumint(9) NOT NULL AUTO_INCREMENT,
		ts timestamp DEFAULT CURRENT_TIMESTAMP,
		source varchar(20) NOT NULL,
		constituency varchar(20) NOT NULL,
		PRIMARY KEY (id)
	) $charset_collate;";

	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	dbDelta( $sql );

	add_option( 'citiznsdc_db_version', $citiznsdc_db_version );
}

