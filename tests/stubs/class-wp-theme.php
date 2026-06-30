<?php

class WP_Theme {
	private string $version;
	private string $template;
	private string $theme_uri;
	private string $author;

	public function __construct(
		string $version = '1.0.0',
		string $template = '',
		string $theme_uri = '',
		string $author = '',
	) {
		$this->version   = $version;
		$this->template  = $template;
		$this->theme_uri = $theme_uri;
		$this->author    = $author;
	}

	public function get( string $header ): string|false {
		return match ( $header ) {
			'Version'  => $this->version,
			'Template' => $this->template,
			'ThemeURI' => $this->theme_uri,
			'Author'   => $this->author,
			default    => false,
		};
	}
}
