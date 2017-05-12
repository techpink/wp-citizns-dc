<?php

/**
Plugin Name: WP Citizns Democracy Club
Description: Access to the Democracy Club APIs
Version: 0.3
Author: tchpnk
Author URI: http://tchpnk.eu/
License: GPLv2 or later
Text Domain: citiznsdc
*/

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

require_once 'vendor/autoload.php';

$upload_dir = wp_upload_dir();
$cache_dir = $upload_dir['basedir'].'/citiznsdc-cache';
if ( ! file_exists( $cache_dir ) ) {
    wp_mkdir_p( $cache_dir );
}


// Create default HandlerStack
$stack = GuzzleHttp\HandlerStack::create();

// Add this middleware to the top with `push`
$stack->push(
  new Kevinrob\GuzzleCache\CacheMiddleware(
    new Kevinrob\GuzzleCache\Strategy\GreedyCacheStrategy(
      new Kevinrob\GuzzleCache\Storage\DoctrineCacheStorage(
        new Doctrine\Common\Cache\ChainCache(
        	[
        		new Doctrine\Common\Cache\ArrayCache(),
        		new Doctrine\Common\Cache\FilesystemCache($cache_dir . '/')
      		]
      	)
      ),
      3600
    )
  ), 
  'cache'
);

// Mapit guzzle client
$default_mapit_buri = "https://mapit.mysociety.org/postcode/";
$mapit_buri = (!get_option( "citiznsdc_mapit_buri")) ? $default_mapit_buri : get_option( "citiznsdc_mapit_buri");
$mapit_client = new GuzzleHttp\Client([
	'base_uri' => $mapit_buri,
	'handler' => $stack,
	'timeout' => 2.0
]);

// DC posts guzzle client
$default_posts_buri = "http://candidates.democracyclub.org.uk/api/v0.9/posts/";
$dcposts_buri = (!get_option( "citiznsdc_posts_buri")) ? $default_posts_buri : get_option("citiznsdc_posts_buri");
$dcposts_client = new GuzzleHttp\Client([
	'base_uri' => $dcposts_buri,
	'handler' => $stack,
	'timeout' => 5.0
]);

// DC persons guzzle client
$default_persons_buri = "http://candidates.democracyclub.org.uk/api/v0.9/persons/";
$dcpersons_buri = (!get_option("citiznsdc_persons_buri")) ? $default_persons_buri : get_option("citiznsdc_persons_buri");
$dcpersons_client = new GuzzleHttp\Client([
	'base_uri' => $dcpersons_buri,
	'handler' => $stack,
	'timeout' => 5.0
]);


add_shortcode( 'citiznsdc_candidates', 'citiznsdc_candidates_func' );
function citiznsdc_candidates_func($atts) {
	$gss = $_GET['gss'];
	if (!isset($gss)) {
		global $wp_query;
		$gss = $wp_query->query_vars['gss'];
	}

	if (isset($gss)) {
		try {
			return citiznsdc_fetch_candidates($gss);
		} catch (GuzzleHttp\Exception\ClientException $e) {
			// area id invalid/unrecognised
			// fall through to default message
		} catch (GuzzleHttp\Exception\RequestException $e) {
			// network/service error
 			$message = "<div><p>Service temporarily unavailable. Please try again later.</p></div>";
			
			return $message;
		}
	}
	
	// default message
	$message = "<div><p>No information could be found for area: ";
	$message .= urldecode($gss);
	$message .= "</p></div>";
			
	return $message;
}


add_shortcode( 'citiznsdc_constituency', 'citiznsdc_constituency_func' );
function citiznsdc_constituency_func($atts) {
	$wmc = $_GET['wmc'];
	if (!isset($wmc)) {
		global $wp_query;
		$wmc = $wp_query->query_vars['wmc'];
	}

	if (isset($wmc)) {
		try {
			return citiznsdc_fetch_constituency($wmc);
		} catch (GuzzleHttp\Exception\ClientException $e) {
			// area id invalid/unrecognised
			// fall through to default message
		} catch (GuzzleHttp\Exception\RequestException $e) {
			// network/service error
			$message = "<div><p>Service temporarily unavailable. Please try again later.</p></div>";
			
			return $message;
		}
	}
		
	// default message
	$message = "<div><p>No information could be found for area: ";
	$message .= urldecode($wmc);
	$message .= "</p></div>";
			
	return $message;
}

