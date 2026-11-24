<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 * @file
 */

declare( strict_types=1 );

namespace MediaWiki\Extension\WikiSEO;

/**
 * Class Validator
 *
 * @package MediaWiki\Extension\WikiSEO
 */
class Validator {
	/** Snapshots of the public, extensible parameter and language lists. */
	private static ?array $cachedParameterNames = null;
	private static ?array $cachedLanguageCodes = null;

	/** @var string[]|null Valid names computed for the current generator set. */
	private static ?array $cachedParams = null;

	/** @var array<string,true> Membership lookup for string parameter names. */
	private static array $cachedParamLookup = [];

	/**
	 * Valid parameter names across all generators
	 * @see WikiSEO::mergeValidTags()
	 *
	 * @var string[]
	 */
	public static $validParameterNames = [
		// This needs to be the first entry see getValidParams()
		'hreflang_%',

		'title',
		'title_mode',
		'title_separator',

		'keywords',
		'description',
		'canonical',

		'robots',
		'googlebot',

		'image', 'image_width', 'image_height', 'image_alt',

		'type',
		'site_name',
		'locale',
		'section',
		'author',

		'published_time',
		'modified_time',
		'image_type',
		'image_secure_url',
		'locale_alternate',
		'og_type',
		'schema_type',
		'schema_author',
		'twitter_site',
		'twitter_creator',
		'twitter_title',
		'twitter_description',
		'twitter_image',
		'twitter_image_alt',
		'twitter_card',
	];

	/**
	 * https://stackoverflow.com/a/3191729
	 *
	 * @var array Language codes
	 */
	public static $isoLanguageCodes = [
		'af-za',
		'am-et',
		'ar-ae',
		'ar-bh',
		'ar-dz',
		'ar-eg',
		'ar-iq',
		'ar-jo',
		'ar-kw',
		'ar-lb',
		'ar-ly',
		'ar-ma',
		'arn-cl',
		'ar-om',
		'ar-qa',
		'ar-sa',
		'ar-sy',
		'ar-tn',
		'ar-ye',
		'as-in',
		'az-cyrl-az',
		'az-latn-az',
		'ba-ru',
		'be-by',
		'bg-bg',
		'bn-bd',
		'bn-in',
		'bo-cn',
		'br-fr',
		'bs-cyrl-ba',
		'bs-latn-ba',
		'ca-es',
		'co-fr',
		'cs-cz',
		'cy-gb',
		'da-dk',
		'de-at',
		'de-ch',
		'de-de',
		'de-li',
		'de-lu',
		'dsb-de',
		'dv-mv',
		'el-gr',
		'en-029',
		'en-au',
		'en-bz',
		'en-ca',
		'en-gb',
		'en-ie',
		'en-in',
		'en-jm',
		'en-my',
		'en-nz',
		'en-ph',
		'en-sg',
		'en-tt',
		'en-us',
		'en-za',
		'en-zw',
		'es-ar',
		'es-bo',
		'es-cl',
		'es-co',
		'es-cr',
		'es-do',
		'es-ec',
		'es-es',
		'es-gt',
		'es-hn',
		'es-mx',
		'es-ni',
		'es-pa',
		'es-pe',
		'es-pr',
		'es-py',
		'es-sv',
		'es-us',
		'es-uy',
		'es-ve',
		'et-ee',
		'eu-es',
		'fa-ir',
		'fi-fi',
		'fil-ph',
		'fo-fo',
		'fr-be',
		'fr-ca',
		'fr-ch',
		'fr-fr',
		'fr-lu',
		'fr-mc',
		'fy-nl',
		'ga-ie',
		'gd-gb',
		'gl-es',
		'gsw-fr',
		'gu-in',
		'ha-latn-ng',
		'he-il',
		'hi-in',
		'hr-ba',
		'hr-hr',
		'hsb-de',
		'hu-hu',
		'hy-am',
		'id-id',
		'ig-ng',
		'ii-cn',
		'is-is',
		'it-ch',
		'it-it',
		'iu-cans-ca',
		'iu-latn-ca',
		'ja-jp',
		'ka-ge',
		'kk-kz',
		'kl-gl',
		'km-kh',
		'kn-in',
		'kok-in',
		'ko-kr',
		'ky-kg',
		'lb-lu',
		'lo-la',
		'lt-lt',
		'lv-lv',
		'mi-nz',
		'mk-mk',
		'ml-in',
		'mn-mn',
		'mn-mong-cn',
		'moh-ca',
		'mr-in',
		'ms-bn',
		'ms-my',
		'mt-mt',
		'nb-no',
		'ne-np',
		'nl-be',
		'nl-nl',
		'nn-no',
		'nso-za',
		'oc-fr',
		'or-in',
		'pa-in',
		'pl-pl',
		'prs-af',
		'ps-af',
		'pt-br',
		'pt-pt',
		'qut-gt',
		'quz-bo',
		'quz-ec',
		'quz-pe',
		'rm-ch',
		'ro-ro',
		'ru-ru',
		'rw-rw',
		'sah-ru',
		'sa-in',
		'se-fi',
		'se-no',
		'se-se',
		'si-lk',
		'sk-sk',
		'sl-si',
		'sma-no',
		'sma-se',
		'smj-no',
		'smj-se',
		'smn-fi',
		'sms-fi',
		'sq-al',
		'sr-cyrl-ba',
		'sr-cyrl-cs',
		'sr-cyrl-me',
		'sr-cyrl-rs',
		'sr-latn-ba',
		'sr-latn-cs',
		'sr-latn-me',
		'sr-latn-rs',
		'sv-fi',
		'sv-se',
		'sw-ke',
		'syr-sy',
		'ta-in',
		'te-in',
		'tg-cyrl-tj',
		'th-th',
		'tk-tm',
		'tn-za',
		'tr-tr',
		'tt-ru',
		'tzm-latn-dz',
		'ug-cn',
		'uk-ua',
		'ur-pk',
		'uz-cyrl-uz',
		'uz-latn-uz',
		'vi-vn',
		'wo-sn',
		'xh-za',
		'yo-ng',
		'zh-cn',
		'zh-hk',
		'zh-mo',
		'zh-sg',
		'zh-tw',
		'zu-za',
	];

