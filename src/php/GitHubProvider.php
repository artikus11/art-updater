<?php

declare(strict_types=1);

namespace Art\Updater;

final class GitHubProvider implements UpdateProviderInterface {

	private readonly string $repository;
	private readonly string $release_tag;

	public function __construct( string $repository, string $release_tag = '' ) {
		$this->repository  = trim( $repository, '/' );
		$this->release_tag = $release_tag;
	}

	public static function from_site(): ?self {
		$repository = Config::constant_string( Config::GITHUB_REPOSITORY );

		if ( '' === $repository ) {
			return null;
		}

		if ( Config::is_github_private() && '' === Config::constant_string( Config::GITHUB_TOKEN ) ) {
			return null;
		}

		return new self(
			$repository,
			Config::constant_string( Config::GITHUB_RELEASE_TAG )
		);
	}

	public function get_update( Plugin $plugin ): ?Update {
		$update = $this->get_remote( $plugin );

		if ( null === $update || ! $update->is_newer_than( $plugin ) ) {
			return null;
		}

		return $update;
	}

	public function get_remote( Plugin $plugin ): ?Update {
		$raw = $this->load_snapshot();

		if ( null === $raw ) {
			return null;
		}

		return $this->update_from_snapshot( $plugin->get_slug(), $raw );
	}

	public function get_snapshot(): ?Snapshot {
		$raw = $this->load_snapshot();

		if ( null === $raw ) {
			return null;
		}

		$release      = isset( $raw['release'] ) && is_string( $raw['release'] ) ? $raw['release'] : null;
		$generated_at = isset( $raw['generated_at'] ) && is_string( $raw['generated_at'] ) ? $raw['generated_at'] : null;

		return new Snapshot( $raw['plugins'], $release, $generated_at );
	}

	public function clear_cache(): bool {
		return delete_transient( $this->cache_key() );
	}

	/**
	 * @return array{plugins: array<string, array<string, mixed>>, assets: array<string, string>, release?: string|null, generated_at?: string|null}|null
	 */
	private function load_snapshot(): ?array {
		if ( ! $this->is_repository_valid() ) {
			return null;
		}

		$cache_key = $this->cache_key();
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			if ( ! empty( $cached['_failed'] ) ) {
				return null;
			}

			if ( $this->is_snapshot_payload( $cached ) ) {
				return $cached;
			}
		}

		$snapshot = $this->fetch_snapshot();
		$ttl      = $this->cache_ttl( null !== $snapshot );

		set_transient(
			$cache_key,
			null !== $snapshot ? $snapshot : [ '_failed' => true ],
			$ttl
		);

		return $snapshot;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function is_snapshot_payload( array $payload ): bool {
		return isset( $payload['plugins'], $payload['assets'] )
			&& is_array( $payload['plugins'] )
			&& is_array( $payload['assets'] );
	}

	/**
	 * @param string                                                                            $slug
	 * @param array{plugins: array<string, array<string, mixed>>, assets: array<string, string>} $snapshot
	 */
	private function update_from_snapshot( string $slug, array $snapshot ): ?Update {
		$entry = isset( $snapshot['plugins'][ $slug ] ) && is_array( $snapshot['plugins'][ $slug ] )
			? $snapshot['plugins'][ $slug ]
			: null;

		if ( null === $entry ) {
			return null;
		}

		$version     = isset( $entry['version'] ) && is_string( $entry['version'] ) ? $entry['version'] : '';
		$package     = isset( $entry['package'] ) && is_string( $entry['package'] ) ? $entry['package'] : '';
		$updated_at  = isset( $entry['updated_at'] ) && is_string( $entry['updated_at'] ) ? $entry['updated_at'] : null;
		$package_url = isset( $snapshot['assets'][ $package ] ) && is_string( $snapshot['assets'][ $package ] )
			? $snapshot['assets'][ $package ]
			: null;

		if ( '' === $version || '' === $package || null === $package_url ) {
			return null;
		}

		return new Update( $version, $package, $package_url, null, null, null, $updated_at );
	}

