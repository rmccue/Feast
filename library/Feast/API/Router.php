<?php

class Feast_API_Router {
	const METHOD_GET    = 1;
	const METHOD_POST   = 2;
	const METHOD_PUT    = 4;
	const METHOD_PATCH  = 8;
	const METHOD_DELETE = 16;

	const READABLE  = 1;  // GET
	const CREATABLE = 2;  // POST
	const EDITABLE  = 14; // POST | PUT | PATCH
	const DELETABLE = 16; // DELETE
	const ALLMETHODS = 31; // GET | POST | PUT | PATCH | DELETE

	/**
	 * Does the endpoint accept raw JSON entities?
	 */
	const ACCEPT_JSON = 128;

	/**
	 * Should we hide this endpoint from the index?
	 */
	const HIDDEN_ENDPOINT = 256;

	/**
	 * Map of HTTP verbs to constants
	 * @var array
	 */
	public static $method_map = array(
		'HEAD'   => self::METHOD_GET,
		'GET'    => self::METHOD_GET,
		'POST'   => self::METHOD_POST,
		'PUT'    => self::METHOD_PUT,
		'PATCH'  => self::METHOD_PATCH,
		'DELETE' => self::METHOD_DELETE,
	);

	/**
	 * Handle serving an API request
	 *
	 * Matches the current server URI to a route and runs the first matching
	 * callback then outputs a JSON representation of the returned value.
	 *
	 * @uses WP_JSON_Server::dispatch()
	 */
	public function serve_request( $path ) {
		header('Content-Type: application/json; charset=' . get_option('blog_charset'), true);

		$enabled = apply_filters( 'feast_json_enabled', true );
		$jsonp_enabled = apply_filters( 'feast_json_jsonp_enabled', true );

		if ( ! $enabled ) {
			echo $this->json_error( 'json_disabled', 'The JSON API is disabled on this site.', 405 );
			return false;
		}
		if ( isset($_GET['_jsonp']) ) {
			if ( ! $jsonp_enabled ) {
				echo $this->json_error( 'json_callback_disabled', 'JSONP support is disabled on this site.', 405 );
				return false;
			}

			// Check for invalid characters (only alphanumeric allowed)
			if ( preg_match( '/\W/', $_GET['_jsonp'] ) ) {
				echo $this->json_error( 'json_callback_invalid', 'The JSONP callback function is invalid.', 400 );
				return false;
			}
		}

		$method = $_SERVER['REQUEST_METHOD'];

		// Compatibility for clients that can't use PUT/PATCH/DELETE
		if ( isset( $_GET['_method'] ) ) {
			$method = strtoupper( $_GET['_method'] );
		}

		$result = $this->check_authentication();

		if ( ! is_wp_error($result)) {
			$result = $this->dispatch( $path, $method );
		}

		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( is_array( $data ) && isset( $data['status'] ) ) {
				status_header( $data['status'] );
			}

			$result = $this->error_to_array( $result );
		}

		if ( 'HEAD' === $method )
			return;