function citiznsdc_postcode_to_wmc_codes($postcode) {
	global $mapit_client;
	$response = $mapit_client->get($postcode, [
		'headers' => [
			'Accept' => 'application/json',
			'X-Api-Key' => get_option( "citiznsdc_mapit_apik")
		]
	]);

	$data = json_decode($response->getBody(), true);

	$wmc = $data['shortcuts']['WMC'];
	if (isset($wmc)) {
		$wmc = (string) $wmc;

		$area = $data['areas'][$wmc]['codes']['gss'];
		if (isset($area)) {
			return array (
				'wmc' => $wmc,
				'gss' => $area
				);
		}
	}
}

function citiznsdc_fetch_candidates($wmc_gss) {
	global $dcposts_client;
	$response = $dcposts_client->get('WMC%3A' . $wmc_gss . '/', [
		'headers' => [
			'Accept' => 'application/json'
		]
	]);

	$data = json_decode($response->getBody(), true);

	$election = $data['elections'][0];
	$candidates = $data['memberships'];

	$return = '<div class="media">';
		$return .= '<div class="media-header theme-one-header">';
			$return .= '<div class="media-heading">';
				$return .= '<div class="fa fa-user"></div>';
				$return .= 'Candidates for ' . $election['name'];
			$return .= '</div>';
		$return .= '</div>';
	
		$return .= '<div class="media-body">';

	if (empty($candidates)) {
		$return = '<p>There are currently no declared candidates.</p>';
	} else {
		foreach($candidates as $candidate) {
			$person = citiznsdc_fetch_person($candidate['person']['id']);

			$return .= '<div class="col-xs-12 col-sm-12 col-md-12 candidate">';
				$return .= '<div class="candidate-info outer-heading hidden">';
					$return .= '<div class="disc theme-' . str_replace(':', '', $candidate['on_behalf_of']['id']) . '">';
						$return .= '<div class="fa fa-bookmark"></div>';
					$return .= '</div>';
					$return .= '<h4 class="drop-inline">' . $candidate['person']['name'] . '</h4>';
				$return .= '</div>';
				$return .= '<div class="candidate-photo">';
					if (isset($person['image'])) {
						$return .= '<img src="' . $person['image'] . '" class="img-rounded">';
					}
				$return .= '</div>';
				$return .= '<div class="candidate-info">';
					$return .= '<div class="inner-heading">';
						$return .= '<div class="disc theme-' . str_replace(':', '', $candidate['on_behalf_of']['id']) . '">';
							$return .= '<div class="fa fa-bookmark"></div>';
						$return .= '</div>';
						$return .= '<h4 class="drop-inline">' . $candidate['person']['name'] . '</h4>';
						//$return .= '<div class="colored-line-left"></div>';
					$return .= '</div>';
					$return .= '<div class="candidate-party text-lock">';
						$return .= '<div>' . $candidate['on_behalf_of']['name'] . '</div> ';
					$return .= '</div>';
					$return .= '<div class="candidate-social">';
						if (isset($person['email'])) {
							$return .= '<a href="mailto:' . $person['email'] . '" target="_blank">';
								$return .= '<div class="fa fa-envelope"></div>';
							$return .= '</a>';
						}
						
						if (isset($person['twitter'])) {
							$return .= '<a href="' . $person['twitter'] . '" target="_blank">';
								$return .= '<div class="fa fa-twitter"></div>';
							$return .= '</a>';
						}
		
						if (isset($person['facebook'])) {
							$return .= '<a href="' . $person['facebook'] . '" target="_blank">';
								$return .= '<div class="fa fa-facebook"></div>';
							$return .= '</a>';
						}

						if (isset($person['linkedin'])) {
							$return .= '<a href="' . $person['linkedin'] . '" target="_blank">';
								$return .= '<div class="fa fa-linkedin-square"></div>';
							$return .= '</a>';
						}

						if (isset($person['homepage'])) {
							$return .= '<a href="' . $person['homepage'] . '" target="_blank">';
								$return .= '<div class="fa fa-globe"></div>';
							$return .= '</a>';
						}
						
					$return .= '</div>';
				$return .= '</div>';
			$return .= '</div>';

		}
	}
		$return .= '</div>';
	$return .= '</div>';

	return $return;
}


