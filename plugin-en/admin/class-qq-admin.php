<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QQ_Admin {

	private static $instance = null;
	public $notices = array();
	public $question_form_values = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->hooks();
		}
		return self::$instance;
	}

	private function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function assets( $hook ) {
		if ( strpos( $hook, 'quranic-quiz' ) === false ) {
			return;
		}
		wp_enqueue_style( 'qq-admin', QQ_PLUGIN_URL . 'assets/css/admin.css', array(), QQ_VERSION );
	}

	public function menu() {
		add_menu_page(
			'Quranic Quiz', 'Quranic Quiz', 'manage_options', 'quranic-quiz',
			array( $this, 'render_dashboard' ), 'dashicons-lightbulb', 58
		);
		add_submenu_page( 'quranic-quiz', 'Dashboard', 'Dashboard', 'manage_options', 'quranic-quiz', array( $this, 'render_dashboard' ) );
		add_submenu_page( 'quranic-quiz', 'Questions', 'Questions', 'manage_options', 'quranic-quiz-questions', array( $this, 'render_questions' ) );
		add_submenu_page( 'quranic-quiz', 'Add Question', 'Add Question', 'manage_options', 'quranic-quiz-add', array( $this, 'render_add_question' ) );
		add_submenu_page( 'quranic-quiz', 'Import Questions', 'Import Questions', 'manage_options', 'quranic-quiz-import', array( $this, 'render_import' ) );
		add_submenu_page( 'quranic-quiz', 'Settings', 'Settings', 'manage_options', 'quranic-quiz-settings', array( $this, 'render_settings' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Handle form posts                                                    */
	/* ------------------------------------------------------------------ */

	public function handle_actions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['qq_save_settings'] ) && check_admin_referer( 'qq_save_settings' ) ) {
			$settings = array(
				'google_client_id'   => isset( $_POST['google_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['google_client_id'] ) ) : '',
				'questions_per_quiz' => isset( $_POST['questions_per_quiz'] ) ? max( 1, intval( $_POST['questions_per_quiz'] ) ) : QQ_DEFAULT_QUESTIONS_PER_QUIZ,
				'time_limit_seconds' => isset( $_POST['time_limit_minutes'] ) ? max( 1, intval( $_POST['time_limit_minutes'] ) ) * 60 : QQ_DEFAULT_TIME_LIMIT_SECONDS,
				'site_name'          => isset( $_POST['site_name'] ) ? sanitize_text_field( wp_unslash( $_POST['site_name'] ) ) : '',
				'certificate_title'  => isset( $_POST['certificate_title'] ) ? sanitize_text_field( wp_unslash( $_POST['certificate_title'] ) ) : '',
			);
			QQ_DB::update_settings( $settings );
			$this->notices[] = array( 'success', 'Settings saved.' );
		}

		if ( isset( $_POST['qq_import_csv'] ) && check_admin_referer( 'qq_import_csv' ) ) {
			$this->handle_csv_import();
		}

		if ( isset( $_POST['qq_toggle_question'] ) && check_admin_referer( 'qq_toggle_question' ) ) {
			global $wpdb;
			$id = intval( $_POST['question_id'] );
			$active = intval( $_POST['new_active'] );
			$wpdb->update( QQ_DB::questions_table(), array( 'active' => $active ), array( 'id' => $id ), array( '%d' ), array( '%d' ) ); // phpcs:ignore
			$this->notices[] = array( 'success', 'Question updated.' );
		}

		if ( isset( $_POST['qq_delete_all_questions'] ) && check_admin_referer( 'qq_delete_all_questions' ) ) {
			global $wpdb;
			$wpdb->query( 'TRUNCATE TABLE ' . QQ_DB::questions_table() ); // phpcs:ignore
			$this->notices[] = array( 'success', 'All questions removed.' );
		}

		if ( isset( $_POST['qq_save_question'] ) && check_admin_referer( 'qq_save_question' ) ) {
			$this->handle_save_question();
		}

		if ( isset( $_POST['qq_delete_question'] ) && check_admin_referer( 'qq_delete_question' ) ) {
			global $wpdb;
			$id = intval( $_POST['question_id'] );
			$wpdb->delete( QQ_DB::questions_table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore
			$this->notices[] = array( 'success', 'Question deleted.' );
		}
	}

	/**
	 * Add or update a single question typed directly into the admin form.
	 * Reuses QQ_Importer::normalize_row() so a manually-typed question is
	 * validated exactly the same way a CSV row is — same required-field and
	 * "4 options for MCQ" rules, nothing invented on either path.
	 */
	private function handle_save_question() {
		global $wpdb;
		$table = QQ_DB::questions_table();

		$question_id = isset( $_POST['question_id'] ) ? intval( $_POST['question_id'] ) : 0;

		$raw = array(
			'question'       => isset( $_POST['qq_question'] ) ? wp_unslash( $_POST['qq_question'] ) : '',
			'qtype'          => isset( $_POST['qq_qtype'] ) ? wp_unslash( $_POST['qq_qtype'] ) : 'mcq',
			'option_a'       => isset( $_POST['qq_option_a'] ) ? wp_unslash( $_POST['qq_option_a'] ) : '',
			'option_b'       => isset( $_POST['qq_option_b'] ) ? wp_unslash( $_POST['qq_option_b'] ) : '',
			'option_c'       => isset( $_POST['qq_option_c'] ) ? wp_unslash( $_POST['qq_option_c'] ) : '',
			'option_d'       => isset( $_POST['qq_option_d'] ) ? wp_unslash( $_POST['qq_option_d'] ) : '',
			'correct'        => isset( $_POST['qq_correct'] ) ? wp_unslash( $_POST['qq_correct'] ) : '',
			'difficulty'     => isset( $_POST['qq_difficulty'] ) ? wp_unslash( $_POST['qq_difficulty'] ) : '',
			'category'       => isset( $_POST['qq_category'] ) ? wp_unslash( $_POST['qq_category'] ) : '',
			'reference'      => isset( $_POST['qq_reference'] ) ? wp_unslash( $_POST['qq_reference'] ) : '',
			'explanation'    => isset( $_POST['qq_explanation'] ) ? wp_unslash( $_POST['qq_explanation'] ) : '',
			'active'         => isset( $_POST['qq_active'] ) ? 1 : 0,
		);

		// True/False-style questions only use 2 options — drop blanks so
		// normalize_row() sees the real option count instead of padding.
		if ( 'tf' === $raw['qtype'] ) {
			if ( '' === trim( (string) $raw['option_c'] ) ) { unset( $raw['option_c'] ); }
			if ( '' === trim( (string) $raw['option_d'] ) ) { unset( $raw['option_d'] ); }
		}

		$clean = QQ_Importer::normalize_row( $raw, 0 );
		if ( is_wp_error( $clean ) ) {
			$this->notices[]        = array( 'error', $clean->get_error_message() );
			$this->question_form_values = $raw + array( 'id' => $question_id );
			return;
		}

		$now  = current_time( 'mysql' );
		$data = array(
			'question'       => $clean['question'],
			'qtype'          => $clean['qtype'],
			'option_a'       => $clean['option_a'],
			'option_b'       => $clean['option_b'],
			'option_c'       => $clean['option_c'],
			'option_d'       => $clean['option_d'],
			'correct_option' => $clean['correct_option'],
			'difficulty'     => $clean['difficulty'],
			'category'       => $clean['category'],
			'reference'      => $clean['reference'],
			'explanation'    => $clean['explanation'],
			'active'         => $raw['active'],
			'updated_at'     => $now,
		);
		$formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' );

		if ( $question_id > 0 ) {
			$wpdb->update( $table, $data, array( 'id' => $question_id ), $formats, array( '%d' ) ); // phpcs:ignore
			$this->notices[] = array( 'success', 'Question #' . $question_id . ' updated.' );
		} else {
			$data['created_at'] = $now;
			$formats[]           = '%s';
			$wpdb->insert( $table, $data, $formats ); // phpcs:ignore
			$this->notices[] = array( 'success', 'New question added (ID #' . (int) $wpdb->insert_id . ').' );
		}
	}

	private function handle_csv_import() {
		if ( empty( $_FILES['qq_csv_file']['tmp_name'] ) ) {
			$this->notices[] = array( 'error', 'Please choose a CSV file to upload.' );
			return;
		}
		$tmp_name = $_FILES['qq_csv_file']['tmp_name'];
		$rows = QQ_Importer::parse_csv( $tmp_name );
		if ( is_wp_error( $rows ) ) {
			$this->notices[] = array( 'error', $rows->get_error_message() );
			return;
		}

		$replace = ! empty( $_POST['qq_replace_existing'] );
		$result = QQ_Importer::bulk_insert_questions( $rows, $replace );

		$msg = sprintf( '%d question(s) imported successfully.', $result['inserted'] );
		$this->notices[] = array( 'success', $msg );
		if ( ! empty( $result['errors'] ) ) {
			$count = count( $result['errors'] );
			$sample = array_slice( $result['errors'], 0, 10 );
			$this->notices[] = array(
				'error',
				sprintf( '%d row(s) were skipped and not imported:', $count ) . '<br>' . esc_html( implode( '<br>', $sample ) ) . ( $count > 10 ? '<br>…' : '' )
			);
		}
	}

	/* ------------------------------------------------------------------ */
	/* Page: Dashboard                                                      */
	/* ------------------------------------------------------------------ */

	public function render_dashboard() {
		global $wpdb;
		$q_table = QQ_DB::questions_table();
		$u_table = QQ_DB::users_table();
		$a_table = QQ_DB::attempts_table();

		$total_q   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$q_table}" );
		$active_q  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$q_table} WHERE active = 1" );
		$by_diff   = $wpdb->get_results( "SELECT difficulty, COUNT(*) as c FROM {$q_table} WHERE active = 1 GROUP BY difficulty", ARRAY_A );
		$total_u   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$u_table}" );
		$total_att = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$a_table} WHERE status = 'completed'" );
		$avg_pct   = $wpdb->get_var( "SELECT AVG(percentage) FROM {$a_table} WHERE status = 'completed'" );
		$recent    = $wpdb->get_results( // phpcs:ignore
			"SELECT a.*, u.name, u.email FROM {$a_table} a INNER JOIN {$u_table} u ON u.id = a.user_id
			 WHERE a.status = 'completed' ORDER BY a.finished_at DESC LIMIT 15", ARRAY_A
		);

		$diff_counts = array( 'easy' => 0, 'medium' => 0, 'hard' => 0 );
		foreach ( $by_diff as $row ) {
			$diff_counts[ $row['difficulty'] ] = (int) $row['c'];
		}

		$settings = QQ_DB::get_settings();
		$google_ok = ! empty( $settings['google_client_id'] );
		?>
		<div class="wrap qq-admin">
			<h1>Quranic Quiz — Dashboard</h1>
			<?php $this->render_notices(); ?>

			<?php if ( ! $google_ok ) : ?>
				<div class="notice notice-warning"><p><strong>Google Sign-In isn't configured yet.</strong> Visitors won't be able to sign in until you add a Google Client ID in <a href="<?php echo esc_url( admin_url( 'admin.php?page=quranic-quiz-settings' ) ); ?>">Settings</a>.</p></div>
			<?php endif; ?>

			<div class="qq-stat-cards">
				<div class="qq-stat-card"><span class="qq-num"><?php echo esc_html( $active_q ); ?></span><span class="qq-lbl">Active questions</span><span class="qq-sub"><?php echo esc_html( $total_q - $active_q ); ?> inactive</span></div>
				<div class="qq-stat-card"><span class="qq-num"><?php echo esc_html( $diff_counts['easy'] ); ?> / <?php echo esc_html( $diff_counts['medium'] ); ?> / <?php echo esc_html( $diff_counts['hard'] ); ?></span><span class="qq-lbl">Easy / Medium / Hard</span></div>
				<div class="qq-stat-card"><span class="qq-num"><?php echo esc_html( $total_u ); ?></span><span class="qq-lbl">Signed-in users</span></div>
				<div class="qq-stat-card"><span class="qq-num"><?php echo esc_html( $total_att ); ?></span><span class="qq-lbl">Quizzes completed</span></div>
				<div class="qq-stat-card"><span class="qq-num"><?php echo $avg_pct !== null ? esc_html( round( $avg_pct, 1 ) ) . '%' : '—'; ?></span><span class="qq-lbl">Average score</span></div>
			</div>

			<h2>Recent attempts</h2>
			<table class="widefat striped">
				<thead><tr><th>User</th><th>Email</th><th>Difficulty</th><th>Score</th><th>%</th><th>Time</th><th>Ended</th><th>Date</th></tr></thead>
				<tbody>
				<?php if ( empty( $recent ) ) : ?>
					<tr><td colspan="8">No quiz attempts yet.</td></tr>
				<?php else : foreach ( $recent as $r ) : ?>
					<tr>
						<td><?php echo esc_html( $r['name'] ); ?></td>
						<td><?php echo esc_html( $r['email'] ); ?></td>
						<td><?php echo esc_html( ucfirst( $r['difficulty'] ) ); ?></td>
						<td><?php echo esc_html( $r['score'] . '/' . $r['total_questions'] ); ?></td>
						<td><?php echo esc_html( round( $r['percentage'], 1 ) ); ?>%</td>
						<td><?php echo esc_html( gmdate( 'i:s', (int) $r['time_taken_seconds'] ) ); ?></td>
						<td><?php echo esc_html( 'time_up' === $r['ended_reason'] ? 'Time expired' : 'Submitted' ); ?></td>
						<td><?php echo esc_html( $r['finished_at'] ); ?></td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Page: Questions                                                     */
	/* ------------------------------------------------------------------ */

	public function render_questions() {
		global $wpdb;
		$q_table = QQ_DB::questions_table();

		$paged   = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1; // phpcs:ignore
		$per_page = 30;
		$offset  = ( $paged - 1 ) * $per_page;

		$filter_diff = isset( $_GET['difficulty'] ) ? sanitize_text_field( wp_unslash( $_GET['difficulty'] ) ) : ''; // phpcs:ignore
		$where = '1=1';
		if ( in_array( $filter_diff, array( 'easy', 'medium', 'hard' ), true ) ) {
			$where .= $wpdb->prepare( ' AND difficulty = %s', $filter_diff );
		}

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$q_table} WHERE {$where}" ); // phpcs:ignore
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$q_table} WHERE {$where} ORDER BY id ASC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A ); // phpcs:ignore
		$total_pages = max( 1, ceil( $total / $per_page ) );
		?>
		<div class="wrap qq-admin">
			<h1>Quranic Quiz — Questions <span style="font-weight:400;font-size:14px;">(<?php echo esc_html( $total ); ?> total)</span></h1>
			<?php $this->render_notices(); ?>

			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=quranic-quiz-questions' ) ); ?>" class="<?php echo '' === $filter_diff ? 'current' : ''; ?>">All</a> |</li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=quranic-quiz-questions&difficulty=easy' ) ); ?>" class="<?php echo 'easy' === $filter_diff ? 'current' : ''; ?>">Easy</a> |</li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=quranic-quiz-questions&difficulty=medium' ) ); ?>" class="<?php echo 'medium' === $filter_diff ? 'current' : ''; ?>">Medium</a> |</li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=quranic-quiz-questions&difficulty=hard' ) ); ?>" class="<?php echo 'hard' === $filter_diff ? 'current' : ''; ?>">Hard</a></li>
			</ul>

			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=quranic-quiz-add' ) ); ?>" class="button button-primary">+ Add New Question</a></p>

			<table class="widefat striped">
				<thead><tr><th style="width:60px;">ID</th><th>Question</th><th>Type</th><th>Options</th><th>Correct</th><th>Difficulty</th><th>Active</th><th style="width:170px;">Actions</th></tr></thead>
				<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['id'] ); ?></td>
						<td><?php echo esc_html( $row['question'] ); ?></td>
						<td><?php echo esc_html( strtoupper( $row['qtype'] ) ); ?></td>
						<td style="font-size:12px;">
							A: <?php echo esc_html( $row['option_a'] ); ?><br>
							B: <?php echo esc_html( $row['option_b'] ); ?><br>
							<?php if ( $row['option_c'] ) : ?>C: <?php echo esc_html( $row['option_c'] ); ?><br><?php endif; ?>
							<?php if ( $row['option_d'] ) : ?>D: <?php echo esc_html( $row['option_d'] ); ?><?php endif; ?>
						</td>
						<td><strong><?php echo esc_html( $row['correct_option'] ); ?></strong></td>
						<td><?php echo esc_html( ucfirst( $row['difficulty'] ) ); ?></td>
						<td>
							<form method="post" style="margin:0;">
								<?php wp_nonce_field( 'qq_toggle_question' ); ?>
								<input type="hidden" name="question_id" value="<?php echo esc_attr( $row['id'] ); ?>">
								<input type="hidden" name="new_active" value="<?php echo $row['active'] ? '0' : '1'; ?>">
								<button type="submit" name="qq_toggle_question" value="1" class="button button-small">
									<?php echo $row['active'] ? 'Deactivate' : 'Activate'; ?>
								</button>
							</form>
						</td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=quranic-quiz-add&edit=' . $row['id'] ) ); ?>" class="button button-small">Edit</a>
							<form method="post" style="display:inline;margin:0;" onsubmit="return confirm('Delete this question permanently?');">
								<?php wp_nonce_field( 'qq_delete_question' ); ?>
								<input type="hidden" name="question_id" value="<?php echo esc_attr( $row['id'] ); ?>">
								<button type="submit" name="qq_delete_question" value="1" class="button button-small button-link-delete">Delete</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post( paginate_links( array(
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'current'   => $paged,
						'total'     => $total_pages,
					) ) );
					?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Page: Add / Edit Question                                           */
	/* ------------------------------------------------------------------ */

	public function render_add_question() {
		global $wpdb;

		$editing = false;
		$v = array(
			'id' => 0, 'question' => '', 'qtype' => 'mcq',
			'option_a' => '', 'option_b' => '', 'option_c' => '', 'option_d' => '',
			'correct' => 'A', 'difficulty' => 'medium',
			'category' => '', 'reference' => '', 'explanation' => '', 'active' => 1,
		);

		if ( ! empty( $this->question_form_values ) ) {
			// A save just failed validation — repopulate exactly what was typed.
			$v = array_merge( $v, $this->question_form_values );
			$v['correct'] = isset( $this->question_form_values['correct'] ) ? $this->question_form_values['correct'] : $v['correct'];
			$editing = ! empty( $v['id'] );
		} elseif ( isset( $_GET['edit'] ) && intval( $_GET['edit'] ) > 0 ) { // phpcs:ignore
			$id  = intval( $_GET['edit'] ); // phpcs:ignore
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . QQ_DB::questions_table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore
			if ( $row ) {
				$editing = true;
				$v = array(
					'id' => $row['id'], 'question' => $row['question'], 'qtype' => $row['qtype'],
					'option_a' => $row['option_a'], 'option_b' => $row['option_b'],
					'option_c' => $row['option_c'], 'option_d' => $row['option_d'],
					'correct' => $row['correct_option'], 'difficulty' => $row['difficulty'],
					'category' => $row['category'], 'reference' => $row['reference'],
					'explanation' => $row['explanation'], 'active' => $row['active'],
				);
			}
		}
		?>
		<div class="wrap qq-admin">
			<h1>Quranic Quiz — <?php echo $editing ? 'Edit Question #' . esc_html( $v['id'] ) : 'Add a New Question'; ?></h1>
			<?php $this->render_notices(); ?>

			<div class="qq-admin-card">
				<form method="post">
					<?php wp_nonce_field( 'qq_save_question' ); ?>
					<input type="hidden" name="question_id" value="<?php echo esc_attr( $v['id'] ); ?>">

					<table class="form-table">
						<tr>
							<th><label for="qq_question">Question text</label></th>
							<td><textarea name="qq_question" id="qq_question" rows="3" class="large-text" required><?php echo esc_textarea( $v['question'] ); ?></textarea></td>
						</tr>
						<tr>
							<th>Question type</th>
							<td>
								<label><input type="radio" name="qq_qtype" value="mcq" id="qq_type_mcq" <?php checked( 'mcq', $v['qtype'] ); ?>> Multiple choice (4 options)</label>
								&nbsp;&nbsp;
								<label><input type="radio" name="qq_qtype" value="tf" id="qq_type_tf" <?php checked( 'tf', $v['qtype'] ); ?>> True / False (2 options)</label>
							</td>
						</tr>
						<tr>
							<th>Options &amp; correct answer</th>
							<td>
								<p class="description" style="margin-top:0;">Mark the correct option using the radio button on its left.</p>
								<p>
									<label><input type="radio" name="qq_correct" value="A" <?php checked( 'A', $v['correct'] ); ?>></label>
									<input type="text" name="qq_option_a" placeholder="Option A" class="regular-text" style="width:420px;" value="<?php echo esc_attr( $v['option_a'] ); ?>" required>
								</p>
								<p>
									<label><input type="radio" name="qq_correct" value="B" <?php checked( 'B', $v['correct'] ); ?>></label>
									<input type="text" name="qq_option_b" placeholder="Option B" class="regular-text" style="width:420px;" value="<?php echo esc_attr( $v['option_b'] ); ?>" required>
								</p>
								<p class="qq-opt-cd">
									<label><input type="radio" name="qq_correct" value="C" <?php checked( 'C', $v['correct'] ); ?>></label>
									<input type="text" name="qq_option_c" placeholder="Option C" class="regular-text" style="width:420px;" value="<?php echo esc_attr( $v['option_c'] ); ?>">
								</p>
								<p class="qq-opt-cd">
									<label><input type="radio" name="qq_correct" value="D" <?php checked( 'D', $v['correct'] ); ?>></label>
									<input type="text" name="qq_option_d" placeholder="Option D" class="regular-text" style="width:420px;" value="<?php echo esc_attr( $v['option_d'] ); ?>">
								</p>
							</td>
						</tr>
						<tr>
							<th><label for="qq_difficulty">Difficulty</label></th>
							<td>
								<select name="qq_difficulty" id="qq_difficulty">
									<option value="easy" <?php selected( 'easy', $v['difficulty'] ); ?>>Easy</option>
									<option value="medium" <?php selected( 'medium', $v['difficulty'] ); ?>>Medium</option>
									<option value="hard" <?php selected( 'hard', $v['difficulty'] ); ?>>Hard</option>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="qq_reference">Reference <span style="font-weight:400;">(optional)</span></label></th>
							<td><input type="text" name="qq_reference" id="qq_reference" class="regular-text" placeholder="e.g. Quran 2:255" value="<?php echo esc_attr( $v['reference'] ); ?>"></td>
						</tr>
						<tr>
							<th><label for="qq_category">Category <span style="font-weight:400;">(optional)</span></label></th>
							<td><input type="text" name="qq_category" id="qq_category" class="regular-text" value="<?php echo esc_attr( $v['category'] ); ?>"></td>
						</tr>
						<tr>
							<th><label for="qq_explanation">Explanation <span style="font-weight:400;">(optional, shown in the answer review)</span></label></th>
							<td><textarea name="qq_explanation" id="qq_explanation" rows="2" class="large-text"><?php echo esc_textarea( $v['explanation'] ); ?></textarea></td>
						</tr>
						<tr>
							<th>Status</th>
							<td><label><input type="checkbox" name="qq_active" value="1" <?php checked( 1, (int) $v['active'] ); ?>> Active (included in quizzes immediately)</label></td>
						</tr>
					</table>
					<p class="submit">
						<button type="submit" name="qq_save_question" value="1" class="button button-primary"><?php echo $editing ? 'Update Question' : 'Add Question'; ?></button>
						<?php if ( $editing ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=quranic-quiz-add' ) ); ?>" class="button">Cancel / Add a different question</a>
						<?php endif; ?>
					</p>
				</form>
			</div>

			<div class="qq-admin-card">
				<h2>Adding many questions at once?</h2>
				<p>This form is best for one-off additions or quick edits. If you have a batch of questions ready in a spreadsheet, the <a href="<?php echo esc_url( admin_url( 'admin.php?page=quranic-quiz-import' ) ); ?>">Import Questions</a> page (CSV upload) is faster.</p>
			</div>
		</div>
		<script>
		(function () {
			var mcq = document.getElementById( 'qq_type_mcq' );
			var tf  = document.getElementById( 'qq_type_tf' );
			var cdFields = document.querySelectorAll( '.qq-opt-cd' );
			function sync() {
				var show = ! tf || ! tf.checked;
				cdFields.forEach( function ( el ) {
					el.style.display = show ? '' : 'none';
					var input = el.querySelector( 'input[type="text"]' );
					if ( input ) { input.required = show; }
					// Switching to True/False while C or D was marked correct would
					// otherwise leave an invisible, invalid selection behind.
					if ( ! show ) {
						var radio = el.querySelector( 'input[type="radio"]' );
						if ( radio && radio.checked ) {
							var first = document.querySelector( 'input[name="qq_correct"][value="A"]' );
							if ( first ) { first.checked = true; }
						}
					}
				} );
			}
			if ( mcq ) { mcq.addEventListener( 'change', sync ); }
			if ( tf ) { tf.addEventListener( 'change', sync ); }
			sync();
		})();
		</script>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Page: Import                                                        */
	/* ------------------------------------------------------------------ */

	public function render_import() {
		?>
		<div class="wrap qq-admin">
			<h1>Quranic Quiz — Import Questions</h1>
			<?php $this->render_notices(); ?>

			<div class="qq-admin-card">
				<h2>Import from CSV</h2>
				<p>Upload a CSV with these column headers (case-insensitive): <code>question, option_a, option_b, option_c, option_d, correct_answer, difficulty</code> (optionally also <code>category, reference, explanation</code>).</p>
				<p><code>correct_answer</code> can be <code>A</code>/<code>B</code>/<code>C</code>/<code>D</code> or <code>1</code>/<code>2</code>/<code>3</code>/<code>4</code>. <code>difficulty</code> can be <code>Easy</code>/<code>Medium</code>/<code>Hard</code> or <code>E</code>/<code>M</code>/<code>H</code>. True/False style questions can leave <code>option_c</code> / <code>option_d</code> blank.</p>
				<p><a href="<?php echo esc_url( QQ_PLUGIN_URL . 'data/question-import-template.csv' ); ?>" class="button">⬇ Download CSV template</a></p>

				<form method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( 'qq_import_csv' ); ?>
					<table class="form-table">
						<tr>
							<th><label for="qq_csv_file">CSV file</label></th>
							<td><input type="file" name="qq_csv_file" id="qq_csv_file" accept=".csv" required></td>
						</tr>
						<tr>
							<th>Import mode</th>
							<td>
								<label><input type="radio" name="qq_replace_existing" value="" checked> Add to existing question bank</label><br>
								<label><input type="radio" name="qq_replace_existing" value="1"> ⚠ Replace the entire question bank with this file</label>
							</td>
						</tr>
					</table>
					<p class="submit"><button type="submit" name="qq_import_csv" value="1" class="button button-primary">Import questions</button></p>
				</form>
			</div>

			<div class="qq-admin-card">
				<h2>Danger zone</h2>
				<form method="post" onsubmit="return confirm('This will permanently delete ALL questions. Are you sure?');">
					<?php wp_nonce_field( 'qq_delete_all_questions' ); ?>
					<button type="submit" name="qq_delete_all_questions" value="1" class="button button-link-delete">Delete all questions</button>
				</form>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Page: Settings                                                      */
	/* ------------------------------------------------------------------ */

	public function render_settings() {
		$s = QQ_DB::get_settings();
		?>
		<div class="wrap qq-admin">
			<h1>Quranic Quiz — Settings</h1>
			<?php $this->render_notices(); ?>

			<div class="qq-admin-card">
				<form method="post">
					<?php wp_nonce_field( 'qq_save_settings' ); ?>
					<table class="form-table">
						<tr>
							<th><label for="google_client_id">Google OAuth Client ID</label></th>
							<td>
								<input type="text" class="regular-text" style="width:480px;" name="google_client_id" id="google_client_id" value="<?php echo esc_attr( $s['google_client_id'] ); ?>" placeholder="xxxxxxxxxx-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.apps.googleusercontent.com">
								<p class="description">Required for visitors to sign in with Google. See the plugin's INSTALL.md for step-by-step instructions to create one in Google Cloud Console (it's free).</p>
							</td>
						</tr>
						<tr>
							<th><label for="questions_per_quiz">Questions per quiz</label></th>
							<td><input type="number" min="1" max="50" name="questions_per_quiz" id="questions_per_quiz" value="<?php echo esc_attr( $s['questions_per_quiz'] ); ?>"></td>
						</tr>
						<tr>
							<th><label for="time_limit_minutes">Timer (minutes)</label></th>
							<td><input type="number" min="1" max="60" name="time_limit_minutes" id="time_limit_minutes" value="<?php echo esc_attr( round( $s['time_limit_seconds'] / 60 ) ); ?>"></td>
						</tr>
						<tr>
							<th><label for="site_name">Site name (shown on quiz & certificate)</label></th>
							<td><input type="text" class="regular-text" name="site_name" id="site_name" value="<?php echo esc_attr( $s['site_name'] ); ?>"></td>
						</tr>
						<tr>
							<th><label for="certificate_title">Certificate title</label></th>
							<td><input type="text" class="regular-text" name="certificate_title" id="certificate_title" value="<?php echo esc_attr( $s['certificate_title'] ); ?>"></td>
						</tr>
					</table>
					<p class="submit"><button type="submit" name="qq_save_settings" value="1" class="button button-primary">Save settings</button></p>
				</form>
			</div>

			<div class="qq-admin-card">
				<h2>Where to add the quiz</h2>
				<p>Add this shortcode to any page (e.g. your <code>/quran-qa/</code> page):</p>
				<p><code style="font-size:15px;background:#f0f0f1;padding:6px 10px;display:inline-block;">[quranic_quiz]</code></p>
			</div>
		</div>
		<?php
	}

	private function render_notices() {
		foreach ( $this->notices as $n ) {
			echo '<div class="notice notice-' . esc_attr( $n[0] ) . ' is-dismissible"><p>' . wp_kses_post( $n[1] ) . '</p></div>';
		}
	}
}