	/**
	 * @return array{plugins: array<string, array<string, mixed>>, assets: array<string, string>, release: string|null, generated_at: string|null}|null
	 */
	private function fetch_snapshot(): ?array {
		$release = $this->request_json( $this->release_url() );

		if ( null === $release || empty( $release['assets'] ) || ! is_array( $release['assets'] ) ) {
			return null;
		}

		$assets       = [];
		$metadata_url = null;

		foreach ( $release['assets'] as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}

			$name = isset( $asset['name'] ) && is_string( $asset['name'] ) ? $asset['name'] : '';
			$url  = isset( $asset['url'] ) && is_string( $asset['url'] ) ? $asset['url'] : '';

			if ( '' === $name || '' === $url ) {
				continue;
			}

			$assets[ $name ] = $url;

			if ( Config::METADATA_ASSET === $name ) {
				$metadata_url = $url;
			}
		}

		if ( null === $metadata_url ) {
			return null;
		}

		$metadata_body = $this->request_asset( $metadata_url );

		if ( null === $metadata_body ) {
			return null;
		}

		$metadata = json_decode( $metadata_body, true );

		if ( ! is_array( $metadata ) || empty( $metadata['plugins'] ) || ! is_array( $metadata['plugins'] ) ) {
			return null;
		}

		$release_id   = isset( $metadata['release'] ) && is_string( $metadata['release'] ) ? $metadata['release'] : null;
		$generated_at = isset( $metadata['generated_at'] ) && is_string( $metadata['generated_at'] ) ? $metadata['generated_at'] : null;

		return [
			'plugins'      => $metadata['plugins'],
			'assets'       => $assets,
			'release'      => $release_id,
			'generated_at' => $generated_at,
		];
	}

	private function release_url(): string {
		$base = Config::API_BASE . $this->repository;

		if ( '' === $this->release_tag ) {
			return $base . '/releases/latest';
		}

		return $base . '/releases/tags/' . rawurlencode( $this->release_tag );
	}

	private function request_json( string $url ): ?array {
		$body = $this->request(
			$url,
			[
				'Accept'               => 'application/vnd.github+json',
				'X-GitHub-Api-Version' => '2022-11-28',
			]
		);

		if ( null === $body ) {
			return null;
		}

		$decoded = json_decode( $body, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	private function request_asset( string $url ): ?string {
		return $this->request(
			$url,
			[
				'Accept'               => 'application/octet-stream',
				'X-GitHub-Api-Version' => '2022-11-28',
			]
		);
	}

	private function request( string $url, array $headers ): ?string {
		$headers['User-Agent'] = 'art-updater';

		$token = Config::constant_string( Config::GITHUB_TOKEN );

		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_get(
			$url,
			[
				'timeout'     => 15,
				'redirection' => 5,
				'headers'     => $headers,
			]
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );

		return is_string( $body ) && '' !== $body ? $body : null;
	}

	private function is_repository_valid(): bool {
		return 1 === preg_match( '#^[^/]+/[^/]+$#', $this->repository );
	}

	private function cache_key(): string {
		return Config::CACHE_PREFIX . md5( $this->repository . "\0" . $this->release_tag );
	}

	private function cache_ttl( bool $success ): int {
		$default = $success ? Config::CACHE_TTL : Config::FAIL_TTL;

		if ( $success && defined( 'HOUR_IN_SECONDS' ) ) {
			$default = 6 * HOUR_IN_SECONDS;
		}

		if ( ! $success && defined( 'MINUTE_IN_SECONDS' ) ) {
			$default = 15 * MINUTE_IN_SECONDS;
		}

		$ttl = apply_filters( Config::CACHE_TTL_FILTER, $default, $success, $this->repository, $this->release_tag );

		return is_int( $ttl ) && $ttl > 0 ? $ttl : $default;
	}
}
