<?php

/**
Plugin Name: WP Citizns Democracy Club
Description: Access to the Democracy Club APIs
Version: 0.4.6
Author: tchpnk
Author URI: http://tchpnk.eu/
License: GPLv2 or later
Text Domain: citiznsdc
*/

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

require_once 'vendor/autoload.php';

// Shortcodes
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
	$gss = $_GET['gss'];
	if (!isset($gss)) {
		global $wp_query;
		$gss = $wp_query->query_vars['gss'];
	}

	$wmc = $_GET['wmc'];
	if (!isset($wmc)) {
		global $wp_query;
		$wmc = $wp_query->query_vars['wmc'];
	}

	if (isset($gss) and isset($wmc)) {
		try {
			return citiznsdc_fetch_constituency($gss, $wmc);
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

add_shortcode( 'citiznsdc_postcode_search', 'citiznsdc_postcode_search_func' );
function citiznsdc_postcode_search_func($atts) {
	$placeholder = (!empty($atts['placeholder'])) ? $atts['placeholder'] : "Please enter a UK postcode";

	?>
	<div class="row">
		<div class="col-md-3">&nbsp;</div>
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
		<div class="col-md-3">&nbsp;</div>
	</div>
	<?php
}

// Create doctrine cache directory
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


//
// Democracy Club API helpers
//

function citiznsdc_fetch_candidates($gss) {
	global $dcposts_client;
	$response = $dcposts_client->get('WMC%3A' . $gss . '/', [
		'headers' => [
			'Accept' => 'application/json'
		]
	]);

	$data = json_decode($response->getBody(), true);

	$election = $data['elections'][0];
	$candidates = $data['memberships'];

	$return = '<div class="media">';
		$return .= '<div class="media-header theme-citiznsdc-header">';
			$return .= '<div class="pull-right">';
				$return .= '<a href="#" class="theme-citiznsdc-info btn btn-default" title="Find out more" data-toggle="modal" data-target="#candidatesModal">';
					$return .= '<div class="fa fa-info-circle"></div>';
				$return .= '</a>';
			$return .= '</div>';
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
			if ($candidate['election']['id'] == 'parl.2017-06-08') {
				$person = citiznsdc_fetch_person($candidate['person']['id']);

				$return .= '<div class="col-xs-12 col-sm-12 col-md-12 candidate">';
					$return .= '<div class="candidate-info outer-heading hidden">';
						$return .= '<div class="rosette theme-dc-' . str_replace(':', '', $candidate['on_behalf_of']['id']) . '">';
							$return .= '<div class="fa fa-bookmark"></div>';
						$return .= '</div>';
						$return .= '<h4 class="drop-inline">' . $candidate['person']['name'] . '</h4>';
						if ($person['more_united'] == 1 and isset($person['more_united_link'])) {
							$return .= '<a href="' . $person['more_united_link'] . '" target="_blank">';
								$return .= '<img class="more-united-logo" src="' . WP_PLUGIN_URL . '/wp-citizns-dc/images/more-united-logo.svg">';
							$return .= '</a>';
						}
						if ($person['best_for_britian'] == 1) {
							$return .= '<a href="https://bestforbritain.org" target="_blank">';
								$return .= '<img class="best-for-britain-badge" src="' . WP_PLUGIN_URL . '/wp-citizns-dc/images/best-for-britain-badge.png">';
							$return .= '</a>';
						}
					$return .= '</div>';
					$return .= '<div class="candidate-photo">';
						if (isset($person['image'])) {
							$return .= '<img src="' . $person['image'] . '" class="img-rounded">';
						}
					$return .= '</div>';
					$return .= '<div class="candidate-info">';
						$return .= '<div class="inner-heading">';
							$return .= '<div class="rosette theme-dc-' . str_replace(':', '', $candidate['on_behalf_of']['id']) . '">';
								$return .= '<div class="fa fa-bookmark"></div>';
							$return .= '</div>';
							$return .= '<h4 class="drop-inline">' . $candidate['person']['name'] . '</h4>';
							if ($person['more_united'] == 1 and isset($person['more_united_link'])) {
								$return .= '<a href="' . $person['more_united_link'] . '" target="_blank">';
									$return .= '<img class="more-united-logo" src="' . WP_PLUGIN_URL . '/wp-citizns-dc/images/more-united-logo.svg">';
								$return .= '</a>';
							}
							if ($person['best_for_britian'] == 1) {
								$return .= '<a href="https://bestforbritain.org" target="_blank">';
									$return .= '<img class="best-for-britain-badge" src="' . WP_PLUGIN_URL . '/wp-citizns-dc/images/best-for-britain-badge.png">';
								$return .= '</a>';
							}
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
	}
		$return .= '</div>';
	$return .= '</div>';

	$return .= '<div class="modal fade" id="candidatesModal" tabindex="-1" role="dialog" style="display: none;">';
		$return .= '<div class="modal-dialog" role="document">';
				$return .= '<div class="modal-content theme-citiznsdc-modal">';
					$return .= '<div class="modal-header">';
						$return .= '<button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">×</span></button>';
						$return .= '<h4 class="modal-title">Candidates</h4>';
					$return .= '</div>';
					$return .= '<div class="modal-body">';
						$return .= '<div>';
						$return .= '<img class="more-united-logo" src="' . WP_PLUGIN_URL . '/wp-citizns-dc/images/more-united-logo.svg">';
						$return .= ' <p class="drop-inline">identifies candidates supported by <a href="http://www.moreunited.uk/about" target="_blank">More United</a> because they align with their values: Opportunity, Tolerance, Democracy, Environment, Openness.<p>';
						$return .= '</div>';
						$return .= '<div>';
						$return .= '<img class="best-for-britain-badge" src="' . WP_PLUGIN_URL . '/wp-citizns-dc/images/best-for-britain-badge.png">';
						$return .= ' <p class="drop-inline">identifies candidates supported by <a href="https://bestforbritain.org/about" target="_blank">Best for Britain</a> because they campaign for a meaningful vote on the future relationship with Europe and who will be prepared to reject anything which leaves Britain worse off.<p>';
						$return .= '</div>';
					$return .= '</div>';
				$return .= '</div>';
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
			if (strcasecmp($data['gender'], "male") == 0) {
				$results['image'] = WP_PLUGIN_URL . "/wp-citizns-dc/images/placeholderM.jpg";
			} else if (strcasecmp($data['gender'], "female") == 0) {
				$results['image'] = WP_PLUGIN_URL . "/wp-citizns-dc/images/placeholderW.jpg";
			} else {
				$results['image'] = WP_PLUGIN_URL . "/wp-citizns-dc/images/placeholderU.jpg";
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
		
		// fetch extra info
		$extra_info = citiznsdc_fetch_person_extra_info($person_id);
		if (!empty($extra_info)) {
			$results['more_united'] = $extra_info['more_united_candidate'];
			if ($results['more_united'] == 1) {
				$results['more_united_link'] = $extra_info['more_united_link'];
			}
			$results['best_for_britian'] = $extra_info['best_for_brit_candidate'];
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


function citiznsdc_fetch_constituency($gss, $wmc_code) {
	$last_election = "2015";

	global $dcposts_client;
	$response = $dcposts_client->get($wmc_code . '/', [
		'headers' => [
			'Accept' => 'application/json'
		]
	]);
	
	$data = json_decode($response->getBody(), true);

	$return = '<div class="media">';
	$return .= '<div class="media-header theme-citiznsdc-header">';
	$return .= '<div class="media-heading">';
	$return .= '<div class="fa fa-map-marker"></div>';
	$return .= $data['area']['name'];
	$return .= '</div>';
	$return .= '</div>';
	
	$return .= '<div class="media-body">';

	$by_election = citiznsdc_fetch_by_election($wmc_code);
	if (!empty($by_election)) {
		$return .= '<h4>' . $by_election['year'] . ' By-Election Results</h4>';
		$return .= '<table class="table">';
		$return .= '<tbody>';
		$return .= '<tr>';
		$return .= '<td class="election-result-icon text-center drop-td">';
		$return .= '<div class="fa fa-trophy"></div>';
		$return .= '</td>';
		$return .= '<th class="election-result-header nowrap">Winner</th>';
		$return .= '<td class="election-result-value">';
		$return .= $by_election['elected_on_behalf_of'];
		$return .= '<span class="divider">|</span>';
		$return .= ($by_election['gain']) ? "Gain" : "Hold";
		$return .= '</td>';
		$return .= '</tr>';
		$return .= '<tr>';
		$return .= '<td class="election-result-icon text-center drop-td">';
		$return .= '<div class="fa fa-user"></div>';
		$return .= '</td>';
		$return .= '<th class="election-result-header nowrap">MP</th>';
		$return .= '<td class="election-result-value">' . $by_election['elected_name'] . '</td>';
		$return .= '</tr>';
		$return .= '<tr>';
		$return .= '<td class="election-result-icon text-center drop-td">';
		$return .= '<div class="fa fa-user"></div>';
		$return .= '</td>';
		$return .= '<th class="election-result-header nowrap">Turnout</th>';
		$return .= '<td class="election-result-value">' . $by_election['turnout_pct'] . '%</td>';
		$return .= '</tr>';
		$return .= '</tbody>';
		$return .= '</table>';
	}

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
				$return .= '<td class="election-result-icon text-center drop-td">';
				$return .= '<div class="fa fa-trophy"></div>';
				$return .= '</td>';
				$return .= '<th class="election-result-header nowrap">Winner</th>';
				$return .= '<td class="election-result-value">' . $candidate['on_behalf_of']['name'] . '</td>';
				$return .= '</tr>';
				$return .= '<tr>';
				$return .= '<td class="election-result-icon text-center drop-td">';
				$return .= '<div class="fa fa-user"></div>';
				$return .= '</td>';
				$return .= '<th class="election-result-header nowrap">MP</th>';
				$return .= '<td class="election-result-value">' . $candidate['person']['name'] . '</td>';
				$return .= '</tr>';
				$return .= '</tbody>';
				$return .= '</table>';
			}
		}
	}
	
	$eu_referendum = citiznsdc_fetch_eu_referendum($gss);
	if (!empty($eu_referendum)) {
		$return .= '<h4>2016 EU Referendum Results</h4>';
		$return .= '<table class="table">';
		$return .= '<tbody>';
		$return .= '<tr>';
		$return .= '<td class="election-result-icon text-center drop-td">';
		$return .= '<div class="fa fa-check-square-o"></div>';
		$return .= '</td>';
		$return .= '<th class="election-result-header nowrap">Decision</th>';
		if ($eu_referendum['leave_pct'] > $eu_referendum['remain_pct']) {
				$return .= '<td class="election-result-value">Leave (' . $eu_referendum['leave_pct'] . '%)</td>';
			} else {
				$return .= '<td class="election-result-value">Remain (' . $eu_referendum['remain_pct'] . '%)</td>';
			}
		$return .= '</tr>';
		$return .= '</tbody>';
		$return .= '</table>';
	}

	// media-body
	$return .= '</div>';
	
	// media
	$return .= '</div>';

	return $return;
}


//
// Custom db tables
//

global $by_election_table_name;
$by_election_table_name = 'citiznsdc_by_elections';

function citiznsdc_fetch_by_election($wmc_code) {
	global $wpdb;
	global $by_election_table_name;
	
	$table_name = $wpdb->prefix . $by_election_table_name;

	return $wpdb->get_row(
		$wpdb->prepare(
            "SELECT * FROM " . $table_name .
            " WHERE constituency = %s",
            $wmc_code
        ),
        ARRAY_A
    );
}

global $eu_referendum_table_name;
$eu_referendum_table_name = 'citiznsdc_eu_referendum';

function citiznsdc_fetch_eu_referendum($gss) {
	global $wpdb;
	global $eu_referendum_table_name;
	
	$table_name = $wpdb->prefix . $eu_referendum_table_name;

	return $wpdb->get_row(
		$wpdb->prepare(
            "SELECT * FROM " . $table_name .
            " WHERE constituency = %s",
            $gss
        ),
        ARRAY_A
    );
}

global $persons_extra_info_table_name;
$persons_extra_info_table_name = 'citiznsdc_persons_extra_info';

function citiznsdc_fetch_person_extra_info($person_id) {
	global $wpdb;
	global $persons_extra_info_table_name;
	
	$table_name = $wpdb->prefix . $persons_extra_info_table_name;
	
	return $wpdb->get_row(
		$wpdb->prepare(
            "SELECT * FROM " . $table_name .
            " WHERE dc_person_id = %s",
            $person_id
        ),
        ARRAY_A
    );
}



//
// Postcode search
//

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

// Mapit API helper
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


// store anonomous analytics for each postcode search
// the data stored is country the request originated from (based on IP) and
// the WMC code of the constituency associated with the postcode. 
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

//
// URL rewrite and query param translation
//

add_action('generate_rewrite_rules', 'add_rewrite_rules');
function add_rewrite_rules( $wp_rewrite ) {
	$new_rules = array(
		'wmc/?([0-9]{5})/gss/?([A-Z][0-9]{8})/?$' => 'index.php?pagename=' .
		get_option("citiznsdc_cand_slug") . '&wmc=$matches[1]&gss=$matches[2]'
	);
	
	// Add the rules to the top, to make sure they have priority
	$wp_rewrite -> rules = $new_rules + $wp_rewrite -> rules;
}


add_filter('query_vars', 'add_query_vars');
function add_query_vars( $public_query_vars ) {
	$public_query_vars[] = "wmc";
	$public_query_vars[] = "gss";
 
	return $public_query_vars;
}


function create_link($link, $link_text) {
	return "<a href=\"{$link}\" target=\"_blank\">{$link_text}</a>";
}


function dump($var) {
	echo "<pre>";
	var_dump($var);
	echo "</pre>";
}

//
// Settings menu
//

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
	global $wpdb;
	global $citiznsdc_db_version;

	$charset_collate = $wpdb->get_charset_collate();

	// citiznsdc_requests db table
	$table_name = $wpdb->prefix . 'citiznsdc_requests';
	if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
		// create table
		$sql = "CREATE TABLE $table_name (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			ts timestamp DEFAULT CURRENT_TIMESTAMP,
			source varchar(20) NOT NULL,
			constituency varchar(20) NOT NULL,
		
			PRIMARY KEY (id)
		) $charset_collate;";

		if ( !function_exists('dbDelta') ) {
			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		}

		dbDelta( $sql );

		add_option( 'citiznsdc_db_version', $citiznsdc_db_version );
	}
	
	// citiznsdc_by_elections db table
	global $by_election_table_name;
	$table_name = $wpdb->prefix . $by_election_table_name;
	if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
		// create table
		$sql = "CREATE TABLE $table_name (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			constituency varchar(10) NOT NULL,
			year varchar(4) NOT NULL,
			elected_name varchar(50) NOT NULL,
			dc_person_id mediumint NOT NULL,
			elected_on_behalf_of varchar(50) NOT NULL,
			dc_on_behalf_of_id mediumint NOT NULL,
			elected_with_votes mediumint NOT NULL,
			elected_with_pct decimal(3, 1) NOT NULL,
			majority mediumint NOT NULL,
			electorate mediumint NOT NULL,
			total_votes mediumint NOT NULL,
			turnout_pct decimal(3, 1) NOT NULL,
			gain tinyint(1) NOT NULL,
		
			PRIMARY KEY (id)
		) $charset_collate;";

		if ( !function_exists('dbDelta') ) {
			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		}

		dbDelta( $sql );
	}
	
	// citiznsdc_eu_referendum db table
	global $eu_referendum_table_name;
	$table_name = $wpdb->prefix . $eu_referendum_table_name;
	if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
		// create table
		$sql = "CREATE TABLE $table_name (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			constituency varchar(10) NOT NULL,
			leave_pct decimal(4, 2) NOT NULL,
			remain_pct decimal(4, 2) NOT NULL,
			is_estimate tinyint(1) NOT NULL,
		
			PRIMARY KEY (id)
		) $charset_collate;";

		if ( !function_exists('dbDelta') ) {
			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		}

		dbDelta( $sql );
	}

	// citiznsdc_persons_extra_info db table
	global $persons_extra_info_table_name;
	$table_name = $wpdb->prefix . $persons_extra_info_table_name;
	if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
		// create table
		$sql = "CREATE TABLE $table_name (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			dc_person_id mediumint NOT NULL,
			article_50_bill tinyint(1),
			more_united_candidate tinyint(1) NOT NULL,
			more_united_link varchar(200),
			best_for_brit_candidate tinyint(1) NOT NULL,
		
			PRIMARY KEY (id)
		) $charset_collate;";

		if ( !function_exists('dbDelta') ) {
			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		}

		dbDelta( $sql );
	}

	// default API base URIs
	global $default_mapit_buri;
   	add_option( 'citiznsdc_mapit_buri', $default_mapit_buri );
   	
   	global $default_posts_buri;
   	add_option( 'citiznsdc_posts_buri', $default_posts_buri );
   	
   	global $default_persons_buri;
   	add_option( 'citiznsdc_persons_buri', $default_persons_buri );
}
