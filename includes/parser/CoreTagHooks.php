<?php
/**
 * Tag hooks provided by MediaWiki core
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Parser
 */

use MediaWiki\MediaWikiServices;

/**
 * Various tag hooks, registered in Parser::firstCallInit()
 * @ingroup Parser
 */
class CoreTagHooks {
	/**
	 * @param Parser $parser
	 * @return void
	 */
	public static function register( $parser ) {
		global $wgRawHtml;
		$parser->setHook( 'pre', [ __CLASS__, 'pre' ] );
		$parser->setHook( 'nowiki', [ __CLASS__, 'nowiki' ] );
		$parser->setHook( 'gallery', [ __CLASS__, 'gallery' ] );
		$parser->setHook( 'indicator', [ __CLASS__, 'indicator' ] );
		$parser->setHook( 'langconvert', [ __CLASS__, 'langconvert' ] );
		if ( $wgRawHtml ) {
			$parser->setHook( 'html', [ __CLASS__, 'html' ] );
		}
	}

	/**
	 * Core parser tag hook function for 'pre'.
	 * Text is treated roughly as 'nowiki' wrapped in an HTML 'pre' tag;
	 * valid HTML attributes are passed on.
	 *
	 * @param string $text
	 * @param array $attribs
	 * @param Parser $parser
	 * @return string HTML
	 */
	public static function pre( $text, $attribs, $parser ) {
		// Backwards-compatibility hack
		$content = StringUtils::delimiterReplace( '<nowiki>', '</nowiki>', '$1', $text, 'i' );

		$attribs = Sanitizer::validateTagAttributes( $attribs, 'pre' );
		// We need to let both '"' and '&' through,
		// for strip markers and entities respectively.
		$content = str_replace(
			[ '>', '<' ],
			[ '&gt;', '&lt;' ],
			$content
		);
		return Html::rawElement( 'pre', $attribs, $content );
	}

	/**
	 * Core parser tag hook function for 'html', used only when
	 * $wgRawHtml is enabled.
	 *
	 * This is potentially unsafe and should be used only in very careful
	 * circumstances, as the contents are emitted as raw HTML.
	 *
	 * Uses undocumented extended tag hook return values, introduced in r61913.
	 *
	 * @suppress SecurityCheck-XSS
	 * @param string $content
	 * @param array $attributes
	 * @param Parser $parser
	 * @throws MWException
	 * @return array|string Output of tag hook
	 */
	public static function html( $content, $attributes, $parser ) {
		global $wgRawHtml;
		if ( $wgRawHtml ) {
			if ( $parser->getOptions()->getAllowUnsafeRawHtml() ) {
				return [ $content, 'markerType' => 'nowiki' ];
			} else {
				// In a system message where raw html is
				// not allowed (but it is allowed in other
				// contexts).
				return Html::rawElement(
					'span',
					[ 'class' => 'error' ],
					// Using ->text() not ->parse() as
					// a paranoia measure against a loop.
					wfMessage( 'rawhtml-notallowed' )->escaped()
				);
			}
		} else {
			throw new MWException( '<html> extension tag encountered unexpectedly' );
		}
	}

	/**
	 * Core parser tag hook function for 'nowiki'. Text within this section
	 * gets interpreted as a string of text with HTML-compatible character
	 * references, and wiki markup within it will not be expanded.
	 *
	 * Uses undocumented extended tag hook return values, introduced in r61913.
	 *
	 * Uses custom html escaping which phan-taint-check won't recognize
	 * hence we suppress the error.
	 * @suppress SecurityCheck-XSS
	 *
	 * @param string $content
	 * @param array $attributes
	 * @param Parser $parser
	 * @return array
	 */
	public static function nowiki( $content, $attributes, $parser ) {
		$content = strtr( $content, [
			// lang converter
			'-{' => '-&#123;',
			'}-' => '&#125;-',
			// html tags
			'<' => '&lt;',
			'>' => '&gt;'
			// Note: Both '"' and '&' are not converted.
			// This allows strip markers and entities through.
		] );
		return [ $content, 'markerType' => 'nowiki' ];
	}

	/**
	 * Core parser tag hook function for 'gallery'.
	 *
	 * Renders a thumbnail list of the given images, with optional captions.
	 * Full syntax documented on the wiki:
	 *
	 *   https://www.mediawiki.org/wiki/Help:Images#Gallery_syntax
	 *
	 * @todo break Parser::renderImageGallery out here too.
	 *
	 * @param string $content
	 * @param array $attributes
	 * @param Parser $parser
	 * @return string HTML
	 */
	public static function gallery( $content, $attributes, $parser ) {
		return $parser->renderImageGallery( $content, $attributes );
	}

	/**
	 * XML-style tag for page status indicators: icons (or short text snippets) usually displayed in
	 * the top-right corner of the page, outside of the main content.
	 *
	 * @param string $content
	 * @param array $attributes
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @return string
	 * @since 1.25
	 */
	public static function indicator( $content, array $attributes, Parser $parser, PPFrame $frame ) {
		if ( !isset( $attributes['name'] ) || trim( $attributes['name'] ) === '' ) {
			return '<span class="error">' .
				wfMessage( 'invalid-indicator-name' )->inContentLanguage()->parse() .
				'</span>';
		}

		$parser->getOutput()->setIndicator(
			trim( $attributes['name'] ),
			Parser::stripOuterParagraph( $parser->recursiveTagParseFully( $content, $frame ) )
		);

		return '';
	}

	/**
	 * Returns content converted into the requested language variant, using LanguageConverter.
	 *
	 * @param string $content
	 * @param array $attributes
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @return string
	 * @since 1.36
	 */
	public static function langconvert( $content, array $attributes, Parser $parser, PPFrame $frame ) {
		if ( isset( $attributes['from'] ) && isset( $attributes['to'] ) ) {
			$langFactory = MediaWikiServices::getInstance()->getLanguageFactory();
			$to = trim( $attributes['to'] );
			$langFrom = $langFactory->getParentLanguage( trim( $attributes['from'] ) );
			$langTo = $langFactory->getParentLanguage( $to );
			if ( $langFrom && $langTo && $langFrom->equals( $langTo ) ) {
				$converter = MediaWikiServices::getInstance()->getLanguageConverterFactory()
					->getLanguageConverter( $langFrom );
				return $converter->autoConvert(
					$parser->recursiveTagParse( $content, $frame ),
					$to
				);
			}
		}

		return Html::rawElement(
			'span',
			[ 'class' => 'error' ],
			wfMessage( 'invalid-langconvert-attrs' )->inContentLanguage()->parse()
		);
	}

}
