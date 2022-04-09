<?php
/*
  Plugin Name: Gravity Forms Infusionsoft Add-On
  Plugin URI: http://www.gravityforms.com
  Description: Integrates Gravity forms with infusionsoft
  Version: 1.0
  Author: Ignitro
Author URI: http://www.ignitro.com
*/

define( 'GF_INFUSIONSOFT_ADDON_VERSION', '2.0' );

add_action( 'gform_loaded', array( 'GF_Infusionsoft_AddOn_Bootstrap', 'load' ), 5 );

class GF_Infusionsoft_AddOn_Bootstrap {

	public static function load() {

		if ( ! method_exists( 'GFForms', 'include_feed_addon_framework' ) ) {
			return;
		}

		require_once( 'class-gfInfusionsoftaddon.php' );

		GFAddOn::register( 'GFInfusionsoftAddOn' );
	}

}

function gf_infusionsoft_addon() {
	return GFInfusionsoftAddOn::get_instance();
}


/*add_filter( 'gform_countries', function ( $countries ) {
  $new_countries = array();

  foreach ( $countries as $country ) {
    $code                   = GF_Fields::get( 'address' )->get_country_code( $country );
    $new_countries[ $code ] = $country;
  }

  return $new_countries;
} );*/