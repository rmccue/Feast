<?php

class Feast_Admin {
	public static function bootstrap() {
		Feast_Admin_Feeds::bootstrap();
		Feast_Admin_Items::bootstrap();

		add_action( 'admin_init', array( 'Feast_Import_OPML', 'bootstrap' ) );
	}
}