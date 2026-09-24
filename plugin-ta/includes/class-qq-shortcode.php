<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QQ_Shortcode {

	private static $instance = null;
	private $rendered = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			add_shortcode( 'quranic_quiz', array( self::$instance, 'render' ) );
			add_action( 'wp_enqueue_scripts', array( self::$instance, 'maybe_enqueue' ) );
		}
		return self::$instance;
	}

	/**
	 * We only want to load the (sizeable) quiz JS/CSS/jsPDF bundle on pages
	 * that actually use the shortcode, so we scan the post content early.
	 */
	public function maybe_enqueue() {
		if ( is_singular() ) {
			global $post;
			if ( $post && has_shortcode( $post->post_content, 'quranic_quiz' ) ) {
				$this->enqueue_assets();
			}
		}
	}

	/**
	 * Cache-busting version string for a plugin asset. Uses the file's own
	 * last-modified time instead of the static QQ_VERSION constant, so every
	 * time this file is re-uploaded (even without bumping a version number
	 * anywhere) browsers and any server/CDN cache automatically see it as a
	 * brand-new URL and fetch the fresh copy instead of an old cached one.
	 */
	private function asset_version( $relative_path ) {
		$file = QQ_PLUGIN_DIR . $relative_path;
		return file_exists( $file ) ? (string) filemtime( $file ) : QQ_VERSION;
	}

	private function enqueue_assets() {
		wp_enqueue_style( 'qq-quiz', QQ_PLUGIN_URL . 'assets/css/quiz.css', array(), $this->asset_version( 'assets/css/quiz.css' ) );
		wp_enqueue_script( 'qq-jspdf', QQ_PLUGIN_URL . 'assets/vendor/jspdf.umd.min.js', array(), '2.5.1', true );
		wp_enqueue_script( 'qq-quiz-app', QQ_PLUGIN_URL . 'assets/js/quiz-app.js', array( 'qq-jspdf' ), $this->asset_version( 'assets/js/quiz-app.js' ), true );

		$settings = QQ_DB::get_settings();

		wp_localize_script( 'qq-quiz-app', 'QQ_CONFIG', array(
			'restUrl'          => esc_url_raw( rest_url( QQ_REST::NAMESPACE_ ) ),
			'googleClientId'   => $settings['google_client_id'],
			'questionsPerQuiz' => intval( $settings['questions_per_quiz'] ),
			'timeLimitSeconds' => intval( $settings['time_limit_seconds'] ),
			'siteName'         => $settings['site_name'],
			'certificateTitle' => $settings['certificate_title'],
		) );
	}

	public function render( $atts ) {
		// Belt-and-braces: if the shortcode is added dynamically (widget,
		// PHP snippet, template) after wp_enqueue_scripts already ran,
		// make sure assets still load.
		if ( ! wp_script_is( 'qq-quiz-app', 'enqueued' ) ) {
			$this->enqueue_assets();
		}

		ob_start();
		?>
		<div id="qq-quiz-root" class="qq-root" data-qq-app="1">
			<div class="qq-loading">
				<div class="qq-loading-spinner"></div>
				<p>Loading the Quranic Quiz…</p>
				<noscript>Please enable JavaScript to take the quiz.</noscript>
			</div>
		</div>
		<?php
		if ( empty( QQ_DB::get_settings()['google_client_id'] ) && current_user_can( 'manage_options' ) ) {
			echo '<p style="max-width:640px;margin:1em auto;padding:12px 16px;background:#fff3cd;border:1px solid #ffe69c;border-radius:8px;font-size:14px;">' .
				esc_html__( 'Quranic Quiz: Google Sign-In is not configured yet. Only site admins see this note — go to Quranic Quiz → Settings to add your Google Client ID.', 'quranic-quiz' ) .
				'</p>';
		}
		return ob_get_clean();
	}
}
