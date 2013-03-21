<?php

class Feast_Post {
	public function __construct($post) {
		if (is_int($post))
			$post = get_post($post);

		$this->post = $post;
	}

	/**#@+
	 * @internal This is a shim for WP_Post, since WP_Post is idiotically declared final
	 */
	protected $post;

	public function __isset($key) {
		switch ($key) {
			default:
				return isset($this->post->$key);
		}
	}

	public function __get($key) {
		switch ($key) {
			default:
				return $this->post->$key;
		}
	}

	public function filter( $filter ) {
		return $this->post->filter($filter);
	}

	public function to_array() {
		return $this->post->to_array();
	}
	/**#@-*/
}