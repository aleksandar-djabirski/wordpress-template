<?php
/**
 * Proves the save boundary through the real validator, including the classic
 * admin path that does not use the REST controller.
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

// Test-only WordPress parser and global API stand-ins are kept here because
// the unit suite does not bootstrap WordPress. The production files remain
// covered by the normal project coding rules.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed, Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden, Universal.Namespaces.DisallowDeclarationWithoutName.Forbidden, Universal.Namespaces.OneDeclarationPerFile.MultipleFound, PSR2.Namespaces.NamespaceDeclaration.BlankLineAfter, WordPress.Security.EscapeOutput.ExceptionNotEscaped
namespace {

	$wordpress_includes = dirname( __DIR__, 3 ) . '/web/wp/wp-includes';

	if ( ! class_exists( 'WP_Block_Parser_Block', false ) ) {
		require_once $wordpress_includes . '/class-wp-block-parser-block.php';
	}

	if ( ! class_exists( 'WP_Block_Parser_Frame', false ) ) {
		require_once $wordpress_includes . '/class-wp-block-parser-frame.php';
	}

	if ( ! class_exists( 'WP_Block_Parser', false ) ) {
		require_once $wordpress_includes . '/class-wp-block-parser.php';
	}

	if ( ! function_exists( 'parse_blocks' ) ) {
		require_once $wordpress_includes . '/blocks.php';
	}

	if ( ! class_exists( 'WP_REST_Request' ) ) {
		final class WP_REST_Request {

			/**
			 * @var array<string, mixed>
			 */
			private array $params = array();

			public function __construct( string $method = 'GET', string $route = '' ) {
				unset( $method, $route );
			}

			public function set_param( string $key, mixed $value ): void {
				$this->params[ $key ] = $value;
			}

			public function get_param( string $key ): mixed {
				return $this->params[ $key ] ?? null;
			}
		}
	}

	if ( ! class_exists( 'WP_Block_Editor_Context' ) ) {
		final class WP_Block_Editor_Context {

			public string $name;

			public mixed $post;

			/**
			 * @param array<string, mixed> $settings
			 */
			public function __construct( array $settings = array() ) {
				$this->name = (string) ( $settings['name'] ?? '' );
				$this->post = $settings['post'] ?? null;
			}
		}
	}

	if ( ! class_exists( 'WP_Post' ) ) {
		final class WP_Post {

			public int $ID;

			public string $post_type;

			public function __construct( int $id, string $post_type ) {
				$this->ID        = $id;
				$this->post_type = $post_type;
			}
		}
	}

	if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
		final class WP_Block_Type_Registry {

			private static ?self $instance = null;

			/**
			 * @var array<string, mixed>
			 */
			private array $registered = array(
				'core/paragraph' => true,
				'core/group'     => true,
				'core/html'      => true,
				'core/shortcode' => true,
				'core/freeform'  => true,
			);

			public static function get_instance(): self {
				if ( null === self::$instance ) {
					self::$instance = new self();
				}

				return self::$instance;
			}

			/**
			 * @return array<string, mixed>
			 */
			public function get_all_registered(): array {
				return $this->registered;
			}

			/**
			 * @param list<string> $registered_names
			 */
			public function set_registered( array $registered_names ): void {
				$this->registered = array();

				foreach ( $registered_names as $registered_name ) {
					$this->registered[ $registered_name ] = true;
				}
			}

			public function is_registered( string $block_name ): bool {
				return isset( $this->registered[ $block_name ] );
			}
		}
	}

	if ( ! class_exists( 'SaveValidationTestWpDie' ) ) {
		final class SaveValidationTestWpDie extends \RuntimeException {

			/**
			 * @var array<string, mixed>
			 */
			public array $arguments;

			/**
			 * @param array<string, mixed> $arguments
			 */
			public function __construct( string $message, array $arguments ) {
				$this->arguments = $arguments;
				parent::__construct( $message );
			}
		}
	}

	if ( ! function_exists( 'get_post' ) ) {
		function get_post( mixed $post_id = 0, mixed $output = null ): mixed {
			unset( $output );

			return $GLOBALS['_test_get_posts'][ (int) $post_id ] ?? null;
		}
	}

	if ( ! function_exists( 'get_shortcode_regex' ) ) {
		/**
		 * @param array<int, string>|null $tagnames
		 */
		function get_shortcode_regex( ?array $tagnames = null ): string {
			unset( $tagnames );

			return '\\[(\\[?)(gallery|contact\\-form)(?![\\w-])([^\\]\\/]*(?:\\/(?!\\])[^\]\\/]*?)*?)(?:(\\/)\\]|\\](?:([^\\[]*+(?:\\[(?!\\/\\2\\])[^\[]*+)*+)\\[\\/\\2\\])?)(\\]?)';
		}
	}

	if ( ! function_exists( 'is_admin' ) ) {
		function is_admin(): bool {
			return (bool) ( $GLOBALS['_test_is_admin'] ?? false );
		}
	}

	if ( ! function_exists( 'wp_unslash' ) ) {
		function wp_unslash( mixed $value ): mixed {
			return is_string( $value ) ? stripslashes( $value ) : $value;
		}
	}

	if ( ! function_exists( 'wp_die' ) ) {
		/**
		 * @param mixed                 $message
		 * @param string                $title
		 * @param array<string, mixed>  $arguments
		 */
		function wp_die( $message, string $title = '', array $arguments = array() ): never {
			throw new SaveValidationTestWpDie( (string) $message, $arguments );
		}
	}
}
namespace Tests\Unit\AgencyPlatform {

	use AgencyPlatform\Editor\SaveValidation;
	use PHPUnit\Framework\TestCase;

