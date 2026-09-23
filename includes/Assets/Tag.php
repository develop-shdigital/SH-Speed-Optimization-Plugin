<?php
/**
 * Minimal, lossless HTML start-tag model.
 *
 * Parsing a whole document with DOMDocument mangles HTML5 markup, so
 * transformers work tag by tag: parse one start tag, change attributes,
 * serialize it back. Untouched attributes keep their original raw form.
 *
 * @package SH\SpeedOptimizer
 */

namespace SH\SpeedOptimizer\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * Start tag.
 */
final class Tag {

	/**
	 * Lower-case tag name.
	 *
	 * @var string
	 */
	public string $name;

	/**
	 * Attributes: lower-case name => raw (still entity-encoded) value, or null for boolean attributes.
	 *
	 * @var array<string,string|null>
	 */
	private array $attributes = array();

	/**
	 * Original attribute names (preserve case on output).
	 *
	 * @var array<string,string>
	 */
	private array $original_names = array();

	/**
	 * Whether the tag was written as self-closing ("<img />").
	 *
	 * @var bool
	 */
	private bool $self_closing = false;

	/**
	 * Original markup.
	 *
	 * @var string
	 */
	private string $original;

	/**
	 * Whether anything changed.
	 *
	 * @var bool
	 */
	private bool $dirty = false;

	/**
	 * Parse a start tag like `<img src="a.jpg" alt=x loading>`.
	 *
	 * @param string $html Start tag markup.
	 */
	public static function parse( string $html ): ?self {
		if ( ! preg_match( '/^<([a-zA-Z][a-zA-Z0-9\-]*)((?:\s|\/)(?:[^>"\']|"[^"]*"|\'[^\']*\')*)?>$/s', trim( $html ), $m ) ) {
			return null;
		}

		$tag           = new self();
		$tag->original = $html;
		$tag->name     = strtolower( $m[1] );
		$attr_string   = $m[2] ?? '';

		if ( preg_match( '#/\s*$#', $attr_string ) ) {
			$tag->self_closing = true;
			$attr_string       = (string) preg_replace( '#/\s*$#', '', $attr_string );
		}

		if ( '' !== trim( $attr_string ) ) {
			preg_match_all( '/([^\s"\'>\/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/s', $attr_string, $matches, PREG_SET_ORDER );
			foreach ( $matches as $match ) {
				$name = strtolower( $match[1] );
				if ( isset( $tag->attributes[ $name ] ) ) {
					continue; // First occurrence wins, as in browsers.
				}
				if ( isset( $match[4] ) && '' !== $match[4] ) {
					$value = $match[4];
				} elseif ( isset( $match[3] ) && '' !== $match[3] ) {
					$value = str_replace( '"', '&quot;', $match[3] );
				} elseif ( isset( $match[2] ) && '' !== $match[2] ) {
					$value = $match[2];
				} else {
					// Distinguish `alt=""` (empty value) from `alt` (boolean).
					$value = preg_match( '/=\s*(""|\'\')$/', $match[0] ) ? '' : null;
				}
				$tag->attributes[ $name ]     = $value;
				$tag->original_names[ $name ] = $match[1];
			}
		}

		return $tag;
	}

	/**
	 * Create a new tag.
	 *
	 * @param string                    $name       Tag name.
	 * @param array<string,string|null> $attributes Decoded attribute values (will be escaped).
	 */
	public static function create( string $name, array $attributes = array() ): self {
		$tag           = new self();
		$tag->name     = strtolower( $name );
		$tag->original = '';
		$tag->dirty    = true;
		foreach ( $attributes as $key => $value ) {
			$tag->set( $key, $value );
		}
		return $tag;
	}

	/**
	 * Whether an attribute exists.
	 *
	 * @param string $name Attribute.
	 */
	public function has( string $name ): bool {
		return array_key_exists( strtolower( $name ), $this->attributes );
	}

	/**
	 * Raw (entity-encoded) attribute value; '' for boolean attributes; null when missing.
	 *
	 * @param string $name Attribute.
	 */
	public function raw( string $name ): ?string {
		$name = strtolower( $name );
		if ( ! array_key_exists( $name, $this->attributes ) ) {
			return null;
		}
		return $this->attributes[ $name ] ?? '';
	}

	/**
	 * Decoded attribute value; null when missing.
	 *
	 * @param string $name Attribute.
	 */
	public function get( string $name ): ?string {
		$raw = $this->raw( $name );
		return null === $raw ? null : html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Set an attribute from a decoded value (null = boolean attribute).
	 *
	 * @param string      $name  Attribute.
	 * @param string|null $value Decoded value.
	 */
	public function set( string $name, ?string $value ): self {
		$key                          = strtolower( $name );
		$this->attributes[ $key ]     = null === $value ? null : htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false );
		$this->original_names[ $key ] = $this->original_names[ $key ] ?? $name;
		$this->dirty                  = true;
		return $this;
	}

	/**
	 * Remove an attribute.
	 *
	 * @param string $name Attribute.
	 */
	public function remove( string $name ): self {
		$key = strtolower( $name );
		if ( array_key_exists( $key, $this->attributes ) ) {
			unset( $this->attributes[ $key ], $this->original_names[ $key ] );
			$this->dirty = true;
		}
		return $this;
	}

	/**
	 * Class names.
	 *
	 * @return string[]
	 */
	public function classes(): array {
		return array_values( array_filter( preg_split( '/\s+/', (string) $this->get( 'class' ) ) ?: array() ) );
	}

	/**
	 * Whether the tag has a class.
	 *
	 * @param string $class_name Class.
	 */
	public function has_class( string $class_name ): bool {
		return in_array( $class_name, $this->classes(), true );
	}

	/**
	 * Add a class.
	 *
	 * @param string $class_name Class.
	 */
	public function add_class( string $class_name ): self {
		if ( ! $this->has_class( $class_name ) ) {
			$this->set( 'class', trim( implode( ' ', $this->classes() ) . ' ' . $class_name ) );
		}
		return $this;
	}

	/**
	 * All attributes (decoded).
	 *
	 * @return array<string,string|null>
	 */
	public function attributes(): array {
		$out = array();
		foreach ( $this->attributes as $name => $raw ) {
			$out[ $name ] = null === $raw ? null : html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		return $out;
	}

	/**
	 * Whether anything changed since parsing.
	 */
	public function is_dirty(): bool {
		return $this->dirty;
	}

	/**
	 * Serialize. Returns the original markup when nothing changed.
	 */
	public function to_html(): string {
		if ( ! $this->dirty && '' !== $this->original ) {
			return $this->original;
		}

		$html = '<' . $this->name;
		foreach ( $this->attributes as $name => $raw ) {
			$label = $this->original_names[ $name ] ?? $name;
			$html .= null === $raw ? ' ' . $label : ' ' . $label . '="' . $raw . '"';
		}
		return $html . ( $this->self_closing ? ' />' : '>' );
	}

	/**
	 * String form.
	 */
	public function __toString(): string {
		return $this->to_html();
	}
}
