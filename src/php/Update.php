<?php

declare(strict_types=1);

namespace Art\Updater;

final class Update {

	/**
	 * @var string
	 */
	private $version;

	/**
	 * @var string ZIP filename in the release, e.g. skl-core.zip.
	 */
	private $package;

	/**
	 * @var string|null Download URL resolved by the provider.
	 */
	private $package_url;

	/**
	 * @var string|null
	 */
	private $changelog;

	/**
	 * @var string|null
	 */
	private $requires;

	/**
	 * @var string|null
	 */
	private $tested;

	/**
	 * @var string|null
	 */
	private $updated_at;

	public function __construct(
		string $version,
		string $package,
		?string $package_url = null,
		?string $changelog = null,
		?string $requires = null,
		?string $tested = null,
		?string $updated_at = null
	) {
		$this->version     = $version;
		$this->package     = $package;
		$this->package_url = $package_url;
		$this->changelog   = $changelog;
		$this->requires    = $requires;
		$this->tested      = $tested;
		$this->updated_at  = $updated_at;
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
