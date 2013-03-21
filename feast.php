<?php
/**
 * Plugin Name: Feast
 * Description: Hungry? Get your feed with Feast, a feed reader for your 'Press
 * Author: Ryan McCue
 * Author URI: http://ryanmccue.info/
 */

function feast_autoload($class) {
	if (strpos($class, 'Feast') !== 0)
		return;

	$file = array(
		__DIR__,
		'library',
	);
	$file = array_merge($file, explode('_', $class));
	$file = implode(DIRECTORY_SEPARATOR, $file) . '.php';

	if (file_exists($file))
		require_once $file;
}

function feast_bootstrap() {
	spl_autoload_register('feast_autoload');
	require_once(__DIR__ . '/library/Feast.php');
	Feast::$path = __DIR__;

	Feast::bootstrap();
}

add_action('plugins_loaded', 'feast_bootstrap');

function feast_activate() {
	spl_autoload_register('feast_autoload');
	require_once(__DIR__ . '/library/Feast.php');
	Feast::$path = __DIR__;

	Feast::activate();
}
register_activation_hook(__FILE__, 'feast_activate');

function feast_deactivate() {
	spl_autoload_register('feast_autoload');
	require_once(__DIR__ . '/library/Feast.php');
	Feast::$path = __DIR__;

	Feast::deactivate();
}
register_deactivation_hook(__FILE__, 'feast_deactivate');