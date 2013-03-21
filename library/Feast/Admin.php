<?php

class Feast_Admin {
	public static function bootstrap() {
		Feast_Admin_Feeds::bootstrap();
		Feast_Admin_Items::bootstrap();
	}
}