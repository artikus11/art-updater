<?php

declare(strict_types=1);

namespace Art\Updater;

final class GitHubProvider implements UpdateProviderInterface {

	private const METADATA_ASSET = 'update-metadata.json';
	private const API_BASE       = 'https://api.github.com/repos/';
	private const CACHE_PREFIX   = 'art_updater_gh_';
	private const CACHE_TTL      = 21600;
	private const FAIL_TTL       = 900;

	private readonly string $repository;
	private readonly string $release_tag;

	public function __construct( string $repository, string $release_tag = '' ) {
		$this->repository  = trim( $repository, '/' );
		$this->release_tag = $release_tag;
	}

	public function get_update( Plugin $plugin ): ?Update {
		$snapshot = $this->get_snapshot();

		if ( null === $snapshot ) {
			return null;
		}

		$slug  = $plugin->get_slug();
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

		$update = new Update( $version, $package, $package_url, null, null, null, $updated_at );

		if ( ! $update->is_newer_than( $plugin ) ) {
			return null;
		}

		return $update;
	}

	/**
	 * @return array{plugins: array<string, array<string, mixed>>, assets: array<string, string>}|null
	 */
	private function get_snapshot(): ?array {
		if ( ! $this->is_repository_valid() ) {
			return null;
		}

		$cache_key = $this->cache_key();
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			if ( ! empty( $cached['_failed'] ) ) {
				return null;
			}

			if ( isset( $cached['plugins'], $cached['assets'] ) && is_array( $cached['plugins'] ) && is_array( $cached['assets'] ) ) {
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
	 * @return array{plugins: array<string, array<string, mixed>>, assets: array<string, string>}|null
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

			if ( self::METADATA_ASSET === $name ) {
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

		return [
			'plugins' => $metadata['plugins'],
			'assets'  => $assets,
		];
	}

	private function release_url(): string {
		$base = self::API_BASE . $this->repository;

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

		$token = $this->get_token();

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

	private function get_token(): string {
		if ( ! defined( 'ART_UPDATER_GITHUB_TOKEN' ) ) {
			return '';
		}

		$token = ART_UPDATER_GITHUB_TOKEN;

		return is_string( $token ) ? trim( $token ) : '';
	}

	private function is_repository_valid(): bool {
		return 1 === preg_match( '#^[^/]+/[^/]+$#', $this->repository );
	}

	private function cache_key(): string {
		return self::CACHE_PREFIX . md5( $this->repository . "\0" . $this->release_tag );
	}

	private function cache_ttl( bool $success ): int {
		$default = $success ? self::CACHE_TTL : self::FAIL_TTL;

		if ( $success && defined( 'HOUR_IN_SECONDS' ) ) {
			$default = 6 * HOUR_IN_SECONDS;
		}

		if ( ! $success && defined( 'MINUTE_IN_SECONDS' ) ) {
			$default = 15 * MINUTE_IN_SECONDS;
		}

		$ttl = apply_filters( 'art_updater_github_cache_ttl', $default, $success, $this->repository, $this->release_tag );

		return is_int( $ttl ) && $ttl > 0 ? $ttl : $default;
	}
}