	/**
	 * @covers \AgencyPlatform\Editor\SaveValidation
	 */
	final class SaveValidationPolicyTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();

			$GLOBALS['_test_current_user_caps'] = array();
			$GLOBALS['_test_filters']           = array();
			$GLOBALS['_test_get_posts']         = array();
			$GLOBALS['_test_is_admin']          = false;
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The unit test resets the WordPress screen context between cases.
			$GLOBALS['pagenow'] = '';
			$_REQUEST           = array();
		}

		protected function tearDown(): void {
			$GLOBALS['_test_current_user_caps'] = array();
			$GLOBALS['_test_filters']           = array();
			$GLOBALS['_test_get_posts']         = array();
			$GLOBALS['_test_is_admin']          = false;
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The unit test resets the WordPress screen context between cases.
			$GLOBALS['pagenow'] = '';
			$_REQUEST           = array();

			parent::tearDown();
		}

		private function request( string $content, string $post_type = 'page' ): \WP_REST_Request {
			$request = new \WP_REST_Request( 'POST', '/wp/v2/pages/42' );
			$request->set_param( 'content', $content );
			$request->set_param( 'type', $post_type );

			return $request;
		}

		private function prepared( string $post_type = 'page' ): object {
			return (object) array( 'post_type' => $post_type );
		}

		/**
		 * @param string[]                 $blocks
		 * @param \WP_Block_Editor_Context $context
		 * @return string[]
		 */
		public function allow_paragraph( array $blocks, \WP_Block_Editor_Context $context ): array {
			return array( 'core/paragraph' );
		}

		public function test_validate_content_refuses_a_forbidden_block_with_details(): void {
			$result = ( new SaveValidation() )->validate_content(
				$this->prepared(),
				$this->request( '<!-- wp:html --><div>raw</div><!-- /wp:html -->' )
			);

			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'agency_platform_forbidden_block', $result->get_error_code() );
			self::assertSame( 'The block "core/html" is not allowed for your role (found at core/html). Remove it and save again.', $result->get_error_message() );
			self::assertSame(
				array(
					'status'     => 403,
					'violations' => array(
						array(
							'block' => 'core/html',
							'path'  => 'core/html',
						),
					),
				),
				$result->get_error_data()
			);
		}

		public function test_validate_content_refuses_raw_html_with_details(): void {
			$result = ( new SaveValidation() )->validate_content(
				$this->prepared(),
				$this->request( '<script>alert(1)</script>' )
			);

			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'agency_platform_raw_html', $result->get_error_code() );
			self::assertSame( 'Raw HTML is not allowed for your role. Rebuild this content with blocks and save again.', $result->get_error_message() );
			self::assertSame(
				array(
					'status'     => 403,
					'violations' => array( '<script>alert(1)</script>' ),
				),
				$result->get_error_data()
			);
		}

		public function test_validate_content_refuses_a_registered_shortcode_with_details(): void {
			add_filter( 'agency_platform_allowed_blocks', array( $this, 'allow_paragraph' ), 10, 2 );

			$result = ( new SaveValidation() )->validate_content(
				$this->prepared(),
				$this->request( '<!-- wp:paragraph --><p>Call [contact-form] now</p><!-- /wp:paragraph -->' )
			);

			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'agency_platform_shortcode', $result->get_error_code() );
			self::assertSame( 'The shortcode [contact-form] is not allowed for your role. Remove it and save again.', $result->get_error_message() );
			self::assertSame(
				array(
					'status'     => 403,
					'violations' => array( 'contact-form' ),
				),
				$result->get_error_data()
			);
		}

		public function test_register_adds_the_classic_save_filter(): void {
			( new SaveValidation() )->register();

			self::assertArrayHasKey( 'wp_insert_post_data', $GLOBALS['_test_filters'] );
			self::assertSame( 'validate_classic_save', $GLOBALS['_test_filters']['wp_insert_post_data'][10][0]['callback'][1] );
		}

		public function test_classic_admin_save_refuses_forbidden_content_with_the_same_message(): void {
			$GLOBALS['_test_is_admin'] = true;
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The unit test supplies the classic admin screen context.
			$GLOBALS['pagenow'] = 'post.php';
			$_REQUEST           = array(
				'action'  => 'editpost',
				'post_ID' => 42,
			);

			try {
				( new SaveValidation() )->validate_classic_save(
					array(
						'ID'           => 42,
						'post_type'    => 'page',
						'post_content' => '<script>alert(1)</script>',
					),
					array(
						'ID'           => 42,
						'post_type'    => 'page',
						'post_content' => '<script>alert(1)</script>',
					)
				);
			} catch ( \SaveValidationTestWpDie $exception ) {
				self::assertSame( 'Raw HTML is not allowed for your role. Rebuild this content with blocks and save again.', $exception->getMessage() );
				self::assertSame( 403, $exception->arguments['response'] );

				return;
			}

			self::fail( 'A classic admin save with raw HTML must be refused.' );
		}

		public function test_programmatic_insert_outside_the_classic_admin_request_is_untouched(): void {
			$data = array(
				'post_type'    => 'page',
				'post_content' => '<script>alert(1)</script>',
			);

			self::assertSame( $data, ( new SaveValidation() )->validate_classic_save( $data, $data ) );
		}

		public function test_custom_template_updates_keep_the_site_editor_context(): void {
			$GLOBALS['_test_get_posts'][42] = new \WP_Post( 42, 'wp_template' );

			$context = SaveValidation::editor_context(
				(object) array( 'ID' => 42 ),
				new \WP_REST_Request( 'POST', '/wp/v2/templates/theme//single' )
			);

			self::assertSame( 'core/edit-site', $context->name );
		}
	}
}
