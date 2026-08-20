<?php

declare(strict_types=1);

namespace Art\Updater;

final readonly class Update {

	public function __construct(
		private string $version,
		private string $package,
		private ?string $package_url = null,
		private ?string $changelog = null,
		private ?string $requires = null,
		private ?string $tested = null,
		private ?string $updated_at = null,
	) {
	}

	public function get_version(): string {
		return $this->version;
	}

	public function get_package(): string {
		return $this->package;
	}

	public function get_package_url(): ?string {
		return $this->package_url;
	}

	public function get_changelog(): ?string {
		return $this->changelog;
	}

	public function get_requires(): ?string {
		return $this->requires;
	}

	public function get_tested(): ?string {
		return $this->tested;
	}

	public function get_updated_at(): ?string {
		return $this->updated_at;
	}

	public function is_newer_than( Plugin $plugin ): bool {
		return version_compare( $this->version, $plugin->get_version(), '>' );
	}
}