		if ( isset($_GET['_jsonp']) )
			echo $_GET['_jsonp'] . '(' . json_encode( $result ) . ')';
		else
			echo json_encode( $result );
	}

	/**
	 * Match the request to a callback and call it
	 *
	 * @param string $path Requested route
	 * @return mixed The value returned by the callback, or a WP_Error instance
	 */
	public function dispatch( $path, $method = self::METHOD_GET ) {
		switch ( $method ) {
			case 'HEAD':
			case 'GET':
				$method = self::METHOD_GET;
				break;

			case 'POST':
				$method = self::METHOD_POST;
				break;

			case 'PUT':
				$method = self::METHOD_PUT;
				break;

			case 'PATCH':
				$method = self::METHOD_PATCH;
				break;

			case 'DELETE':
				$method = self::METHOD_DELETE;
				break;

			default:
				return new WP_Error( 'json_unsupported_method', __( 'Unsupported request method' ), array( 'status' => 400 ) );
		}

		$matched = false;
		foreach ( $this->routes as $route => $handlers ) {
			$match = preg_match('@^' . $route . '$@i', $path, $args);

			if ( !$match )
				continue;

			$matched = true;

			foreach ($handlers as $handler) {
				$callback = $handler[0];
				$supported = isset( $handler[1] ) ? $handler[1] : self::METHOD_GET;

				if ( !( $supported & $method ) )
					continue;

				if ( ! is_callable($callback) )
					return new WP_Error( 'json_invalid_handler', __('The handler for the route is invalid'), array( 'status' => 500 ) );

				$args = array_merge( $args, $_GET );
				if ( $method & self::METHOD_POST ) {
					$args = array_merge( $args, $_POST );
				}
				if ( $supported & self::ACCEPT_JSON ) {
					$data = json_decode( $this->get_raw_data(), true );
					$args = array_merge( $args, $data );
				}

				$params = $this->sort_callback_params($callback, $args);
				if ( is_wp_error($params) )
					return $params;

				return call_user_func_array($callback, $params);
			}
		}

		if ( $matched )
			return new WP_Error( 'json_invalid_http_method', __( 'The specified route does not match the HTTP method used' ), array( 'status' => 405 ) );

		return new WP_Error( 'json_no_route', __( 'No route was found matching the URL and request method' ), array( 'status' => 404 ) );
	}

	/**
	 * Sort parameters by order specified in method declaration
	 *
	 * Takes a callback and a list of available params, then filters and sorts
	 * by the parameters the method actually needs, using the Reflection API
	 *
	 * @param callback $callback
	 * @param array $params
	 * @return array
	 */
	protected function sort_callback_params($callback, $provided) {
		if ( is_string( $callback ) && strpos($callback, '::') !== false )
			$callback = explode( '::', $callback );

		if ( is_array( $callback ) )
			$ref_func = new ReflectionMethod( $callback[0], $callback[1] );
		else
			$ref_func = new ReflectionFunction( $callback );

		$wanted = $ref_func->getParameters();
		$ordered_parameters = array();

		foreach ( $wanted as $param ) {
			if ( isset( $provided[ $param->getName() ] ) ) {
				// We have this parameters in the list to choose from
				$ordered_parameters[] = $provided[$param->getName()];
			}
			elseif ( $param->isDefaultValueAvailable() ) {
				// We don't have this parameter, but it's optional
				$ordered_parameters[] = $param->getDefaultValue();
			}
			else {
				// We don't have this parameter and it wasn't optional, abort!
				return new WP_Error( 'json_missing_callback_param', sprintf( __( 'Missing parameter %s' ), $param->getName() ), array( 'status' => 400 ) );
			}
		}
		return $ordered_parameters;
	}

	/**
	 * Check the authentication headers if supplied
	 *
	 * @return WP_Error|WP_User|null WP_User object indicates successful login, WP_Error indicates unsuccessful login and null indicates no authentication provided
	 */
	public function check_authentication() {
		$user = apply_filters( 'json_check_authentication', null);
		if ( is_a( $user, 'WP_User' ) )
			return $user;

		if ( !isset( $_SERVER['PHP_AUTH_USER'] ) )
			return;

		$username = $_SERVER['PHP_AUTH_USER'];
		$password = $_SERVER['PHP_AUTH_PW'];

		$user = wp_authenticate( $username, $password );

		if ( is_wp_error( $user ) )
			return $user;

		wp_set_current_user( $user->ID );
		return $user;
	}

	/**
	 * Convert an error to an array
	 *
	 * This iterates over all error codes and messages to change it into a flat
	 * array. This enables simpler client behaviour, as it is represented as a
	 * list in JSON rather than an object/map
	 *
	 * @param WP_Error $error
	 * @return array List of associative arrays with code and message keys
	 */
	protected function error_to_array( $error ) {
		$errors = array();
		foreach ((array) $error->errors as $code => $messages) {
			foreach ((array) $messages as $message) {
				$errors[] = array('code' => $code, 'message' => $message);
			}
		}
		return $errors;
	}

	/**
	 * Get an appropriate error representation in JSON
	 *
	 * Note: This should only be used in {@see WP_JSON_Server::serve_request()},
	 * as it cannot handle WP_Error internally. All callbacks and other internal
	 * methods should instead return a WP_Error with the data set to an array
	 * that includes a 'status' key, with the value being the HTTP status to
	 * send.
	 *
	 * @param string $code WP_Error-style code
	 * @param string $message Human-readable message
	 * @param int $status HTTP status code to send
	 * @return string JSON representation of the error
	 */
	protected function json_error( $code, $message, $status = null ) {
		if ( $status )
			status_header( $status );

		$error = compact( 'code', 'message' );
		return json_encode(array($error));
	}
}