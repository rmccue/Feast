<?php

class Feast extends Feast_Autohooker {
	public static $path = '';

	public static $feed = null;
	public static $item = null;

	protected static $timezone = null;

	public static function bootstrap() {
		parent::register_hooks();

		// Default feed hooks
		add_action('feast_pre_insert_feed', array('Feast_Feed', 'prepareFeedData'), 100, 2);
		add_action('feast_create_feed', array('Feast_Feed', 'addFeedData'), 100, 2);

		// Default item hooks
		add_action('feast_create_item', array('Feast_Item', 'addItemData'), 100, 3);

		if (is_admin()) {
			Feast_Admin::bootstrap();
		}
		if ( defined('DOING_AJAX') && DOING_AJAX ) {
			Feast_API::bootstrap();
		}
	}

	public static function error_to_exception($wp_error) {
		return new Feast_Exception($wp_error->get_message(), $wp_error->get_code(), $wp_error->get_error_data(), $wp_error);
	}

	/**
	 * Activate Feast
	 *
	 * Runs installation routines as needed
	 */
	public static function activate() {
		self::setup_roles();

		wp_schedule_event( time(), 'hourly', 'feast_cron_update' );
	}

	public static function deactivate() {
		self::remove_roles();
		wp_clear_scheduled_hook( 'feast_cron_update' );
	}