function citiznsdc_fetch_person($person_id) {
	$results = array();

	try {
		global $dcpersons_client;
		$response = $dcpersons_client->get($person_id . '/', [
			'headers' => [
				'Accept' => 'application/json'
			]
		]);

		$data = json_decode($response->getBody(), true);

		if (isset($data['thumbnail'])) {	
			$results['image'] = $data['thumbnail'];
		} else {
			if ($data['gender'] == "male") {
				$results['image'] = WP_PLUGIN_URL . "/wp-citizns-dc/images/genericM.jpg";
			} else {
				$results['image'] = WP_PLUGIN_URL . "/wp-citizns-dc/images/genericW.jpg";
			}
		}

		if (isset($data['email']) and strpos($data['email'], "@")) {
				$results['email'] = $data['email'];
		}

		foreach ($data['contact_details'] as $contact_detail) {
			if ($contact_detail['contact_type'] == "twitter") {
				$results['twitter'] = "https://twitter.com/" . $contact_detail['value'];
			}
		}

		foreach ($data['links'] as $link) {
			if (stristr($link['note'], "facebook") != false) {
				$results['facebook'] = $link['url'];
			} else if ($link['note'] == "homepage") {
				$results['homepage'] = $link['url'];
			} else if ($link['note'] == "linkedin") {
				$results['linkedin'] = $link['url'];
			}
		}
	} catch (GuzzleHttp\Exception\ClientException $e) {
		// person id is invalid/unrecognised
		// fall through to default message
		//echo dump($e);
	} catch (GuzzleHttp\Exception\RequestException $e) {
		// network/service error
		// fall through to default message
		//echo dump($e);
	}
	
	return $results;
}


