<?php
/*
Plugin Name: BeerXML Shortcode
Plugin URI: http://wordpress.org/extend/plugins/beerxml-shortcode/
Description: Automatically insert and display beer recipes by linking to a BeerXML document. Now with <a href="https://wordpress.org/plugins/shortcode-ui/">Shortcake</a> integration!
Author: Derek Springer
Author URI: http://www.fivebladesbrewing.com/beerxml-plugin-wordpress/
Version: 0.7.1
License: GPL2 or later
Text Domain: beerxml-shortcode
*/

/**
 * Class wrapper for BeerXML shortcode
 */
class BeerXML_Shortcode {

	/**
	 * A simple call to init when constructed
	 */
	function __construct() {
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'init', array( $this, 'beer_style' ) );
	}

	/**
	 * BeerXML initialization routines
	 */
	function init() {
		// I18n
		load_plugin_textdomain(
			'beerxml-shortcode',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

		if ( ! defined( 'BEERXML_URL' ) ) {
			define( 'BEERXML_URL', plugin_dir_url( __FILE__ ) );
		}

		if ( ! defined( 'BEERXML_PATH' ) ) {
			define( 'BEERXML_PATH', plugin_dir_path( __FILE__ ) );
		}

		if ( ! defined( 'BEERXML_BASENAME' ) ) {
			define( 'BEERXML_BASENAME', plugin_basename( __FILE__ ) );
		}

		require_once( BEERXML_PATH . '/includes/mime.php' );
		if ( is_admin() ) {
			require_once( BEERXML_PATH . '/includes/admin.php' );
		}

		require_once( BEERXML_PATH . '/includes/classes.php' );

		add_shortcode( 'beerxml', array( $this, 'beerxml_shortcode' ) );
	}

	/**
	 * Register Custom Taxonomy for Beer Style
	 */
	function beer_style() {
		$labels = array(
			'name'                       => __( 'Beer Styles', 'beerxml-shortcode' ),
			'singular_name'              => __( 'Beer Style', 'beerxml-shortcode' ),
			'menu_name'                  => __( 'Beer Style', 'beerxml-shortcode' ),
			'all_items'                  => __( 'All Beer Styles', 'beerxml-shortcode' ),
			'parent_item'                => __( 'Parent Beer Style', 'beerxml-shortcode' ),
			'parent_item_colon'          => __( 'Parent Beer Style:', 'beerxml-shortcode' ),
			'new_item_name'              => __( 'New Beer Style Name', 'beerxml-shortcode' ),
			'add_new_item'               => __( 'Add New Beer Style', 'beerxml-shortcode' ),
			'edit_item'                  => __( 'Edit Beer Style', 'beerxml-shortcode' ),
			'update_item'                => __( 'Update Beer Style', 'beerxml-shortcode' ),
			'separate_items_with_commas' => __( 'Separate beer styles with commas', 'beerxml-shortcode' ),
			'search_items'               => __( 'Search Beer Styles', 'beerxml-shortcode' ),
			'add_or_remove_items'        => __( 'Add or remove beer styles', 'beerxml-shortcode' ),
			'choose_from_most_used'      => __( 'Choose from the most used beer styles', 'beerxml-shortcode' ),
			'not_found'                  => __( 'Not Found', 'beerxml-shortcode' ),
		);

		$args = array(
			'labels'                     => $labels,
			'hierarchical'               => false,
			'public'                     => true,
			'show_ui'                    => true,
			'show_admin_column'          => true,
			'show_in_nav_menus'          => true,
			'show_tagcloud'              => true,
			'update_count_callback' 	 => '_update_post_term_count',
			'query_var'					 => true,
			'rewrite'           		 => array( 'slug' => 'beer-style' ),
		);

		register_taxonomy( 'beer_style', 'post', $args );
	}

	/**
	 * Shortcode for BeerXML
	 * [beerxml
	 * 		recipe=http://example.com/wp-content/uploads/2012/08/bowie-brown.xml
	 * 		cache=10800
	 * 		metric=true
	 * 		download=true
	 * 		style=true
	 * 		mash=true
	 * 		fermentation=true]
	 *
	 * @param  array $atts shortcode attributes
	 *                     recipe - URL to BeerXML document
	 *                     cache - number of seconds to cache recipe
	 *                     metric - true  -> use metric values
	 *                              false -> use U.S. values
	 *                     download - true -> include link to BeerXML file
	 *                     style - true -> include style details
	 *                     mash - true -> include mash details
	 *                     fermentation - true -> include fermentation details
	 * @return string HTML to be inserted in shortcode's place
	 */
	function beerxml_shortcode( $atts ) {
		global $post;

		if ( ! is_array( $atts ) ) {
			return '<!-- BeerXML shortcode passed invalid attributes -->';
		}

		if ( ! isset( $atts['recipe'] ) && ! isset( $atts[0] ) ) {
			return '<!-- BeerXML shortcode source not set -->';
		}

		extract( shortcode_atts( array(
			'recipe'       => null,
			'cache'        => get_option( 'beerxml_shortcode_cache', 60*60*12 ), // cache for 12 hours
			'metric'       => 2 == get_option( 'beerxml_shortcode_units', 1 ), // units
			'download'     => get_option( 'beerxml_shortcode_download', 1 ), // include download link
			'style'        => get_option( 'beerxml_shortcode_style', 1 ), // include style details
			'mash'         => get_option( 'beerxml_shortcode_mash', 1 ), // include mash details
			'misc'         => get_option( 'beerxml_shortcode_misc', 1 ), // include miscs details
			'fermentation' => get_option( 'beerxml_shortcode_fermentation', 0 ), // include fermentation details
			'mhop'         => get_option( 'beerxml_shortcode_mhop', 0 ), // display hops in metric
		), $atts ) );

		if ( ! isset( $recipe ) ) {
			$recipe = $atts[0];
		}

		$recipe = esc_url_raw( $recipe );
		$recipe_filename = pathinfo( $recipe, PATHINFO_FILENAME );
		$recipe_id = "beerxml_shortcode_recipe-{$post->ID}_{$recipe_filename}";

		$cache  = intval( esc_attr( $cache ) );
		if ( is_admin() || -1 == $cache ) { // clear cache if set to -1
			delete_transient( $recipe_id );
			$cache = 0;
		}

		$metric       = filter_var( esc_attr( $metric ), FILTER_VALIDATE_BOOLEAN );
		$download     = filter_var( esc_attr( $download ), FILTER_VALIDATE_BOOLEAN );
		$style        = filter_var( esc_attr( $style ), FILTER_VALIDATE_BOOLEAN );
		$mash         = filter_var( esc_attr( $mash ), FILTER_VALIDATE_BOOLEAN );
		$fermentation = filter_var( esc_attr( $fermentation ), FILTER_VALIDATE_BOOLEAN );
		$misc         = filter_var( esc_attr( $misc ), FILTER_VALIDATE_BOOLEAN );
		$mhop         = filter_var( esc_attr( $mhop ), FILTER_VALIDATE_BOOLEAN );

		if ( ! $cache || false === ( $beer_xml = get_transient( $recipe_id ) ) ) {
			$beer_xml = new BeerXML( $recipe );
		} else {
			// result was in cache, just use that
			return $beer_xml;
		}

		if ( ! $beer_xml->recipes ) { // empty recipe
			return '<!-- Error parsing BeerXML document -->';
		}

		/***************
		 * Recipe Details
		 **************/
		if ( $metric ) {
			$beer_xml->recipes[0]->batch_size = round( $beer_xml->recipes[0]->batch_size, 1 );
			$t_vol = __( 'L', 'beerxml-shortcode' );
		} else {
			$beer_xml->recipes[0]->batch_size = round( $beer_xml->recipes[0]->batch_size * 0.264172, 1 );
			$t_vol = __( 'gal', 'beerxml-shortcode' );
		}

		$btime = round( $beer_xml->recipes[0]->boil_time );
		$srm = round( $beer_xml->recipes[0]->est_color, 1);
		$eff = round( $beer_xml->recipes[0]->efficiency, 1);
		$t_details = __( 'Recipe Details', 'beerxml-shortcode' );
		$t_style   = __( 'Style', 'beerxml-shortcode' );
		$t_type   = __( 'Type', 'beerxml-shortcode' );
		$t_size    = __( 'Batch Size', 'beerxml-shortcode' );
		$t_time    = __( 'min', 'beerxml-shortcode' );
		$t_ibu     = __( 'IBU', 'beerxml-shortcode' );
		$t_srm     = __( 'SRM', 'beerxml-shortcode' );
		$t_og      = __( 'Est. OG', 'beerxml-shortcode' );
		$t_fg      = __( 'Est. FG', 'beerxml-shortcode' );
		$t_abv     = __( 'ABV', 'beerxml-shortcode' );
		$t_eff     = __( 'Efficiency', 'beerxml-shortcode' );
		$t_boil    = __( 'Boil Time', 'beerxml-shortcode' );

		// cleanup any extra text the 'est' might add
		$est_og = preg_split( '/\s/', trim( $beer_xml->recipes[0]->est_og ) );
		$est_og = $est_og[0];
		$est_fg = preg_split( '/\s/', trim( $beer_xml->recipes[0]->est_fg ) );
		$est_fg = $est_fg[0];
		$details = <<<DETAILS
		<div class='beerxml-details'>
			<h3>$t_details</h3>
			<ul>
				<li>$t_style: {$beer_xml->recipes[0]->style->name}</li>
				<li>$t_size: {$beer_xml->recipes[0]->batch_size} $t_vol</li>
				<li>$t_type: {$beer_xml->recipes[0]->type}</li>
				<li>$t_ibu: {$beer_xml->recipes[0]->ibu}</li>
				<li>$t_srm: $srm</li>
				<li>$t_og: $est_og</li>
				<li>$t_fg: $est_fg</li>
				<li>$t_abv: {$beer_xml->recipes[0]->est_abv}</li>
				<li>$t_eff: $eff%</li>
				<li>$t_boil: $btime $t_time</li>
			</ul>
		</div>
DETAILS;

		/***************
		 * Style Details
		 **************/
		$style_details = '';
		if ( $style && $beer_xml->recipes[0]->style ) {
			$t_style = __( 'Style Details', 'beerxml-shortcode' );
			$style_details = <<<STYLE
			<div class='beerxml-style'>
				<h3>$t_style</h3>
				<ul>
					{$this->build_style( $beer_xml->recipes[0]->style )}
				</ul>
			</div>
STYLE;
		}

		/***************
		 * Fermentables Details
		 **************/
		$fermentables = '';
		$total = BeerXML_Fermentable::calculate_total( $beer_xml->recipes[0]->fermentables );
		foreach ( $beer_xml->recipes[0]->fermentables as $fermentable ) {
			$fermentables .= $this->build_fermentable( $fermentable, $total, $metric );
		}

		$t_fermentables = __( 'Fermentables', 'beerxml-shortcode' );
		$t_amount = __( 'Amount', 'beerxml-shortcode' );
		$fermentables = <<<FERMENTABLES
		<div class='beerxml-fermentables'>
			<h3>$t_fermentables</h3>
			<ul>
				$fermentables
			</ul>
		</div>
FERMENTABLES;

		/***************
		 * Hops Details
		 **************/
		$hops = '';
		if ( $beer_xml->recipes[0]->hops ) {
			foreach ( $beer_xml->recipes[0]->hops as $hop ) {
				$hops .= $this->build_hop( $hop, $metric || $mhop );
			}

			$t_hops  = __( 'Hops', 'beerxml-shortcode' );
			$hops = <<<HOPS
			<div class='beerxml-hops'>
				<h3>$t_hops</h3>
				<ul>
					$hops
				</ul>
			</div>
HOPS;
		}

		/***************
		 * Miscs
		 **************/
		$miscs = '';
		if ( $misc && $beer_xml->recipes[0]->miscs ) {
			foreach ( $beer_xml->recipes[0]->miscs as $misc ) {
				$miscs .= $this->build_misc( $misc, $metric );
			}

			$t_miscs = __( 'Miscs', 'beerxml-shortcode' );
			$t_type = __( 'Type', 'beerxml-shortcode' );
			$miscs = <<<MISCS
			<div class='beerxml-miscs'>
				<h3>$t_miscs</h3>
				<ul>
					$miscs
				</ul>
			</div>
MISCS;
		}

		/***************
		 * Yeast Details
		 **************/
		$yeasts = '';
		if ( $beer_xml->recipes[0]->yeasts ) {
			foreach ( $beer_xml->recipes[0]->yeasts as $yeast ) {
				$yeasts .= $this->build_yeast( $yeast, $metric );
			}

			$t_yeast = __( 'Yeast', 'beerxml-shortcode' );
			$yeasts = <<<YEASTS
			<div class='beerxml-yeasts'>
				<h3>$t_yeast</h3>
				<ul>
					$yeasts
				</ul>
			</div>
YEASTS;
		}

		/***************
		 * Mash Details
		 **************/
		$mash_details = '';
		if ( $mash ) {
			if ( $beer_xml->recipes[0]->mash->mash_steps ) {
				foreach ( $beer_xml->recipes[0]->mash->mash_steps as $mash_step ) {
					$mash_details .= $this->build_mash( $mash_step, $metric );
				}

				$t_mash           = __( 'Mash', 'beerxml-shortcode' );
				$ph = round( $beer_xml->recipes[0]->mash->ph, 1 );
				$mash_details = <<<MASH
				<div class='beerxml-mash'>
					<h3>$t_mash</h3>
					<ul>
						<li>Target pH: $ph</li>
						$mash_details
					</ul>
				</div>
MASH;
			}
		}

		/***************
		 * Fermentation Details
		 **************/
		$fermentation_details = '';
		if ( $fermentation ) {
			$fermentation_steps = $this->build_fermentation_steps($beer_xml->recipes[0]);
			foreach ( $fermentation_steps as $fermentation_step ) {
				$fermentation_details .= $this->build_fermentation( $fermentation_step, $metric );
			}

			$t_fermentation           = __( 'Fermentation', 'beerxml-shortcode' );
			$fermentation_details = <<<FERMENTATION
			<div class='beerxml-fermentation'>
				<h3>$t_fermentation</h3>
				<ul>
					$fermentation_details
				</ul>
			</div>
FERMENTATION;
		}

		/***************
		 * Notes
		 **************/
		$notes = '';
		if ( $beer_xml->recipes[0]->notes ) {
			$t_notes = __( 'Notes', 'beerxml-shortcode' );
			$formatted_notes = preg_replace( '/\n/', '<br />', $beer_xml->recipes[0]->notes );
			$notes = <<<NOTES
			<div class='beerxml-notes'>
				<h3>$t_notes</h3>
				<p>$formatted_notes</p>
			</div>
NOTES;
		}

		/***************
		 * Download link
		 **************/
		$link = '';
		if ( $download ) {
			$t_download = __( 'Download', 'beerxml-shortcode' );
			$t_link = __( 'Download this recipe\'s BeerXML file', 'beerxml-shortcode' );
			$link = <<<LINK
			<div class="beerxml-download">
				<h3>$t_download</h3>
				<p><a href="$recipe" download="$recipe_filename">$t_link</a></p>
			</div>
LINK;
		}

		// stick 'em all together
		$html = <<<HTML
		<div class='beerxml-recipe'>
			$details
			$style_details
			$fermentables
			$hops
			$miscs
			$yeasts
			$mash_details
			$fermentation_details
			$notes
			$link
		</div>
HTML;

		if ( $cache && $beer_xml->recipes ) {
			set_transient( $recipe_id, $html, $cache );
		}

		return $html;
	}

	/**
	 * Build style row
	 * @param  BeerXML_Style 		$style fermentable to display
	 */
	static function build_style( $style ) {
		global $post;

		$t_name = __( 'Name', 'beerxml-shortcode' );
		$t_category = __( 'Cat.', 'beerxml-shortcode' );
		$t_og_range = __( 'OG Range', 'beerxml-shortcode' );
		$t_fg_range = __( 'FG Range', 'beerxml-shortcode' );
		$t_ibu_range = __( 'IBU', 'beerxml-shortcode' );
		$t_srm_range = __( 'SRM', 'beerxml-shortcode' );
		$t_carb_range = __( 'Carb', 'beerxml-shortcode' );
		$t_abv_range = __( 'ABV', 'beerxml-shortcode' );

		$category = $style->category_number . ' ' . $style->style_letter;
		$og_range = round( $style->og_min, 3 ) . ' - ' . round( $style->og_max, 3 );
		$fg_range = round( $style->fg_min, 3 ) . ' - ' . round( $style->fg_max, 3 );
		$ibu_range = round( $style->ibu_min, 1 ) . ' - ' . round( $style->ibu_max, 1 );
		$srm_range = round( $style->color_min, 1 ) . ' - ' . round( $style->color_max, 1 );
		$carb_range = round( $style->carb_min, 1 ) . ' - ' . round( $style->carb_max, 1 );
		$abv_range = round( $style->abv_min, 1 ) . ' - ' . round( $style->abv_max, 1 );

		// $catlist = get_the_terms( $post->ID, 'beer_style' );
		// $catlist = array_values( $catlist );
		// if ( $catlist && ! is_wp_error( $catlist ) ) {
		// 	$link = get_term_link( $catlist[0]->term_id, 'beer_style' );
		// 	$catname = "<a href='{$link}'>{$catlist[0]->name}</a>";
		// }

		return <<<STYLE
		<li>$t_name: {$style->name}</li>
		<li>$t_category: $category</li>
		<li>$t_og_range: $og_range</li>
		<li>$t_fg_range: $fg_range</li>
		<li>$t_ibu_range: $ibu_range</li>
		<li>$t_srm_range: $srm_range</li>
		<li>$t_carb_range: $carb_range</li>
		<li>$t_abv_range: $abv_range</li>
STYLE;
	}

	/**
	 * Build fermentable row
	 * @param  BeerXML_Fermentable  $fermentable fermentable to display
	 * @param  boolean $metric      true to display values in metric
	 * @return string               table row containing fermentable details
	 */
	static function build_fermentable( $fermentable, $total, $metric = false ) {
		$percentage = round( $fermentable->percentage( $total ), 1 );
		if ( $metric ) {
			if ( $fermentable->amount < 0.9995 ) {
				$fermentable->amount = round( $fermentable->amount * 1000, 0 );
				$t_weight = __( 'g', 'beerxml-shortcode' );
			} else {
				$fermentable->amount = round( $fermentable->amount, 1 );
				$t_weight = __( 'kg', 'beerxml-shortcode' );
			}
		} else {
			$fermentable->amount = $fermentable->amount * 2.20462;
			if ( $fermentable->amount < 0.995 ) {
				$fermentable->amount = round( $fermentable->amount * 16, 1 );
				$t_weight = __( 'oz', 'beerxml-shortcode' );
			} else {
				$fermentable->amount = round( $fermentable->amount, 2 );
				$t_weight = __( 'lbs', 'beerxml-shortcode' );
			}
		}

		return <<<FERMENTABLE
		<li>$fermentable->amount $t_weight ($percentage%) $fermentable->name</li>
FERMENTABLE;
	}

	/**
	 * Build hop row
	 * @param  BeerXML_Hop          $hop hop to display
	 * @param  boolean $metric      true to display values in metric
	 * @return string               table row containing hop details
	 */
	static function build_hop( $hop, $metric = false ) {
		if ( $metric ) {
			if ( $hop->amount < 0.9995 ) {
				$hop->amount = round( $hop->amount * 1000, 0 );
				$t_weight = __( 'g', 'beerxml-shortcode' );
			} else {
				$hop->amount = round( $hop->amount, 2 );
				$t_weight = __( 'kg', 'beerxml-shortcode' );
			}
		} else {
			$hop->amount = $hop->amount * 2.20462;
			if ( $hop->amount < 0.995 ) {
				$hop->amount = round( $hop->amount * 16, 1 );
				$t_weight = __( 'oz', 'beerxml-shortcode' );
			} else {
				$hop->amount = round( $hop->amount, 2 );
				$t_weight = __( 'lbs', 'beerxml-shortcode' );
			}
		}

		if ( $hop->time >= 1440 ) {
			$hop->time = round( $hop->time / 1440, 1);
			$t_time = _n( 'day', 'days', $hop->time, 'beerxml-shortcode' );
		} else {
			$hop->time = round( $hop->time );
			$t_time = __( 'min', 'beerxml-shortcode' );
		}

		$hop->alpha = round( $hop->alpha, 1 );

		return <<<HOP
		<li>$hop->amount $t_weight - $hop->name ($hop->alpha%) - $hop->use $hop->time $t_time</li>
HOP;
	}

	/**
	 * Build misc row
	 * @param  BeerXML_Misc         hop misc to display
	 * @return string               table row containing hop details
	 */
	static function build_misc( $misc, $metric = false ) {
		if ( $misc->time >= 1440 ) {
			$misc->time = round( $misc->time / 1440, 1);
			$t_time = _n( 'day', 'days', $misc->time, 'beerxml-shortcode' );
		} else {
			$misc->time = round( $misc->time );
			$t_time = __( 'min', 'beerxml-shortcode' );
		}

		$amount = '';
		if ( ! empty( $misc->display_amount ) ) {
			$amount = $misc->display_amount;
		} else {
			if ( $metric ) {
				$misc->amount = round( $misc->amount * 1000, 1 );
				$t_weight = __( 'g', 'beerxml-shortcode' );
			} else {
				$misc->amount = round( $misc->amount * 35.274, 1 );
				$t_weight = __( 'oz', 'beerxml-shortcode' );
			}

			$amount = "{$misc->amount} $t_weight";
		}

		return <<<MISC
		<li>$amount - $misc->name - $misc->type - $misc->use $misc->time $t_time</li>
MISC;
	}

	/**
	 * Build yeast row
	 * @param  BeerXML_Yeast        $yeast yeast to display
	 * @param  boolean $metric      true to display values in metric
	 * @return string               table row containing yeast details
	 */
	static function build_yeast( $yeast, $metric = false ) {

		// Would be nice to be able to list an amount (like "1 pkg" or "11.5 g")
		//	the BeerXML seems to just have an amount in ml
		$product_id = ! empty( $yeast->product_id ) ? " ({$yeast->product_id})" : '';
		return <<<YEAST
		<li>{$yeast->name}$product_id, {$yeast->laboratory}</li>
YEAST;
	}

	/**
	 * Build mash row
	 * @param  BeerXML_Mash_Step   $mash_details mash details to display
	 * @param  boolean $metric     true to display values in metric
	 * @return string              table row containing mash details
	 */
	static function build_mash( $mash_details, $metric = false ) {

		// parse and convert the infusion temp, comes with amount and units (eg "159 F")
		$inf_temp_arr = preg_split( '/\s/', trim( $mash_details->infuse_temp ) );
		$inf_temp = $inf_temp_arr[0];
		$inf_temp_unit = $inf_temp_arr[1];
		$inf_temp_f = ("F" == $inf_temp_unit);

		if ( $metric ) {
			$mash_details->step_temp = round( $mash_details->step_temp, 1 );
			$t_temp = __( 'C', 'beerxml-shortcode' );
			$volume = round( $mash_details->infuse_amount, 1 );
			$t_vol = __( 'L', 'beerxml-shortcode' );
			if ($inf_temp_f) {
				$inf_temp = round( ($inf_temp − 32) * (5/9), 1);
			}
		} else {
			$mash_details->step_temp = round( ( $mash_details->step_temp * (9/5) ) + 32, 1 );
			$t_temp = __( 'F', 'beerxml-shortcode' );
			$volume = round( $mash_details->infuse_amount * 0.264172, 2 );
			$t_vol = __( 'gal', 'beerxml-shortcode' );
			if (!$inf_temp_f) {
				$inf_temp = round( ( $inf_temp * (9/5) ) + 32, 1 );
			}
		}


		$mash_details->step_time = round( $mash_details->step_time );
		$t_minutes = __( 'min', 'beerxml-shortcode' );

		return <<<MASH
		<li>$mash_details->name
		 - {$mash_details->step_temp}&deg;$t_temp
		 - {$mash_details->step_time} $t_minutes
		 - $volume $t_vol @ $inf_temp $t_temp</li>
MASH;
	}

	/**
	 * Build fermentation array
	 * @param  BeerXML_Recipe       Main Recipe
	 * @return array                fermentation steps
	 */
	static function build_fermentation_steps( $recipe ) {
		$fermentation_stages = array();
		if ( 1 <= $recipe->fermentation_stages ) {
			$fermentation_stages[] = array(
				'name' => 'Stage #1',
				'time' => $recipe->primary_age,
				'temp' => $recipe->primary_temp
			);
		}

		if ( 2 <= $recipe->fermentation_stages ) {
			$fermentation_stages[] = array(
				'name' => 'Stage #2',
				'time' => $recipe->secondary_age,
				'temp' => $recipe->secondary_temp
			);
		}

		if ( 3 == $recipe->fermentation_stages ) {
			$fermentation_stages[] = array(
				'name' => 'Stage #3',
				'time' => $recipe->tertiary_age,
				'temp' => $recipe->tertiary_temp
			);
		}

		return $fermentation_stages;
	}

	/**
	 * Build fermentation row
	 * @param  array   $fermentation_details	fermentation steps to display
	 * @param  boolean $metric      			true to display values in metric
	 * @return string               			table row containing fermentation details
	 */
	static function build_fermentation( $fermentation_details, $metric = false ) {
		if ( $metric ) {
			$fermentation_details['temp'] = round( $fermentation_details['temp'], 1 );
			$t_temp = __( 'C', 'beerxml-shortcode' );
		} else {
			$fermentation_details['temp'] = round( ( $fermentation_details['temp'] * (9/5) ) + 32, 1 );
			$t_temp = __( 'F', 'beerxml-shortcode' );
		}

		$fermentation_details['time'] = round( $fermentation_details['time'] );
		$t_days = __( 'days', 'beerxml-shortcode' );
		return <<<FERMENTATION
		<li>{$fermentation_details['name']} - {$fermentation_details['time']} $t_days - {$fermentation_details['temp']}&deg;$t_temp</li>
FERMENTATION;
	}
}

// The fun starts here!
new BeerXML_Shortcode();