	/**
	 * Removes all params that are not in $validParameterNames
	 *
	 * @param array $params Raw params
	 * @return array Validated params
	 */
	public static function validateParams( array $params ): array {
		$validatedParams = [];
		$validParams = static::getValidParams();

		// Subclasses may override getValidParams(). Preserve their strict
		// membership semantics, as well as strict comparison of integer keys.
		$useLookup = static::class === self::class;
		foreach ( $params as $paramKey => $paramData ) {
			$valid = $useLookup && is_string( $paramKey )
				? isset( self::$cachedParamLookup[$paramKey] )
				: in_array( $paramKey, $validParams, true );
			if ( $valid ) {
				$validatedParams[$paramKey] = $paramData;
			}
		}

		return $validatedParams;
	}

	/**
	 * Returns an array containing valid parameter names for use in WikiSEO
	 *
	 * @return string[]
	 */
	public static function getValidParams(): array {
		// Both public lists can be extended by extensions. Copy-on-write
		// snapshots avoid a new fingerprint string on every validation.
		if ( self::$cachedParams !== null &&
			self::$cachedParameterNames === self::$validParameterNames &&
			self::$cachedLanguageCodes === self::$isoLanguageCodes
		) {
			return self::$cachedParams;
		}

		$params = array_values( array_filter(
			self::$validParameterNames,
			static function ( string $name ): bool {
				return $name !== 'hreflang_%';
			}
		) );
		foreach ( self::$isoLanguageCodes as $langCode ) {
			$params[] = 'hreflang_' . $langCode;
		}

		self::$cachedParameterNames = self::$validParameterNames;
		self::$cachedLanguageCodes = self::$isoLanguageCodes;
		self::$cachedParams = array_values( array_unique( $params ) );
		self::$cachedParamLookup = [];
		foreach ( self::$cachedParams as $name ) {
			if ( is_string( $name ) ) {
				self::$cachedParamLookup[$name] = true;
			}
		}
		return self::$cachedParams;
	}
}
