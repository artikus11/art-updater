<?php

declare(strict_types=1);

namespace Art\Updater;

final readonly class Snapshot {

	/**
	 * @param array<string, array<string, mixed>> $plugins
	 */
	public function __construct(
		private array $plugins,
		private ?string $release = null,
		private ?string $generated_at = null,
	) {
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function get_plugins(): array {
		return $this->plugins;
	}

	public function has_plugin( string $slug ): bool {
		return isset( $this->plugins[ $slug ] ) && is_array( $this->plugins[ $slug ] );
	}

	public function get_version( string $slug ): ?string {
		if ( ! $this->has_plugin( $slug ) ) {
			return null;
		}

		$version = $this->plugins[ $slug ]['version'] ?? null;

		return is_string( $version ) && '' !== $version ? $version : null;
	}

	public function get_release(): ?string {
		return $this->release;
	}

	public function get_generated_at(): ?string {
		return $this->generated_at;
	}
}