	/**
	 * Register post types and taxonomies
	 *
	 * @wp-action init
	 */
	public static function register_types() {
		register_post_type(Feast_Feed::TYPE, array(
			'labels' => array(
				'name' => _x('Feeds', 'post type general name', 'feast'),
				'singular_name' => _x('Feed', 'post type singular name', 'feast'),
				'add_new' => _x('Add New', 'feed', 'feast'),
				'add_new_item' => __('Add New Feed', 'feast'),
				'edit_item' => __('Edit Feed', 'feast'),
				'new_item' => __('New Feed', 'feast'),
				'view_item' => __('View Feed', 'feast'),
				'search_items' => __('Search Feeds', 'feast'),
				'not_found' => __('No feeds found.', 'feast'),
				'not_found_in_trash' => __('No feeds found in Trash.', 'feast'),
				'all_items' => __( 'All Feeds', 'feast'),
			),
			'public'  => true,
			'show_ui' => true,
			'capability_type' => 'feast_feed',
			'map_meta_cap' => true,
			'hierarchical' => false,
			'has_archive' => true,
			'rewrite' => false,
			'supports' => array( 'title', /*'editor',*/ 'thumbnail', 'excerpt', 'custom-fields' ),
		));
		register_post_type(Feast_Item::TYPE, array(
			'labels' => array(
				'name' => _x('Items', 'post type general name', 'feast'),
				'singular_name' => _x('Item', 'post type singular name', 'feast'),
				'add_new' => _x('Add New', 'item', 'feast'),
				'add_new_item' => __('Add New Item', 'feast'),
				'edit_item' => __('Edit Item', 'feast'),
				'new_item' => __('New Item', 'feast'),
				'view_item' => __('View Item', 'feast'),
				'search_items' => __('Search Items', 'feast'),
				'not_found' => __('No items found.', 'feast'),
				'not_found_in_trash' => __('No items found in Trash.', 'feast'),
				'all_items' => _x( 'Items', 'all items name', 'feast'),
			),
			'public'  => true,
			'capability_type' => 'feast_item',
			'map_meta_cap' => true,
			'hierarchical' => false,
			'show_ui' => true,
			'show_in_menu' => 'edit.php?post_type=' . Feast_Feed::TYPE,
			'rewrite' => false,
			'supports' => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'comments' ),
		));
	}

	/**
	 * Add Feast post rewrite rules
	 *
	 * @wp-action init
	 */
	public static function add_rewrite_rules() {
		add_rewrite_rule('feast/feed/(\d+)/?$', 'index.php?post_type=' . Feast_Feed::TYPE . '&p=$matches[1]', 'top');
		add_rewrite_rule('feast/feed/(\d+)/items/(\d+)/?$', 'index.php?post_type=' . Feast_Item::TYPE . '&p=$matches[2]', 'top');
		add_rewrite_rule('feast/api(/.*)$', 'index.php?feast_api=$matches[1]', 'top');

		global $wp;
		$wp->add_query_var('feast_api');
	}

	/**
	 * Get the correct permalink for a Feast post type
	 *
	 * @wp-filter post_type_link
	 *
	 * @param string $link Generated permalink
	 * @param WP_Post $post Post object
	 * @param boolean $leavename Whether to keep post name or page name
	 * @param boolean $sample Is it a sample permalink?
	 * @return string Corrected permalink
	 */
	public static function get_permalink($link, $post, $leavename, $sample) {
		switch ($post->post_type) {
			case Feast_Feed::TYPE:
				$link = '/feast/feed/' . $post->ID . '/';
				return home_url($link);
			case Feast_Item::TYPE:
				$link = '/feast/feed/' . $post->post_parent . '/items/' . $post->ID . '/';
				return home_url($link);
			default:
				return $link;
		}
	}

	/**
	 * Create objects for the current post
	 *
	 * @wp-action the_post
	 * @param WP_Post $post Post data
	 */
	public static function the_post($post) {
		Feast::$feed = null;
		Feast::$item = null;

		switch ($post->post_type) {
			case Feast_Feed::TYPE:
				Feast::$feed = new Feast_Feed($post);
				break;
			case Feast_Item::TYPE:
				Feast::$feed = new Feast_Feed(get_post($post->post_parent));
				Feast::$item = new Feast_Item($post);
				break;
		}
	}

	/**
	 * Override an item's author
	 *
	 * @wp-filter the_author
	 * @wp-filter the_modified_author
	 *
	 * @param string $author Post author's name
	 * @return string Corrected author name
	 */
	public static function override_author($author) {
		global $post;
		if ( $post->post_type !== Feast_Item::TYPE )
			return $author;

		return get_post_meta( $post->ID, '_feast_author_name', true );
	}

	/**
	 * Override an item's author meta
	 *
	 * @wp-filter get_the_author_user_login
	 * @wp-filter get_the_author_user_nicename
	 * @wp-filter get_the_author_display_name
	 * @wp-filter get_the_author_user_email
	 * @wp-filter get_the_author_user_url
	 * @wp-filter get_the_author_description
	 */
	public static function override_author_meta( $value ) {
		global $post;
		if ( $post->post_type !== Feast_Item::TYPE )
			return $value;

		$author_name = get_post_meta( $post->ID, '_feast_author_name', true );
		$field = str_replace( 'get_the_author_', '', current_filter() );
		switch ($field) {
			case 'user_login':
			case 'user_nicename':
				return 'feast-' . sanitize_username( $author_name );

			case 'display_name':
				return $author_name;

			case 'user_email':
				return get_post_meta( $post->ID, '_feast_author_email', true );

			case 'user_url':
				return get_post_meta( $post->ID, '_feast_author_url', true );

			case 'description':
				return ' '; // non-empty
		}

		return $value;
	}

	/**
	 * Force the site to behave as a multi-author site
	 *
	 * @wp-filter is_multi_author
	 *
	 * @param bool $is_multi Is the site actually a multi-author site?
	 * @return bool Forced multi-author status
	 */
	public static function force_multi_author($is_multi) {
		return true;
	}

	/**
	 * Setup the Feast roles and capabilities
	 */
	public static function setup_roles() {
		global $wp_roles;

		if ( class_exists('WP_Roles') )
			if ( ! isset( $wp_roles ) )
				$wp_roles = new WP_Roles();

		if ( ! is_object( $wp_roles ) )
			return;

		$capabilities = self::get_capabilities();

		foreach( $capabilities as $cap_group ) {
			foreach( $cap_group as $cap ) {
				$wp_roles->add_cap( 'administrator', $cap );
			}
		}
	}

	/**
	 * Remove the Feast roles and capabilities
	 */
	public static function remove_roles() {
		global $wp_roles;

		if ( class_exists('WP_Roles') )
			if ( ! isset( $wp_roles ) )
				$wp_roles = new WP_Roles();

		if ( ! is_object( $wp_roles ) )
			return;

		$capabilities = self::get_capabilities();

		foreach( $capabilities as $cap_group ) {
			foreach( $cap_group as $cap ) {
				$wp_roles->remove_cap( 'administrator', $cap );
			}
		}
	}

	/**
	 * Get the feast-related capabilities
	 *
	 * @return array Multidimensional array
	 */
	public static function get_capabilities() {
		$capabilities = array();

		$capabilities['core'] = array(
			'manage_feast',
		);

		$capability_types = array( 'feast_feed', 'feast_item' );

		foreach( $capability_types as $capability_type ) {
			$capabilities[ $capability_type ] = array(
				// Post type
				"edit_{$capability_type}",
				"read_{$capability_type}",
				"delete_{$capability_type}",
				"edit_{$capability_type}s",
				"edit_others_{$capability_type}s",
				"publish_{$capability_type}s",
				"read_private_{$capability_type}s",
				"delete_{$capability_type}s",
				"delete_private_{$capability_type}s",
				"delete_published_{$capability_type}s",
				"delete_others_{$capability_type}s",
				"edit_private_{$capability_type}s",
				"edit_published_{$capability_type}s",

				// Terms
				"manage_{$capability_type}_terms",
				"edit_{$capability_type}_terms",
				"delete_{$capability_type}_terms",
				"assign_{$capability_type}_terms"
			);
		}

		return $capabilities;
	}

	/**
	 * Update all items
	 *
	 * @wp-action feast_cron_update
	 */
	public static function update_all_feeds() {
		$feeds = get_posts(array(
			'post_type' => Feast_Feed::TYPE,
			'showposts' => -1,
		));

		foreach ($feeds as $post) {
			$feed = new Feast_Feed($post);
			$feed->update();
		}
	}

	/**
	 * @wp-action template_redirect
	 */
	public static function hijack_template_redirect() {
		if ( ! get_query_var('feast_api') )
			return;

		$base = parse_url( site_url( '/feast/api' ), PHP_URL_PATH );

		$path = parse_url( str_replace($base, '', $_SERVER['REQUEST_URI']), PHP_URL_PATH );

		Feast_API::route( $path );
	}

	/**
	 * @wp-filter redirect_canonical
	 */
	public static function unredirect_canonical( $url, $requested ) {
		if ( ! get_query_var( 'feast_api' ) )
			return $url;

		return false;
	}

	public static function getTimezoneObject() {
		if ( !empty( self::$timezone ) )
			return self::$timezone;

		$current_offset = get_option('gmt_offset');
		$tzstring = get_option('timezone_string');

		$check_zone_info = true;

		// Remove old Etc mappings. Fallback to gmt_offset.
		if ( false !== strpos($tzstring,'Etc/GMT') )
			$tzstring = '';

		if ( empty($tzstring) ) { // Create a UTC+- zone if no timezone string exists
			$check_zone_info = false;
			if ( 0 == $current_offset )
				$tzstring = 'UTC';
			elseif ($current_offset < 0)
				$tzstring = 'UTC' . $current_offset;
			else
				$tzstring = 'UTC+' . $current_offset;
		}

		self::$timezone = new DateTimeZone($tzstring);
		return self::$timezone;
	}
}