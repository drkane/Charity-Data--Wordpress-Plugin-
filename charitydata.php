<?php
/*
Plugin name: Charity Data Display
Plugin uri: http://data.ncvo-vol.org.uk/projects/charity-data-display
Description: Allows information about a charity to be displayed on any Wordpress page, either in the post or as a widget, based on a pre-defined Charity Number, using a shortcode or based on a custom field. Uses data from <a href="http://opencharities.org/">Open Charities</a> - a new project to open up the UK Charities Register.
Author: David Kane
Author uri: http://drkane.co.uk/
Version: 0.1
*/

// include library of utilities
include_once ( "lib/drk_utils.php" );

/* Functions run when the plugin is activated and deactivated */

// Activation hook
// loads default options
register_activation_hook( __FILE__, 'charitydata_activate' );
function charitydata_activate() {
	$charitydata_default_options = array(
		"acknowledgement"=>'<div class="acknowledgement" style="font-size:smaller;text-align:right;"><small>Data from <a href="http://opencharities.org/charities/%%ccnum%%">OpenCharities</a></small><br><a href="http://opendefinition.org/" class="image_link"><img alt="This material is Open Data" src="http://m.okfn.org/images/ok_buttons/od_80x15_blue.png" /></a></div>',
		"source_url"=>"http://opencharities.org/charities/%%ccnum%%.json",
		"cache_interval"=>20,
		"default_charity"=>"",
		"templates"=>array(
			"basic"=>array("name"=>"Basic",
				"template"=>'{{<p><strong>Charity is no longer active</strong>. Removed on %%date_removed%%</p>}}
					<p>Name: %%title%%</p>
					<p>%%activities%%</p>
					{{<p><strong>Find out more</strong>: %%findoutmore%%</p>}}'),
			"full"=>array("name"=>"Full",
				"template"=>'{{<p><strong>Charity is no longer active</strong>. Removed on %%date_removed%%</p>}}
					<p>%%activities%%</p>
					<div>
						<h3>Financial Details</h3>
						<p>For year ending %%accounts_date%%</p>
						<p><strong>Latest income</strong>: &pound;%%income%%</p>
						<p><strong>Latest spending</strong>: &pound;%%spending%%</p>
						{{<p><strong>Latest employees</strong>: %%employees%%</p>}}
					</div>
				{{<p><strong>Find out more</strong>: %%findoutmore%%</p>}}')
			),				
		"custom_field"=>array("enabled"=>true,"name"=>"Charity Number"),
		"url_rewriting"=>array("enabled"=>true,"urltext"=>"charity","post"=>""),
		"widget"=>array("enabled"=>true),
		"shortcode"=>array("enabled"=>true),
		"stylesheet"=>true
	);
	update_option("charitydata_options", $charitydata_default_options);
	update_option("charitydata_cached", array() );
}

// on plugin deactivation - remove all the set options, including all the cached data
register_deactivation_hook( __FILE__, 'charitydata_deactivate' );
function charitydata_deactivate() {
	delete_option("charitydata_options");
	$charitydata_cached = get_option("charitydata_cached");
	foreach($charitydata_cached as $cached){
		delete_option("charitydata_cache_" . $cached);
	}
	delete_option("charitydata_cached");
}

/**
 *  The main class which fetches and displays data based on a charity number
 */
class charitydata {

	public $regno = ''; // stores the charity number
	public $country = 'gb'; // sets the country as GB (not yet used)
	public $data = array(); // will store the actual data
	public $errors = array(); // stores any errors found
	private $ready = false; // when the data has been found this becomes true
	private $source_url = ''; // set by options - usually opencharities
	private $data_type = 'json'; // set the data type to json (not changable yet)
	private $reset = false; //
	
	
	// when class is used, fetch options and if regno set then get data
	function __construct($regno=false) {
		$this->options = get_option("charitydata_options");
		if($regno){
			$this->set_charity($regno);
			$this->get_data();
		}
	}
	
	// set the charity to $regno
	public function set_charity($regno) {
		$this->regno = trim($regno);
	}
	
	// set country (not yet used)
	public function set_country($country) {
		$this->country = $country;
	}
	
	// set data type (not yet used)
	public function set_data_type($data_type) {
		$this->data_type = $data_type;
	}
	
	// set the source url used to get the data
	private function set_url() {
		$this->source_url = str_replace ( '%%ccnum%%' , $this->regno, $this->options['source_url'] );
	}
	
	/**
	 *  key function which loads data
	 *
	 *  Two possible ways - either the data is retrieved from a cache or direct from opencharities
	 */
	public function get_data($force_refresh=false) {
		if($this->regno!=''){
			$cache = get_option("charitydata_cache_" . $this->regno);
			if($cache&&!$force_refresh){ // if a cache exists, and we're not forcing a refresh of the data and...
				if(isset($cache["cached_time"])){ // ...if we know when the last cache was created
					$current_date = date_create();
					$cache_interval = new DateInterval("P" . $this->options['cache_interval'] . "D"); // find the interval after which the cache will be refreshed
					if(date_add($cache["cached_time"],$cache_interval)>$current_date){ // if the current date is less than the cache date + the interval then use the cache
						$this->data = $cache;
						$this->ready = true;
					}
				}
			}
			if(!$this->ready) { // if we're not using the cache data or it doesn't exist
				$this->set_url(); // get the URL we're using to look data up
				$this->data = file_get_contents($this->source_url); // get the data
				if($this->data ) { // data will be false if URL 404'd
					$this->data = json_decode($this->data,true); // decode the JSON data
					$this->ready = true; // we're ready to go
					$this->data = $this->data["charity"]; // set data
					$this->clean_data(); // clean the data
					$this->data["cached_time"] = date_create(); // add the time when we cached the data
					update_option("charitydata_cache_" . $this->regno, $this->data); // add the data to the cache
					$currently_cached = get_option("charitydata_cached"); // record that we have cached the data
					$currently_cached[$this->regno] = $this->regno;
					update_option( "charitydata_cached", $currently_cached );
				} else {
					$this->errors[] = "Charity could not be found";
				}
			}
		} else {
			$this->errors[] = "No charity number set";
		}
	}
	
	// Display the data
	public function display($template='small',$return_type="return") {
		$return = '';
		$template = drk_sluggify($template);
		if ( $this->ready ) {
			if ( isset ( $this->options['templates'][$template] ) ) { // if the template is set then apply it
				$return .= $this->apply_template ( $this->options['templates'][$template]['template'] , $this->data );
			} else { // otherwise apply a very basic one
				$return .= '<p>' . $this->data["activities"] . '</p>';
				$return .= '<p><strong>Latest income</strong>: &pound;' . drk_number_format($this->data["income"]) . '</p>';
				$return .= '<p><strong>Registered</strong>: ' . date("d/m/Y", strtotime($this->data["date_registered"])) . '</p>';
				$return .= '<p>Find out more: ' . $this->data["findoutmore"] . '</p>';
			}
		} else {
			$return = '<p>Charity Number not specified</p>';
		}
		$return .= $this->apply_template( $this->options['acknowledgement'] , $this->data );
		if($return_type=="echo"){
			echo $return;
			return true;
		} else {
			return $return;
		}
	}
	
	// Get a particular data item
	public function get($item){
		if(isset($this->data[$item])){
			return $this->data[$item];
		} else {
			return false;
		}
	}
	
	// Function which takes a given template and replaces the placeholders (%%field%%) with actual values
	private function apply_template($template, $fields){
		preg_match_all("/%%(.*?)%%/",$template,$matches); // find all the fields mentioned in the template
		
		$patterns_if = array(); // an array of possible fields to match
		$patterns = array(); // an array of possible fields to match
		$replacements_if = array(); // array of replacement values
		$replacements = array(); // array of replacement values
		
		foreach($matches[1] as $o){ // go through all the field matches found
			$patterns_if[] = "/{{(.*?)%%$o%%(.*?)}}/"; // work out the regular expression to match the field
			$patterns[] = "/%%$o%%/"; // work out the regular expression to match the field
			if(isset($fields[$o])){ // if the data item exists
				if(is_array($fields[$o])){ // if the data item is an array
					$fields[$o] = implode(";\n",$fields[$o]); // then implode it into one string
				}
				$replacements[] = $fields[$o]; // add the data value as a replacement
				if($fields[$o]!=""){
					$replacements_if[] = '${1}'.$fields[$o].'${2}';
				} else {
					$replacements_if[] = "";
				}
			} else { // otherwise replace with blank
				$replacements_if[] = "";
				$replacements[] = "";
			}
		}

		$text = preg_replace($patterns_if,$replacements_if,$template); // replace all the if values with the appropriate values
		$text = preg_replace($patterns,$replacements,$text); // replace all the fields with appropriate values
		
		return $text;
	}
	
	private function get_findoutmore($sep=" | ",$list=false) {
		$return = false;
		if($this->ready){
			$return = '';
			$findoutmore = array();
			if($this->data["website"]!=''){
				$findoutmore[] = '<a href="' . $this->data["website"] . '" target="_blank">Website</a>';
			}
			$findoutmore[] = '<a href="http://opencharities.org/charities/' . $this->regno . '" target="_blank">OpenCharities</a>';
			$findoutmore[] = '<a href="http://www.charitycommission.gov.uk/SHOWCHARITY/RegisterOfCharities/SearchResultHandler.aspx?RegisteredCharityNumber=' . $this->regno . '&SubsidiaryNumber=0" target="_blank">Charity Commission</a>';
			if($this->data["facebook_account_name"]!=''){
				$findoutmore[] = '<a href="http://facebook.com/' . $this->data["facebook_account_name"] . '" target="_blank">Facebook</a>';
			}
			if($this->data["twitter_account_name"]!=''){
				$findoutmore[] = '<a href="http://twitter.com/' . $this->data["twitter_account_name"] . '" target="_blank">Twitter</a>';
			}
			if($this->data["youtube_account_name"]!=''){
				$findoutmore[] = '<a href="http://youtube.com/' . $this->data["youtube_account_name"] . '" target="_blank">Youtube</a>';
			}
			if(count($findoutmore)>0){
				if($list){
					$return .= '<ul><li>';
					$sep = '</li><li>';
				}
				$return .= implode($sep, $findoutmore);
				if($list){
					$return .= '</li></ul>';
				}
			}
		}
		return $return;
	}
	
	private function get_trustees($sep= ", ") {
		$trustees = array();
		foreach ( $this->data["trustees"] as $trustee ) {
			$trustee = drk_proper_case ( $trustee["full_name"] , 2 );
			$trustees[] = $trustee;
		}
		return drk_implode ( $sep , $trustees );
	}
	
	private function get_classification( $type = 'CC', $sep= ", ") {
		$classifications = array();
		$types = array ( "CC"=>"CharityClassification", "ICNPO"=>"ICNPO" );
		foreach ( $this->data["classifications"] as $classification ) {
			if ( $classification["grouping"] == $types[$type] ) {
				$classification = drk_proper_case ( $classification["title"] , 2 );
				$classifications[] = $classification;
			}
		}
		return drk_implode ( $sep , $classifications );
	}
	
	private function get_finance( $type ) {
		$return = '';
		switch ( $type ) {
			case 'latest_assets':
			case 'latest_income':
			case 'latest_expenditure':
			case 'latest_employees':
			case 'latest_volunteers':
			case 'finance_breakdown_html':
				$latest_details = array ( "assets"=>"total_assets", "income"=>"total_income", "expenditure"=>"total_expenses", "employees"=>"employees", "volunteers"=>"volunteers" );
				$latest_report = array();
				$latest_date = false;
				foreach ( $this->data["annual_reports"] as $report ) {
					if ( $latest_date ) {
						if( $latest_date < strtotime( $report["financial_year_end"] ) ) {
							$latest_date = strtotime( $report["financial_year_end"] );
							$latest_report = $report;
						}
					} else {
						$latest_date = strtotime( $report["financial_year_end"] );
					}
				}
				$type_break = explode ( "_" , $type );
				if ( $type_break[0] = 'latest' && isset ( $latest_report[$latest_details[$type_break[1]]] ) ) {
					$return = drk_number_format ( $latest_report[$latest_details[$type_break[1]]] );
				} else {
					$return = '<table>';
					foreach ( $latest_report as $key=>$value ) {
						$return .= '<tr><th>' . $key . '</th><td>' . $value . '</td></tr>';
					}
					$return .= '</table>';
				}
				break;
			case 'finance_breakdown_history_html':
				$return = array();
				$values = array( array ( ) );
				$col = 1;
				foreach ( $this->data["annual_reports"] as $report ) {
					$row = 0;
					foreach ( $report as $key=>$value ) {
						$values[$row][0] = $key;
						$values[$row][$col] = $value;
						$row++;
					}
					$col++;
				}
				foreach ( $values as $value ) {
					$return[] = drk_implode ( "</td><td>", $value, "<tr><td>", "</td></tr>", "" ); 
				}
				$return = drk_implode ( "", $return, '</table>', '</table>' );
				break;
			case 'historic_finance_html':
				$return = array();
				foreach ( $this->data["accounts"] as $report ) {
					$return_text = '<tr>';
					$return_text .= '<td>';
					$return_text .= $report["accounts_date"];
					$return_text .= ' <a href="' . $report["accounts_url"] . '" title="Account PDF" target="_blank">[Accounts]</a>';
					if ( isset ( $report["sir_url"] ) ) {
						if ( $report["sir_url"] != "" ) {
							$return_text .= ' <a href="' . $report["sir_url"] . '" title="Account PDF" target="_blank">[SIR]</a>';
						}
					}
					$return_text .= '</td>';
					$return_text .= '<td style="text-align:right;">' . drk_number_format ( $report["income"] ) . '</td>';
					$return_text .= '<td style="text-align:right;">' . drk_number_format( $report["spending"] ) . '</td>';
					$return_text .= '</tr>';
					$return[] = $return_text;
				}
				$return = drk_implode ( "", $return, '<table><thead><tr><th>Date</th><th>Income (&pound;)</th><th>Expenditure (&pound;)</th></tr></thead><tbody>', '</tbody></table>' );
				break;
			case 'historic_finance_chart':
				break;
			default:
				break;
		} 
		return $return;
	}
	
	private function clean_data(){
		$new_data = array();
		
		// Charity information:
		$new_data["name"] = $new_data["title"] = drk_proper_case( $this->data["title"] );
		$new_data["ccnum"] = $new_data["regno"] = $new_data["charity_number"] = $this->regno;
		$new_data["activities"] = drk_proper_case( $this->data["activities"] , 1 );
		$new_data["company_number"] = $this->data["corrected_company_number"];
		$new_data["date_registered"] = $this->data["date_registered"];
		$new_data["date_removed"] = $this->data["date_removed"];
		$new_data["trustees"] = $this->get_trustees(", ");
		if ( $new_data["date_removed"] == "" ) {
			$new_data["removed"] = "";
		} else {
			$new_data["removed"] = "Charity removed";
		}
		
		// Contact and geography:
		$new_data["street_address"] = drk_proper_case( $this->data["address"]["street_address"] , 0 );
		$new_data["postcode"] = strtoupper( $this->data["address"]["postcode"] );
		$new_data["locality"] = drk_proper_case( $this->data["address"]["locality"] , 0 );
		$new_data["phone"] = $this->data["telephone"];
		$new_data["fax"] = $this->data["fax"];
		$new_data["latlng"] = drk_implode ( ",", array ( $this->data["lat"], $this->data['lng'] ) );
		
		// Web presence:
		$new_data["web"] = $new_data["website"] = $this->data["website"];
		$new_data["opencharities"] = 'http://opencharities.org/charities/' . $this->regno;
		$new_data["charity_commission"] = $new_data["charitycommission"] = $new_data["cc"] = 'http://www.charitycommission.gov.uk/SHOWCHARITY/RegisterOfCharities/SearchResultHandler.aspx?RegisteredCharityNumber=' . $this->regno . '&SubsidiaryNumber=0';
		$new_data["facebook"] = 'http://facebook.com/' . $this->data["facebook_account_name"];
		$new_data["twitter"] = 'http://twitter.com/' . $this->data["twitter_account_name"];
		$new_data["youtube"] = 'http://youtube.com/' . $this->data["youtube_account_name"];
		$new_data["rss"] = $this->data["feed_url"];
		$new_data["findoutmore"] = $this->get_findoutmore(" | ");
		
		// Classification:
		$new_data["charity_classification"] = $this->get_classification("CC", ", ");
		$new_data["icnpo"] = $new_data["icnpo_classification"] = $this->get_classification("ICNPO", ", ");
		
		// Finances:
		$new_data["income"] = drk_number_format ( $this->data["income"] );
		$new_data["expenditure"] = $new_data["expend"] = $new_data["spending"] = drk_number_format ( $this->data["spending"] );
		$new_data["assets"] = $this->get_finance("latest_assets");
		$new_data["volunteers"] = drk_number_format ( $this->data["volunteers"] );
		$new_data["employees"] = $new_data["staff"] = drk_number_format ( $this->data["employees"] );
		$new_data["fye"] = $new_data["fyend"] = $new_data["financial_year_end"] = $this->data["accounts_date"];
		$new_data["historic_finance_html"] = $this->get_finance("historic_finance_html");
		$new_data["historic_finance_chart"] = $this->get_finance("historic_finance_chart");
		$new_data["finance_breakdown_html"] = $this->get_finance("finance_breakdown_html");
		$new_data["finance_breakdown_history_html"] = $this->get_finance("finance_breakdown_history_html");
	
		// overwrite data with cleaned data
		$this->data = $new_data;
	}

}

function quick_display($regno){
	$charity = new charitydata;
	$charity->set_charity($regno);
	$charity->display('small',"echo");
}

global $charitydata_options;
$charitydata_options = get_option("charitydata_options");

if($charitydata_options['widget']['enabled']){

/* Add our function to the widgets_init hook. */
add_action( 'widgets_init', 'charitydata_load_widgets' );

/* Function that registers our widget. */
function charitydata_load_widgets() {
	register_widget( 'charitydata_widget' );
}

class charitydata_widget extends WP_Widget {
	public $charitydata_options = array();
	
	/** constructor */
	function __construct() {
		parent::WP_Widget(
			/* Base ID */'charitydata_widget', 
			/* Name */'Charity Data Widget', 
			array( 'description' => 'Displays data about a particular charity' ) 
		);
		global $charitydata_options;
		$this->charitydata_options = $charitydata_options;
	}

	/** @see WP_Widget::widget */
	function widget( $args, $instance ) {
		extract( $args );
		$regno = $instance["regno"];
		if($regno==""){
			if($this->charitydata_options["custom_field"]["enabled"]&&(is_page()||is_single())){
				$custom_fields = get_post_custom();
				if(isset($custom_fields[$this->charitydata_options["custom_field"]["name"]])){
					$regno = $custom_fields[$this->charitydata_options["custom_field"]["name"]];
				}
			} else {
				$regno = $this->charitydata_options["default_charity"];
			}
		}
		echo $before_widget;
		if($regno==""){
			echo $before_title . "Charity Search" . $after_title; ?>
			<form method="get" id="charity_search" action="<?php get_bloginfo('url'); ?>">
			<label class="assistive-text">Charity Number:</label>
			<input class="field" type="text" name="<?php echo $this->charitydata_options["url_rewriting"]["urltext"]; ?>" placeholder="Charity Number" />
			<input type="hidden" name="p" value="<?php echo $this->charitydata_options["url_rewriting"]["post"]; ?>" />
			<input type="submit" class="submit" value="search" style="display:none;" />
			</form>
		<?php } else {
			$charity = new charitydata($regno);
			echo $before_title . drk_proper_case($charity->get("title")) . " <small>(" . $charity->get("charity_number") . ")</small>" . $after_title;
			echo $charity->display($instance["template"]);
		}
		echo $after_widget;
	}

	function form($instance) {
		$options = get_option("charitydata_options");
		if ( $instance ) {
			$regno = esc_attr( $instance[ 'regno' ] );
			$template = esc_attr( $instance[ 'template' ] );
		}
		else {
			$regno = __( '', 'text_domain' );
			$template = __( '', 'text_domain' );
		}
		?>
		<p>
			<label for="<?php echo $this->get_field_id('regno'); ?>"><?php _e('Charity Number:'); ?></label> 
			<input class="widefat" id="<?php echo $this->get_field_id('regno'); ?>" name="<?php echo $this->get_field_name('regno'); ?>" type="text" value="<?php echo $regno; ?>" placeholder="Charity Number" /><br><span class="description">Leave blank to detect the charity number from a custom field</span>
		</p>
		<p>
			<label for="<?php echo $this->get_field_id('template'); ?>"><?php _e('Template to use:'); ?></label> 
			<select class="widefat" id="<?php echo $this->get_field_id('template'); ?>" name="<?php echo $this->get_field_name('template'); ?>">
				<?php foreach($this->charitydata_options['templates'] as $template_key=>$template_val): ?>
					<option value="<?php echo $template_key; ?>"<?php if($template_key==$template){ echo ' selected="selected"'; } ?>><?php echo $template_val['name']; ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php 
	}

	function update($new_instance, $old_instance) {
		$instance = $old_instance;
		$instance['regno'] = trim($new_instance['regno']);
		$instance['template'] = trim($new_instance['template']);
		return $instance;
	}

}

}

if(!function_exists("display_charitydata")){
function display_charitydata($regno, $type="small", $header="h2", $tag="div", $class="charity-data"){
	$charity = new charitydata($regno);
	$return_content = '';
	$return_content .= "<$tag class=\"$class\">";
	$return_content .= "<$header>" . drk_proper_case($charity->get("title")) . " <small>(" . $charity->get("charity_number") . ")</small></$header>";
	$return_content .= $charity->display($type);
	$return_content .= "</$tag>";
	return $return_content;
}
}

add_shortcode ('charity', 'charitydata_shortcode_handler');
function charitydata_shortcode_handler ($atts, $content=null ){
	global $charitydata_options;
	$atts = shortcode_atts( array(
		"ccnum" => '',
		"header" => 'h2',
		"tag" => 'div',
		"template" => 'small'
	) , $atts );
	$regno = $atts["ccnum"];
	if($regno==""){
		if(isset($_REQUEST["charity"])&&$charitydata_options["url_rewriting"]["enabled"] ){
			$regno = $_REQUEST["charity"];
		} else if($charitydata_options["custom_field"]["enabled"]) {
			$custom_fields = get_post_custom();
			if(isset($custom_fields[$charitydata_options["custom_field"]["name"]])){
				$regno = $custom_fields[$charitydata_options["custom_field"]["name"]];
			}
		} else {
			$regno = $charitydata_options["default_charity"];
		}
	}
	if($regno==""){
		$output = '<p>Enter a charity number to show details about a charity<p>';
		$output .= '<form method="get" id="charity_search" action="' . get_bloginfo('url') . '">';
		$output .= '<label class="assistive-text">Charity Number:</label>';
		$output .= '<input class="field" type="text" name="' . $charitydata_options["url_rewriting"]["urltext"] .'" placeholder="Charity Number" />';
		$output .= '<input type="hidden" name="p" value="' . $charitydata_options["url_rewriting"]["post"] . '" />';
		$output .= '<input type="submit" class="submit" value="search" />';
		$output .= '</form><br>';
	} else {
		$output = display_charitydata( $regno , $atts["template"], $atts["header"], $atts["tag"]);
	}
	return $output;
}

if($charitydata_options["stylesheet"]){
	add_action( 'wp_enqueue_scripts', 'charitydata_stylesheet' );
	function charitydata_stylesheet() {
		wp_register_style( 'charitydata', plugins_url('default-style.css', __FILE__) );
		wp_enqueue_style( 'charitydata' );
	}
}

function make_chart($account_input = array() , $inc_colour='224499', $spend_colour = '80C65A', $height = '175', $width = '300') {
	$income = $spending = $years = $income_label = $spending_label = array();
	foreach($account_input as $accounts){
		$income[] = $accounts["income"];
		$spending[] = $accounts["spending"];
		$years[] = date("Y", strtotime($accounts["accounts_date"]));
	}
	$multiplier = drk_find_multiplier(max(array_merge($income,$spending)));
	foreach($income as &$value){
		$value = $value / $multiplier;
	}
	foreach($spending as &$value){
		$value = $value / $multiplier;
	}
	$income = array_reverse($income);
	$spending = array_reverse($spending);
	$years = array_reverse($years);
	$max_value = max(array_merge($income,$spending));
	$return .= '<img src="http://chart.apis.google.com/chart';
	$return .= '?chds=0,'. $max_value .',0,'. $max_value;
	$return .= '&chxt=y,x';
	$return .= '&chbh=a,4,9';
	$return .= '&chs=' . $width . 'x' . $height;
	$return .= '&cht=bvg';
	$return .= '&chco=' . $inc_colour . ',' . $spend_colour;
	$return .= '&chd=t:'. implode(",",$income) . '|'. implode(",",$spending);
	$return .= '&chdl=Income+&pound;' . $multiplier["suffix"] . '|Spending+&pound;' . $multiplier["suffix"];
	$return .= '&chdlp=t';
	$return .= '&chxl=1:|'. implode("|",$years);
	$return .= '&chxr=0,0,' . $max_value;
	$return .= '&chm=';
	$count = 0;
	foreach($income as $inc){
		if($count>0){$return .= '|';}
		$return .= 't' . drk_number_format($inc,1) . ",000000,0,$count,10";
		$count++;
	}
	$count = 0;
	foreach($spending as $spend){
		$return .= '|';
		$return .= 't' . drk_number_format($spend,1) . ",000000,1,$count,10";
		$count++;
	}
	$return .= '" />';
	return $return;
}


add_action('admin_menu', 'charitydata_add_page_fn');
// Add sub page to the Settings Menu
function charitydata_add_page_fn() {
	add_options_page('Charity Data options', 'Charity Data', 'manage_options', __FILE__, 'charitydata_options_fn');
}

function charitydata_options_fn() {
?>
	<div class="wrap">
		<div class="icon32" id="icon-options-general"><br></div>
		<h2>Charity Data Options</h2>
		<form action="options.php" method="post">
		<?php settings_fields('charitydata_options'); ?>
		<?php do_settings_sections(__FILE__); ?>
		<p class="submit">
			<input name="Submit" type="submit" class="button-primary" value="<?php esc_attr_e('Save Changes'); ?>" />
		</p>
		</form>
	</div>
<?php
}

add_action('admin_init', 'charitydata_options_init_fn' );
// Register our settings. Add the settings section, and settings fields
function charitydata_options_init_fn(){
	register_setting('charitydata_options', 'charitydata_options', 'charitydata_validate_input' );
	add_settings_section('charitydata_main_section', 'Main Settings', 'charitydata_main_section_text_fn', __FILE__);
	add_settings_field('charitydata_shortcode', 'Shortcode', 'charitydata_shortcode_fn', __FILE__, 'charitydata_main_section');
	add_settings_field('charitydata_widget', 'Widgets', 'charitydata_widget_fn', __FILE__, 'charitydata_main_section');
	add_settings_field('charitydata_url_rewriting', 'URL rewriting', 'charitydata_url_rewriting_fn', __FILE__, 'charitydata_main_section');
	add_settings_field('charitydata_custom_field', 'Custom field', 'charitydata_custom_field_fn', __FILE__, 'charitydata_main_section');
	add_settings_field('charitydata_default_charity', 'Default charity', 'charitydata_default_charity_fn', __FILE__, 'charitydata_main_section');
	add_settings_field('charitydata_stylesheet', 'Stylesheet', 'charitydata_stylesheet_fn', __FILE__, 'charitydata_main_section');
	add_settings_section('charitydata_advanced', 'Advanced', 'template_section_text_fn', __FILE__);
	add_settings_field('charitydata_template', 'Templates', 'charitydata_template_fn', __FILE__, 'charitydata_advanced');
	add_settings_field('charitydata_source_url', 'Source URL', 'charitydata_source_url_fn', __FILE__, 'charitydata_advanced');
	add_settings_field('charitydata_cache_interval', 'Cache Interval (days)', 'charitydata_cache_interval_fn', __FILE__, 'charitydata_advanced');
	add_settings_field('charitydata_acknowledgement', 'Acknowledgement', 'charitydata_acknowledgement_fn', __FILE__, 'charitydata_advanced');
}

function charitydata_main_section_text_fn(){
	echo '';
}

function template_section_text_fn(){ ?>
	<p>HTML templates can be used to show data in a variety of forms. To include a particular field in the template, use %%field_name%%:</p>
	<pre class="code">	&lt;p&gt;The name of this charity is: %%title%%&lt;/p&gt;</pre>
	<p>To only include a field (and surrounding) if it is not blank, surround it with double curly quotes:</p>
	<pre class="code">	&lt;p&gt;{{This text will only appear if %%title%% is not blank.}} But this text will always appear.&lt;/p&gt;</pre>
<?php }

function charitydata_acknowledgement_fn() {
	$options = get_option('charitydata_options'); ?>
	<textarea id="charitydata_acknowledgement" name="charitydata_options[acknowledgement]" class="code" rows="7" cols="100" placeholder="Acknowledgement"><?php echo $options['acknowledgement']; ?></textarea><br>
	<span class="description"> This acknowledgement will be placed at the end every time the data is displayed. HTML can be used. Field names can be included in the same way as for templates (below).</span>
	<?php
}

function charitydata_source_url_fn() {
	$options = get_option('charitydata_options'); ?>
	<input type="text" id="charitydata_source_url" name="charitydata_options[source_url]" value="<?php echo $options['source_url']; ?>" class="regular-text code" placeholder="Source URL" /><br>
	<span class="description"> The URL for the source data. Use </span><span class="code"> %%ccnum%%</span><span class="description"> as a placeholder for the charity number. For example </span><span class="code">http://opencharities.org/charities/%%ccnum%%.json</span>
	<?php
}

function charitydata_default_charity_fn() {
	$options = get_option('charitydata_options'); ?>
	<input type="text" id="charitydata_default_charity" name="charitydata_options[default_charity]" value="<?php echo $options['default_charity']; ?>" class="regular-text" placeholder="Charity number" /><br>
	<span class="description">The charity number of the default charity, to be displayed if another is not selected.</span>
	<?php
}

function charitydata_cache_interval_fn() {
	$options = get_option('charitydata_options'); ?>
	<input type="number" id="charitydata_cache_interval" name="charitydata_options[cache_interval]" value="<?php echo $options['cache_interval']; ?>" placeholder="Days" min=0 max=10000 /><br>
	<span class="description"> The number of days that information about a charity should be cached before rechecking. Use zero if no caching should be performed.</span>
	<?php
}

function charitydata_stylesheet_fn() {
	$options = get_option('charitydata_options'); ?>
	<p><label><input type="checkbox" name="charitydata_options[stylesheet]" value="true" <?php if($options['stylesheet']){echo 'checked="checked" ';} ?>/> If checked, the included stylesheet will be applied. Uncheck to use your own custom styles.</label></p>
	<?php
}

function charitydata_template_fn() {
	$options = get_option('charitydata_options');
	$count = 0;
	if(isset($options['templates'])){
	$templates = $options['templates'];
	foreach($templates as $temp_key=>$template): ?>
		<input type="text" id="charitydata_template<?php echo $count; ?>_name" name="charitydata_options[templates][<?php echo $count; ?>][name]" value="<?php echo $template['name']; ?>" placeholder="Template name" class="regular-text" /><label><input type="checkbox" id="charitydata_template<?php echo $count; ?>_delete" name="charitydata_options[templates][<?php echo $count; ?>][delete]" value="true" /> Delete this template</label><br>
		<textarea id="charitydata_template<?php echo $count;?>_contents" rows="7" cols="100" name="charitydata_options[templates][<?php echo $count; ?>][template]" placeholder="Template contents" /><?php echo $template['template']; ?></textarea><br>
		<br><hr><br>
	<?php 
		$count++;
		endforeach; 
	} ?>
		<input type="text" id="charitydata_template<?php echo $count;?>_name" name="charitydata_options[templates][<?php echo $count; ?>][name]" placeholder="New template name" class="regular-text" /><br>
		<textarea id="charitydata_template<?php echo $count;?>_contents" rows="7" cols="100" name="charitydata_options[templates][<?php echo $count; ?>][template]" placeholder="Add new template" /></textarea>
	<?php 
}

function functionality_section_text_fn() { ?>
	<p>Select which parts of the plugin you want to enable<p>
	<?php
}

function charitydata_shortcode_fn() { 
	$options = get_option('charitydata_options'); ?>
		<span class="description"><p>This will allow a shortcode to be used in pages and posts which shows an info box about a charity.</p><p>The box can display information on one charity only (set in the shortcode, or using the default) or can automatically pick a charity number based on a custom field or on part of the URL.</p><p>The format for the shortcode is (all parameters are optional):</p></span>
		<pre class="code">[charity ccnum="123456" template="basic" tag="div" heading="h2"]</pre>
		<p><label><input type="checkbox" name="charitydata_options[shortcode][enabled]" value="true" <?php if($options['shortcode']['enabled']){echo 'checked="checked" ';} ?>/> Enable shortcode</label></p>
	
	<?php
}

function charitydata_widget_fn() { 
	$options = get_option('charitydata_options'); ?>
		<p><span class="description">Adds a widget to the sidebar which displays information about a charity. The box can display information on one charity only (set in the shortcode, or using the default) or can automatically pick a charity number based on a custom field or on part of the URL. If set to pick up an automatic charity number, when none is shown the widget will allow users to enter a charity number to look up.</span></p>
		<p><label><input type="checkbox" name="charitydata_options[widget][enabled]" value="true" <?php if($options['widget']['enabled']){echo 'checked="checked" ';} ?>/> Enable widget</label></p>
	
	
	<?php
}

function charitydata_url_rewriting_fn() { 
	$options = get_option('charitydata_options'); ?>
		<p><span class="description">[Only works if rewriting of permalinks is enabled] If enabled, allows you to produce a personalised page for every charity, with a URL such as </span><span class="code"><?php echo get_bloginfo('url'); ?>/charity/123456</span><span class="description">.</span></p>
		<p><label><input type="checkbox" name="charitydata_options[url_rewriting][enabled]" value="true" <?php if($options['url_rewriting']['enabled']){echo 'checked="checked" ';} ?>/> Enable URL rewriting</label></p>
		<p><label>Text used in url before the charity number: <input type="text" placeholder="charity" name="charitydata_options[url_rewriting][urltext]" value="<?php echo $options['url_rewriting']['urltext']; ?>" /></label></p>
		<p><label>ID of the post or page where the charity data will be displayed: <input type="text" placeholder="Post" name="charitydata_options[url_rewriting][post]" value="<?php echo $options['url_rewriting']['post']; ?>" /></label></p>
		<p><span class="description">This page must have an empty charity shortcode, eg </span><span class="code">[charity]</span><span class="description">.</span></p>
	
	
	<?php
}

function charitydata_custom_field_fn() { 
	$options = get_option('charitydata_options'); ?>
		<p><span class="description">Allows a custom field on a page to display which charity is shown in the widget or shortcode.</span></p>
		<p><label><input type="checkbox" name="charitydata_options[custom_field][enabled]" value="true" <?php if($options['custom_field']['enabled']){echo 'checked="checked" ';} ?>/> Enable custom field</label></p>
		<p><label>Name of custom field: <input type="text" placeholder="Charity Number" name="charitydata_options[custom_field][name]" value="<?php echo $options['custom_field']['name']; ?>" /></label></p>
	
	<?php
}

function charitydata_validate_input($input){

	$functionality = array("custom_field", "url_rewriting", "widget", "shortcode" );
	
	foreach($functionality as $f){
		if(isset($input[$f]['enabled'])){
			$input[$f]['enabled'] = true;
		} else {
			$input[$f]['enabled'] = false;
		}
	}
	
	if ( $input['url_rewriting']['enabled'] ) {
		global $wp_rewrite;
	   	$wp_rewrite->flush_rules();
	}
	
	$input['url_rewriting']['urltext'] = strtolower ( trim ( $input['url_rewriting']['urltext'] ) );
	$input['source_url'] = strtolower ( trim ( $input['source_url'] ) );
	
	$new_templates = array();
	foreach($input['templates'] as $template_key=>$template){
		if(!isset($template['delete'])){
			$slug = drk_sluggify( $template['name'] );
			if($slug!=""){
				$new_templates[$slug] = $template;
			}
		}
	}
	$input['templates'] = $new_templates;
	
	if(isset($input['stylesheet'])){
		$input['stylesheet'] = true;
	} else {
		$input['stylesheet'] = false;
	}
	
	if($input['default_charity']=="")
		$input['default_charity'] = false;
	
	if($input['cache_interval']==""||$input['cache_interval']=="0"){
		$input['cache_interval'] = false;
	} else {
		$input['cache_interval'] = $input['cache_interval'] * 1;
	}
	
	return $input;
}


add_action ( 'query_vars', 'charitydata_queryvars' );
function charitydata_queryvars ( $qvars ) {

	$options = get_option('charitydata_options');
	
	if ( isset ( $options['url_rewriting']['enabled'] ) ) {
	
		$qvars[] = $options['url_rewriting']['urltext'];
		
	}
	return $qvars;
}

/**
 * Add rewrite rules to include URL redirection.
 */	
add_filter ( 'generate_rewrite_rules', 'charitydata_dir_rewrite' );
if ( ! function_exists ( 'charitydata_dir_rewrite' ) ) {
function charitydata_dir_rewrite ( $wp_rewrite ) {

	$options = get_option('charitydata_options');
	
	if ( isset ( $options['url_rewriting']['enabled'] ) ) {
		if ( $options['url_rewriting']['enabled'] ) {
		
			// default options
			$url_rewrite = 'charity';
			$url_post = '1';
			
			// check if other options have been specified
			if( isset ( $options['url_rewriting']['urltext'] ) ) {
				if ( $options['url_rewriting']['urltext'] != "" ) {
					$url_rewrite = $options['url_rewriting']['urltext'];
				}
			}
			if( isset ( $options['url_rewriting']['post'] ) ) {
				if ( $options['url_rewriting']['post'] != "" ) {
					$url_post = $options['url_rewriting']['post'];
				}
			}
			
			$feed_rules = $wp_rewrite->non_wp_rules;
			
			// set the feed rules
			$feed_rules[$url_rewrite . '/([0-9]+)'] = 'index.php?p=' . $url_post . '&charity=$2';
			
			// apply feed rules
			$wp_rewrite->non_wp_rules = $feed_rules;
	
			return $wp_rewrite;
			
			// Permalinks need to be reset for them to work - usually by saving changes on the pretty permalinks page.
			
		}
	}
}
}