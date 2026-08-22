<?php

declare(strict_types=1);

namespace Art\Updater;

final class Config {

	public const string GITHUB_TOKEN       = 'AUP_GITHUB_TOKEN';
	public const string GITHUB_REPOSITORY  = 'AUP_GITHUB_REPOSITORY';
	public const string GITHUB_RELEASE_TAG = 'AUP_GITHUB_RELEASE_TAG';
	public const string GITHUB_PRIVATE     = 'AUP_GITHUB_PRIVATE';

	public const string METADATA_ASSET     = 'update-metadata.json';
	public const string API_BASE           = 'https://api.github.com/repos/';
	public const string CACHE_PREFIX       = 'aup_gh_';
	public const string CACHE_TTL_FILTER   = 'aup_github_cache_ttl';
	public const int    CACHE_TTL          = 21600;
	public const int    FAIL_TTL           = 900;

	public const string ERROR_TMP      = 'aup_tmp';
	public const string ERROR_REDIRECT = 'aup_redirect';
	public const string ERROR_DOWNLOAD = 'aup_download';

	public static function constant_string( string $name ): string {
		if ( ! defined( $name ) ) {
			return '';
		}

		$value = constant( $name );

		return is_string( $value ) ? trim( $value ) : '';
	}

	public static function is_github_private(): bool {
		return defined( self::GITHUB_PRIVATE ) && (bool) constant( self::GITHUB_PRIVATE );
	}
}
