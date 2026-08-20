<?php

declare(strict_types=1);

namespace Art\Updater;

final readonly class Plugin {

	public function __construct(
		private string $slug,
		private string $version,
		private string $plugin_file,
	) {
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
