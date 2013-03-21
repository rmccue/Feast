<?php

class Feast_API extends Feast_Autohooker {
	public static function bootstrap() {
		parent::register_hooks();
	}

	/**
	 * Retrieve the route map
	 *
	 * The route map is an associative array with path regexes as the keys. The
	 * value is an indexed array with the callback function/method as the first
	 * item, and a bitmask of HTTP methods as the second item (see the class
	 * constants).
	 *
	 * Each route can be mapped to more than one callback by using an array of
	 * the indexed arrays. This allows mapping e.g. GET requests to one callback
	 * and POST requests to another.
	 *
	 * Note that the path regexes (array keys) must have @ escaped, as this is
	 * used as the delimiter with preg_match()
	 *
	 * @return array `'/path/regex' => array( $callback, $bitmask )` or `'/path/regex' => array( array( $callback, $bitmask ), ...)`
	 */
	public function getRoutes() {
		$endpoints = array(
			// Feed endpoints
			'/feeds'             => array(
				array( 'Feast_API::getFeeds', Feast_API_Router::READABLE ),
				array( 'Feast_API::newFeed',  Feast_API_Router::CREATABLE | Feast_API_Router::ACCEPT_JSON ),
			),

			'/feeds/(?P<id>\d+)' => array(
				array( 'Feast_API::getFeed',    Feast_API_Router::READABLE ),
				array( 'Feast_API::editFeed',   Feast_API_Router::EDITABLE | Feast_API_Router::ACCEPT_JSON ),
				array( 'Feast_API::deleteFeed', Feast_API_Router::DELETABLE ),
			),

			'/feeds/(?P<feed>\d+)/items' => array(
				array( 'Feast_API::getItems', Feast_API_Router::READABLE ),
			),

			'/feeds/(?P<feed>\d+)/items/(?P<id>\d+)' => array(
				array( 'Feast_API::getItem', Feast_API_Router::READABLE ),
			),

			// Item endpoints
			'/items'             => array(
				array( 'Feast_API::getItems', Feast_API_Router::READABLE ),
			),

			'/items/(?P<id>\d+)' => array(
				array( 'Feast_API::getItem',    Feast_API_Router::READABLE ),
				array( 'Feast_API::editItem',   Feast_API_Router::EDITABLE | Feast_API_Router::ACCEPT_JSON ),
				array( 'Feast_API::deleteItem', Feast_API_Router::DELETABLE ),
			),
		);

		$endpoints = apply_filters( 'feast_json_endpoints', $endpoints );

		// Normalise the endpoints
		foreach ( $endpoints as $route => &$handlers ) {
			if ( count($handlers) <= 2 && isset( $handlers[1] ) && ! is_array( $handlers[1] ) ) {
				$handlers = array( $handlers );
			}
		}
		return $endpoints;
	}

	/**
	 * Route an API call to the correct handler
	 */
	public static function route( $path ) {
		header('Content-Type: application/json; charset=utf-8');

		$router = new Feast_API_Router();
		$router->routes = self::getRoutes();

		$router->serve_request( $path );
		die();
	}

	public static function getItem( $ID ) {
		$post = get_post($ID);

		$item = self::getItemData($post);

		return $item;
	}

	public static function getItems( $limit = 20, $start = 0, $page = 1, $feed = 0 ) {
		$args = array(
			'post_type' => Feast_Item::TYPE,
			'posts_per_page' => $limit,
			'offset' => $start,
			'paged' => $page,
		);

		if ( $feed > 0 ) {
			$args['post_parent'] = absint($feed);
		}

		$posts = get_posts( $args );

		$items = array();
		foreach ($posts as $item) {
			$items[ $item->ID ] = self::getItemData($item);
		}
		return $items;
	}

	public static function getItemData($post) {
		return array(
			'id' => $post->ID,
			'title' => $post->post_title,
			'timestamp' => strtotime($post->post_date),
			'feed_id' => (string) $post->post_parent,
			'permalink' => get_permalink( $post->ID ),
			'content' => $post->post_content,
			'author' => array(
				'name' => get_post_meta( $post->ID, '_feast_author_name', true ),
				'url' => get_post_meta( $post->ID, '_feast_author_url', true ),
			),
		);
	}

	public static function getFeeds( $limit = 40, $conditions = array() ) {
		$posts = get_posts(array(
			'post_type' => Feast_Feed::TYPE
		));

		$feeds = array();
		foreach ($posts as $feed) {
			$icon_id = get_post_meta( $feed->ID, '_feast_icon', true);
			$feeds[ $feed->ID ] = array(
				'id' => $feed->ID,
				'title' => $feed->post_title,
				'icon' => wp_get_attachment_url( $icon_id ),
				'url' => get_permalink( $feed->ID ),
			);
		}
		return $feeds;
	}
}