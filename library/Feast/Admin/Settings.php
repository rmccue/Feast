<?php

class Feast_Admin_Settings extends Feast_Autohooker {
	public static function bootstrap() {
		self::register_hooks();
	}

	/**
	 * @wp-action admin_init
	 */
	public static function init() {
		register_setting( 'feast_options', 'feast_display_on_home', array(__CLASS__, 'validate_boolean') );

		add_settings_section('feast_options_display', 'Display Settings', array(__CLASS__, 'settings_section_display'), 'feast_options');
		add_settings_field('feast_options_display_on_home', 'Display on Home', array(__CLASS__, 'settings_field_display_on_home'), 'feast_options', 'feast_options_display');
	}

	/**
	 * Add our menu item
	 *
	 * @wp-action admin_menu
	 */
	public static function register_menu() {
		add_options_page(_x('Feast', 'page title', 'feast'), _x('Feast', 'menu title', 'feast'), 'manage_options', 'feast_options', array(__CLASS__, 'admin_page'));
	}

	/**
	 * Print the content
	 */
	public static function admin_page() {
	?>
		<div class="wrap">
			<h2><?php _e('Feast Options', 'feast') ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields('feast_options') ?>
				<?php do_settings_sections('feast_options') ?>
				<?php submit_button() ?>
			</form>
		</div>
	<?php
	}

	/**
	 * Print description for the main settings section
	 *
	 * @see self::init()
	 */
	public static function settings_section_display() {
		echo '<p>' . __('Control how Feast displays feeds and items on your site.', 'feast') . '</p>';
	}

	/**
	 * Print field for the Send to Author checkbox
	 *
	 * @see self::init()
	 */
	public static function settings_field_display_on_home() {
		$current = get_option('feast_display_on_home', false);

		echo '<label><input type="checkbox" name="feast_display_on_home" ' . checked($current, true, false) . ' /> ';
		_e('Show items as part of the blog post archive', 'feast');
		echo '</label>';
	}

	/**
	 * Validate boolean options (from checkbox)
	 *
	 * @param string $input
	 * @return string
	 */
	public static function validate_boolean($input) {
		return (bool) $input;
	}
}
