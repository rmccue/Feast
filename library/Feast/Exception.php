<?php

class Feast_Exception extends Exception {
	protected $code = '';
	protected $error = null;
	protected $data = null;

	public function __construct($message, $code, $data = null, $wp_error = null) {
		parent::__construct($message);
		$this->code = $code;
		$this->data = $data;
		$this->error = null;
	}

	public function getData() {
		return $this->data;
	}

	public function getOriginalError() {
		return $this->error;
	}
}