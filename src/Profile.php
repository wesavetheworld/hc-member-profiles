<?php

namespace MLA\Commons;

use \BP_Component;
use \RecursiveDirectoryIterator;
use \RecursiveIteratorIterator;
use \WP_CLI;

class Profile extends BP_Component {

	/**
	 * Used by MLA\Commons\Profile\Migration when creating the xprofile group this plugin uses.
	 * THERE CAN ONLY BE ONE GROUP WITH THIS NAME AND DESCRIPTION, OTHERWISE THIS PLUGIN WILL BE CONFUSED.
	 */
	const XPROFILE_GROUP_NAME = 'MLA Commons Profile';
	const XPROFILE_GROUP_DESCRIPTION = 'Created and used by the MLA Commons Profile plugin.';

	protected static $instance;

	public $plugin_dir;
	public $plugin_templates_dir;
	public $template_files;

	public function __construct() {
		$this->plugin_dir = \plugin_dir_path( __DIR__ . '/../..' );
		$this->plugin_templates_dir = \trailingslashit( $this->plugin_dir . 'templates' );
		$this->template_files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->plugin_templates_dir ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		if( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'profile', __NAMESPACE__ . '\Profile\CLI' );
		}

		\add_action( 'bp_init', [ $this, 'init' ] );
	}

	public static function get_instance() {
		return self::$instance = ( null === self::$instance ) ? new self : self::$instance;
	}

	/**
	 * TODO check if required plugins are active & throw warning or bail if not: follow, block
	 */
	public function init() {
		if ( ! \bp_is_user_change_avatar() && ( \bp_is_user_profile() || \bp_is_user_profile_edit() ) ) {
			\add_filter( 'load_template', [ $this, 'filter_load_template' ] );
			\add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
			\add_action( 'xprofile_updated_profile', [ $this, 'save_academic_interests' ] );
		}

		// disable buddypress friends component in favor of follow/block
		$this->disable_bp_component( 'friends' );
	}

	public function disable_bp_component( $component_name ) {
		$active_components = \bp_get_option( 'bp-active-components' );

		if ( isset( $active_components[$component_name] ) ) {
			unset( $active_components[$component_name] );
			\bp_update_option( 'bp-active-components', $active_components );
		}
	}

	public function enqueue_scripts() {
		\wp_enqueue_style( 'mla_commons_profile_main_css', \plugins_url() . '/profile/css/main.css' );
		\wp_enqueue_script( 'mla_commons_profile_main_js', \plugins_url() . '/profile/js/main.js' );
	}

	public function filter_load_template( $path ) {
		$their_slug = str_replace( \trailingslashit( STYLESHEETPATH ), '', $path );

		foreach( $this->template_files as $name => $object ){
			$our_slug = str_replace( $this->plugin_templates_dir, '', $name );

			if ( $our_slug === $their_slug ) {
				return $name;
			}
		}

		return $path;
	}

	public function save_academic_interests( $user_id ) {
		$tax = \get_taxonomy( 'mla_academic_interests' );

		// If array add any new keywords.
		if ( is_array( $_POST['academic-interests'] ) ) {
			foreach ( $_POST['academic-interests'] as $term_id ) {
				$term_key = \term_exists( $term_id, 'mla_academic_interests' );
				if ( empty( $term_key ) ) {
					$term_key = \wp_insert_term( \sanitize_text_field( $term_id ), 'mla_academic_interests' );
				}
				if ( ! \is_wp_error( $term_key ) ) {
					$term_ids[] = intval( $term_key['term_id'] );
				} else {
					error_log( '*****CAC Academic Interests Error - bad tag*****' . var_export( $term_key, true ) );
				}
			}
		}

		// Set object terms for tags.
		$term_taxonomy_ids = \wp_set_object_terms( $user_id, $term_ids, 'mla_academic_interests' );
		\clean_object_term_cache( $user_id, 'mla_academic_interests' );

		// Set user meta for theme query.
		\delete_user_meta( $user_id, 'academic_interests' );
		foreach ( $term_taxonomy_ids as $term_taxonomy_id ) {
			\add_user_meta( $user_id, 'academic_interests', $term_taxonomy_id, $unique = false );
		}
	}

}
