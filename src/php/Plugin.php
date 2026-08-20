<?php

declare(strict_types=1);

namespace Art\Updater;

final class Plugin {

	/**
	 * @var string
	 */
	private $slug;

	/**
	 * @var string
	 */
	private $version;

	/**
	 * @var string Plugin basename, e.g. skl-core/skl-core.php.
	 */
	private $plugin_file;

	public function __construct( string $slug, string $version, string $plugin_file ) {
		$this->slug        = $slug;
		$this->version     = $version;
		$this->plugin_file = $plugin_file;
	}

	public function get_slug(): string {
		return $this->slug;
	}

	public function get_version(): string {
		return $this->version;
	}

	public function get_plugin_file(): string {
		return $this->plugin_file;
	}
}
