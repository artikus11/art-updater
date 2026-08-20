<?php

declare(strict_types=1);

namespace Art\Updater;

final class PluginUpdater {

	/**
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * @var UpdateProviderInterface
	 */
	private $provider;

	/**
	 * @var array<string, string>
	 */
	private $headers;

	public function __construct( string $plugin_file, ?UpdateProviderInterface $provider = null ) {
		$plugin = $this->make_plugin( $plugin_file );

		if ( null === $plugin ) {
			return;
		}

		$this->plugin  = $plugin;
		$this->headers = $this->read_headers( $plugin_file );
		$provider      = $provider ?? $this->make_github_provider();

		if ( null === $provider ) {
			return;
		}

		$this->provider = $provider;
		$this->register();
	}

	/**
	 * @param mixed $transient
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}

		$plugin_file = $this->plugin->get_plugin_file();
		$update      = $this->provider->get_update( $this->plugin );

		if ( null === $update ) {
			$item = $this->as_transient_item( $this->plugin->get_version(), '' );

			if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
				$transient->no_update = [];
			}

			$transient->no_update[ $plugin_file ] = $item;

			if ( isset( $transient->response[ $plugin_file ] ) ) {
				unset( $transient->response[ $plugin_file ] );
			}

			return $transient;
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = [];
		}

		$transient->response[ $plugin_file ] = $this->as_transient_item(
			$update->get_version(),
			(string) $update->get_package_url()
		);

		if ( isset( $transient->no_update[ $plugin_file ] ) ) {
			unset( $transient->no_update[ $plugin_file ] );
		}

		return $transient;
	}

	/**
	 * @param mixed  $result
	 * @param string $action
	 * @param mixed  $args
	 * @return mixed
	 */
	public function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) ) {
			return $result;
		}

		if ( $args->slug !== $this->plugin->get_slug() ) {
			return $result;
		}

		$update  = $this->provider->get_update( $this->plugin );
		$version = null !== $update ? $update->get_version() : $this->plugin->get_version();
		$package = (string) $update?->get_package_url();
		$tested  = null !== $update && null !== $update->get_tested() ? $update->get_tested() : $this->header( 'tested' );
		$requires = null !== $update && null !== $update->get_requires() ? $update->get_requires() : $this->header( 'requires' );
		$changelog = null !== $update && null !== $update->get_changelog() ? $update->get_changelog() : '';

		$info = (object) [
			'name'          => $this->header( 'name' ),
			'slug'          => $this->plugin->get_slug(),
			'version'       => $version,
			'author'        => $this->header( 'author' ),
			'homepage'      => $this->header( 'plugin_uri' ),
			'requires'      => $requires,
			'tested'        => $tested,
			'requires_php'  => $this->header( 'requires_php' ),
			'download_link' => $package,
			'last_updated'  => $update?->get_updated_at(),
			'sections'      => [
				'description' => $this->header( 'description' ),
				'changelog'   => $changelog,
			],
		];

		return $info;
	}

	/**
	 * @param bool|\WP_Error $reply
	 * @param string         $package
	 * @param mixed          $upgrader
	 * @param array<string, mixed> $hook_extra
	 * @return bool|\WP_Error|string
	 */
	public function pre_download( $reply, $package, $upgrader, $hook_extra = [] ) {
		if ( false !== $reply || ! is_string( $package ) || ! $this->is_our_package( $package, $hook_extra ) ) {
			return $reply;
		}

		return $this->download_package( $package );
	}

	/**
	 * @param mixed                $response
	 * @param array<string, mixed> $hook_extra
	 * @param array<string, mixed> $result
	 * @return array<string, mixed>
	 */
	public function after_install( $response, $hook_extra, $result ) {
		if ( ! is_array( $result ) || ! $this->is_our_upgrade( $hook_extra ) ) {
			return $result;
		}

		global $wp_filesystem;

		if ( ! is_object( $wp_filesystem ) ) {
			return $result;
		}

		$from = isset( $result['destination'] ) ? untrailingslashit( (string) $result['destination'] ) : '';
		$to   = untrailingslashit( WP_PLUGIN_DIR . '/' . $this->plugin->get_slug() );

		if ( '' === $from || $from === $to ) {
			return $result;
		}

		if ( $wp_filesystem->exists( $to ) ) {
			$wp_filesystem->delete( $to, true );
		}

		$wp_filesystem->move( $from, $to );
		$result['destination'] = $to . '/';

		return $result;
	}

	private function register(): void {
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugin_information' ], 10, 3 );
		add_filter( 'upgrader_pre_download', [ $this, 'pre_download' ], 10, 4 );
		add_filter( 'upgrader_post_install', [ $this, 'after_install' ], 10, 3 );
	}

	private function make_plugin( string $plugin_file ): ?Plugin {
		if ( ! is_readable( $plugin_file ) ) {
			return null;
		}

		$basename = plugin_basename( $plugin_file );
		$slug     = dirname( $basename );

		if ( '.' === $slug || '' === $slug ) {
			$slug = basename( $plugin_file, '.php' );
		}

		$headers = $this->read_headers( $plugin_file );
		$version = $headers['version'];

		if ( '' === $slug || '' === $version ) {
			return null;
		}

		return new Plugin( $slug, $version, $basename );
	}

	private function make_github_provider(): ?GitHubProvider {
		$repository = $this->constant_string( 'ART_UPDATER_GITHUB_REPOSITORY' );

		if ( '' === $repository ) {
			return null;
		}

		$private = defined( 'ART_UPDATER_GITHUB_PRIVATE' ) && ART_UPDATER_GITHUB_PRIVATE;
		$token   = $this->constant_string( 'ART_UPDATER_GITHUB_TOKEN' );

		if ( $private && '' === $token ) {
			return null;
		}

		return new GitHubProvider(
			$repository,
			$this->constant_string( 'ART_UPDATER_GITHUB_RELEASE_TAG' )
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function read_headers( string $plugin_file ): array {
		$data = get_file_data(
			$plugin_file,
			[
				'name'         => 'Plugin Name',
				'version'      => 'Version',
				'author'       => 'Author',
				'plugin_uri'   => 'Plugin URI',
				'description'  => 'Description',
				'requires'     => 'Requires at least',
				'tested'       => 'Tested up to',
				'requires_php' => 'Requires PHP',
			],
			'plugin'
		);

		$headers = [];

		foreach ( $data as $key => $value ) {
			$headers[ $key ] = is_string( $value ) ? $value : '';
		}

		return $headers;
	}

	private function header( string $key ): string {
		return isset( $this->headers[ $key ] ) ? $this->headers[ $key ] : '';
	}

	/**
	 * @return object
	 */
	private function as_transient_item( string $version, string $package ) {
		return (object) [
			'id'           => $this->plugin->get_plugin_file(),
			'slug'         => $this->plugin->get_slug(),
			'plugin'       => $this->plugin->get_plugin_file(),
			'new_version'  => $version,
			'url'          => $this->header( 'plugin_uri' ),
			'package'      => $package,
			'requires'     => $this->header( 'requires' ),
			'tested'       => $this->header( 'tested' ),
			'requires_php' => $this->header( 'requires_php' ),
		];
	}

	/**
	 * @param array<string, mixed> $hook_extra
	 */
	private function is_our_upgrade( $hook_extra ): bool {
		if ( ! is_array( $hook_extra ) ) {
			return false;
		}

		if ( ! empty( $hook_extra['plugin'] ) && $this->plugin->get_plugin_file() === $hook_extra['plugin'] ) {
			return true;
		}

		return ! empty( $hook_extra['plugins'] )
			&& is_array( $hook_extra['plugins'] )
			&& in_array( $this->plugin->get_plugin_file(), $hook_extra['plugins'], true );
	}

	/**
	 * @param string               $package
	 * @param array<string, mixed> $hook_extra
	 */
	private function is_our_package( string $package, $hook_extra ): bool {
		if ( $this->is_our_upgrade( $hook_extra ) ) {
			return $this->is_github_asset_url( $package );
		}

		$update = $this->provider->get_update( $this->plugin );

		return null !== $update && $update->get_package_url() === $package;
	}

	private function is_github_asset_url( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$path = wp_parse_url( $url, PHP_URL_PATH );

		return 'api.github.com' === $host
			&& is_string( $path )
			&& false !== strpos( $path, '/releases/assets/' );
	}

	/**
	 * @return string|\WP_Error
	 */
	private function download_package( string $url ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$tmp = wp_tempnam( $url );

		if ( ! $tmp ) {
			return new \WP_Error( 'art_updater_tmp', 'Could not create a temporary file for the plugin package.' );
		}

		$response = $this->request_package( $url, $tmp );

		if ( is_wp_error( $response ) ) {
			wp_delete_file( $tmp );

			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( in_array( $code, [ 301, 302, 303, 307, 308 ], true ) ) {
			$location = wp_remote_retrieve_header( $response, 'location' );

			if ( ! is_string( $location ) || '' === $location ) {
				wp_delete_file( $tmp );

				return new \WP_Error( 'art_updater_redirect', 'GitHub asset redirect is missing a Location header.' );
			}

			$response = $this->request_package( $location, $tmp, false );
		}

		if ( is_wp_error( $response ) ) {
			wp_delete_file( $tmp );

			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			wp_delete_file( $tmp );

			return new \WP_Error( 'art_updater_download', 'GitHub asset download failed.' );
		}

		return $tmp;
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	private function request_package( string $url, string $filename, bool $authorize = true ) {
		$headers = [
			'User-Agent' => 'art-updater',
		];

		if ( $authorize && $this->is_github_asset_url( $url ) ) {
			$headers['Accept'] = 'application/octet-stream';
			$token               = $this->constant_string( 'ART_UPDATER_GITHUB_TOKEN' );

			if ( '' !== $token ) {
				$headers['Authorization'] = 'Bearer ' . $token;
			}
		}

		return wp_remote_get(
			$url,
			[
				'timeout'     => 300,
				'redirection' => 0,
				'headers'     => $headers,
				'stream'      => true,
				'filename'    => $filename,
			]
		);
	}

	private function constant_string( string $name ): string {
		if ( ! defined( $name ) ) {
			return '';
		}

		$value = constant( $name );

		return is_string( $value ) ? trim( $value ) : '';
	}
}
