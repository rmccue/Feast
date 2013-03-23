<?php

class Feast_OPMLParser {
	public $data = array();
	public $raw = '';
	public $error = '';

	public function __construct( $raw_data ) {
		$this->raw = $raw_data;
		// Create an XML parser
		try {
			$xml_parser = new SimpleXMLElement( $this->raw, LIBXML_NOERROR );

			$this->data = $this->loop( $xml_parser->body->outline );
		}
		catch ( Exception $e ) {
			$this->error = $e->getMessage();
			return;
		}
	}

	protected function loop( $element ) {
		$data = array();

		foreach ($element as $element) {
			if ( $element['type'] == 'rss' || isset( $element['xmlUrl'] ) ) {
				$data[] = $this->format( $element );
			}
			elseif ( $element->outline ) {
				$data[ (string) $element['text'] ] = $this->loop( $element->outline );
			}
		}

		return $data;
	}

	/**
	 * Return an array from a supplied SimpleXMLElement object
	 *
	 * @param SimpleXMLElement $element
	 * @return array
	 */
	protected function format( $element ) {
		return array(
			'htmlurl' => (string) $element['htmlUrl'],
			'xmlurl' => (string) $element['xmlUrl'],
			'text' => (string) $element['text'],
			'title' => (string) $element['title'],
			'description' => (string) $element['description'],
		);
	}
}