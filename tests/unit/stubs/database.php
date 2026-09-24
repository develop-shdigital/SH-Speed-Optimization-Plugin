<?php
/**
 * Fake `$wpdb` for database and query analysis unit tests.
 *
 * Records every query and answers from a list of regex => response rules,
 * so tests can check the SQL that was built and simulate results/failures.
 * Only defines a class (no functions): this file loads before
 * wordpress.php, which declares its functions without function_exists().
 *
 * @package SH\SpeedOptimizer\Tests
 */

// phpcs:ignoreFile

if ( ! class_exists( 'SHSO_Test_Fake_WPDB' ) ) {
	class SHSO_Test_Fake_WPDB {
		public $prefix             = 'wp_';
		public $base_prefix        = 'wp_';
		public $posts              = 'wp_posts';
		public $postmeta           = 'wp_postmeta';
		public $comments           = 'wp_comments';
		public $commentmeta        = 'wp_commentmeta';
		public $terms              = 'wp_terms';
		public $termmeta           = 'wp_termmeta';
		public $term_taxonomy      = 'wp_term_taxonomy';
		public $term_relationships = 'wp_term_relationships';
		public $options            = 'wp_options';
		public $sitemeta           = '';
		public $last_error         = '';
		public $queries            = array();

		/** @var array<int,array{0:string,1:string}> Method + SQL of every query. */
		public $log = array();

		/** @var array<int,array{0:string,1:mixed}> Regex => response (value or callable( $sql, $method )). */
		public $rules = array();

		/** @var array<int,array{table:string,data:array}> */
		public $inserted = array();

		public $suppress = false;

		public function on( string $pattern, $response ): self {
			$this->rules[] = array( $pattern, $response );
			return $this;
		}

		public function prepare( $query, ...$args ) {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			$index = 0;
			return preg_replace_callback(
				'/%%|%d|%s|%f/',
				function ( $m ) use ( &$index, $args ) {
					if ( '%%' === $m[0] ) {
						return '%';
					}
					$value = $args[ $index++ ] ?? null;
					if ( '%d' === $m[0] ) {
						return (string) (int) $value;
					}
					if ( '%f' === $m[0] ) {
						return (string) (float) $value;
					}
					return "'" . addslashes( (string) $value ) . "'";
				},
				$query
			);
		}

		public function esc_like( $text ) {
			return addcslashes( (string) $text, '_%\\' );
		}

		public function suppress_errors( $suppress = true ) {
			$old            = $this->suppress;
			$this->suppress = (bool) $suppress;
			return $old;
		}

		public function get_var( $sql ) {
			return $this->respond( 'get_var', $sql, null );
		}

		public function get_row( $sql, $output = OBJECT ) {
			return $this->respond( 'get_row', $sql, null );
		}

		public function get_results( $sql, $output = OBJECT ) {
			return $this->respond( 'get_results', $sql, array() );
		}

		public function get_col( $sql ) {
			return $this->respond( 'get_col', $sql, array() );
		}

		public function query( $sql ) {
			return $this->respond( 'query', $sql, 0 );
		}

		public function insert( $table, $data, $format = null ) {
			$this->log[]      = array( 'insert', $table );
			$this->inserted[] = array(
				'table' => $table,
				'data'  => $data,
			);
			foreach ( $this->rules as $rule ) {
				if ( preg_match( $rule[0], 'INSERT INTO ' . $table ) ) {
					return $rule[1] instanceof \Closure ? call_user_func( $rule[1], $table, 'insert', $data ) : $rule[1];
				}
			}
			return 1;
		}

		/**
		 * SQL of all logged queries.
		 *
		 * @return string[]
		 */
		public function sql(): array {
			return array_map( static fn( $entry ) => $entry[1], $this->log );
		}

		private function respond( string $method, $sql, $fallback ) {
			$this->last_error = '';
			$this->log[]      = array( $method, (string) $sql );
			foreach ( $this->rules as $rule ) {
				if ( preg_match( $rule[0], (string) $sql ) ) {
					return $rule[1] instanceof \Closure ? call_user_func( $rule[1], (string) $sql, $method ) : $rule[1];
				}
			}
			return $fallback;
		}
	}
}