function citiznsdc_fetch_constituency($wmc_code) {
	$last_election = "2015";

	global $dcposts_client;
	$response = $dcposts_client->get($wmc_code . '/', [
		'headers' => [
			'Accept' => 'application/json'
		]
	]);
	
	$data = json_decode($response->getBody(), true);

	$return = '<div class="media">';
	$return .= '<div class="media-header theme-one-header">';
	$return .= '<div class="media-heading">';
	$return .= '<div class="fa fa-map-marker"></div>';
	$return .= $data['area']['name'];
	$return .= '</div>';
	$return .= '</div>';
	
	$return .= '<div class="media-body">';

	$elections = $data['elections'];
	if (empty($elections)) {
		$return .= 'There is no data available.';
	} else {
		foreach($elections as $election) {
			if ($election['id'] == $last_election) {
				$return .= '<h4>' . $election['name'] . ' Results</h4>';
			}
		}
		
		$candidates = $data['memberships'];
		foreach($candidates as $candidate) {
			if ($candidate['election']['id'] == $last_election and $candidate['elected']) {
				$return .= '<table class="table">';
				$return .= '<tbody>';
				$return .= '<tr>';
				$return .= '<td class="text-center drop-td">';
				$return .= '<div class="fa fa-trophy"></div>';
				$return .= '</td>';
				$return .= '<th class="nowrap">Winner</th>';
				$return .= '<td>' . $candidate['on_behalf_of']['name'] . '</td>';
				$return .= '</tr>';
				$return .= '<tr>';
				$return .= '<td class="text-center drop-td">';
				$return .= '<div class="fa fa-user"></div>';
				$return .= '</td>';
				$return .= '<th class="nowrap">MP</th>';
				$return .= '<td>' . $candidate['person']['name'] . '</td>';
				$return .= '</tr>';
				$return .= '</tbody>';
				$return .= '</table>';
			}
		}
	}
	
	// media-body
	$return .= '</div>';
	
	// media
	$return .= '</div>';

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
	$placeholder = (!empty($atts['placeholder'])) ? $atts['placeholder'] : "Please enter a UK postcode";

	?>
	<div class="row">
		<div class="col-md-6">								
			<!-- FORM  -->
			<?php
			echo '<form class="search-form" name="citiznsdc-postcode-form" action="'. get_admin_url() .'admin-post.php" method="post">';
			?>
				<input type="hidden" name="action" value="citiznsdc-postcode-form" />
				<div class="input-group">
					<?php
					echo '<input class="form-control" type="text" required name="citiznsdc_postcode" placeholder="' . strip_tags( trim( $placeholder ) ) . '"/>';
					?>
					<span class="input-group-btn">
						<button class="btn btn-primary" onclick="document.getElementById('citiznsdc-postcode-form').submit();">
							<span class="screen-reader-text">FIND</span>
							FIND
						</button>
					</span>
				</div>							
			</form>							
			<!-- /END FORM -->
		</div>
		<div class="col-md-6">&nbsp;</div>
	</div>
	<?php
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
	// default redirect URL - postcode not recognised page
	$url = esc_url(home_url( '/' . "postcode-not-recognised" . '/' ));
	
	$postcode = (!empty($_POST["citiznsdc_postcode"])) ? $_POST["citiznsdc_postcode"] : NULL;
	$postcode = trim($postcode);
	if (isset($postcode) and strlen($postcode) >= 6 and strlen($postcode) <= 8) {
		$postcode = urlencode($postcode);
		
		try {
			$wmc_codes = citiznsdc_postcode_to_wmc_codes($postcode);

			citiznsdc_store_query_metadata($wmc_codes['wmc']);

			$url = esc_url(home_url( '/wmc/' . $wmc_codes['wmc'] . '/gss/' . $wmc_codes['gss'] . '/'));
		} catch (GuzzleHttp\Exception\ClientException $e) {
			// postcode invalid/unrecognised
			// fall through, $url already set
		} catch (GuzzleHttp\Exception\RequestException $e) {
			// network/service error
			$url = esc_url(home_url( '/' . "service-unavailable" . '/' ));			
		}
	}
	
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

		<h3>MapIt API</h3>
		<p>
		<label>Postcode API Base URI</label>
		<input class="" type="text" size="60" name="citiznsdc_mapit_buri" value="<?php echo get_option('citiznsdc_mapit_buri'); ?>" />
		</p>
		<p>
		<label>Postcode API Key</label>
		<input class="" type="text" size="45" name="citiznsdc_mapit_apik" value="<?php echo get_option('citiznsdc_mapit_apik'); ?>" />
		</p>

		<h3>Democracy Club API Info</h3>
		<p>
		<label>Posts API Base URI</label>
		<input class="" type="text" size="60" name="citiznsdc_posts_buri" value="<?php echo get_option('citiznsdc_posts_buri'); ?>" />
		</p>
		<p>
		<label>Persons API Base URI</label>
		<input class="" type="text" size="60" name="citiznsdc_persons_buri" value="<?php echo get_option('citiznsdc_persons_buri'); ?>" />
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
	$mapit_buri = (!empty($_POST["citiznsdc_mapit_buri"])) ? $_POST["citiznsdc_mapit_buri"] : NULL;
	$mapit_apik = (!empty($_POST["citiznsdc_mapit_apik"])) ? $_POST["citiznsdc_mapit_apik"] : NULL;
   	$posts_buri = (!empty($_POST["citiznsdc_posts_buri"])) ? $_POST["citiznsdc_posts_buri"] : NULL;
   	$persons_buri = (!empty($_POST["citiznsdc_persons_buri"])) ? $_POST["citiznsdc_persons_buri"] : NULL;
   	$cand_slug = (!empty($_POST["citiznsdc_cand_slug"])) ? $_POST["citiznsdc_cand_slug"] : NULL;

   	// TODO validation should go here

   	// Update the values
   	update_option("citiznsdc_mapit_buri", $mapit_buri, TRUE);
	update_option("citiznsdc_mapit_apik", $mapit_apik, TRUE);
	update_option("citiznsdc_posts_buri", $posts_buri, TRUE);
	update_option("citiznsdc_persons_buri", $persons_buri, TRUE);
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
	// create citiznsdc_requests db table
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

	// default API base URIs
	global $default_mapit_buri;
   	add_option( 'citiznsdc_mapit_buri', $default_mapit_buri );
   	
   	global $default_posts_buri;
   	add_option( 'citiznsdc_posts_buri', $default_posts_buri );
   	
   	global $default_persons_buri;
   	add_option( 'citiznsdc_persons_buri', $default_persons_buri );
}


function add_rewrite_rules( $wp_rewrite ) {
	$new_rules = array(
		'wmc/?([0-9]{5})/gss/?([A-Z][0-9]{8})/?$' => 'index.php?pagename=' .
		get_option("citiznsdc_cand_slug") . '&wmc=$matches[1]&gss=$matches[2]'
	);
	
	// Add the rules to the top, to make sure they have priority
	$wp_rewrite -> rules = $new_rules + $wp_rewrite -> rules;
}
add_action('generate_rewrite_rules', 'add_rewrite_rules');


function add_query_vars( $public_query_vars ) {
	$public_query_vars[] = "wmc";
	$public_query_vars[] = "gss";
 
	return $public_query_vars;
}
add_filter('query_vars', 'add_query_vars');