<?php
/**
 * Admin screen: pick a date range, preview the counts, download the workbook.
 *
 * @package LLSCE_Certificate_Report
 */

defined( 'ABSPATH' ) || exit;

class LLSCE_CR_Admin {

	const SLUG            = 'llsce-certificate-report';
	const PREVIEW_NONCE   = 'llsce_cr_preview';
	const DOWNLOAD_NONCE  = 'llsce_cr_download';
	const DOWNLOAD_ACTION = 'llsce_cr_download';

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_post_' . self::DOWNLOAD_ACTION, array( $this, 'handle_download' ) );
	}

	/**
	 * Who may run the report. Defaults to administrators.
	 */
	public static function capability(): string {
		return (string) apply_filters( 'llsce_cr_capability', 'manage_options' );
	}

	/**
	 * Always under Tools; also under the LifterLMS menu when that plugin is active.
	 */
	public function register_menu(): void {
		$title = __( 'LLSCE Certificate Report', 'llsce-certificate-report' );

		if ( class_exists( 'LifterLMS' ) ) {
			add_submenu_page( 'lifterlms', $title, __( 'Certificate Report', 'llsce-certificate-report' ), self::capability(), self::SLUG, array( $this, 'render_page' ) );
		}

		add_management_page( $title, $title, self::capability(), self::SLUG, array( $this, 'render_page' ) );
	}

	/**
	 * Validate a Y-m-d date from the request.
	 *
	 * @return string|null|false The date, null when blank, false when malformed.
	 */
	public static function clean_date( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return null;
		}

		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $raw );

		return ( $date && $date->format( 'Y-m-d' ) === $raw ) ? $raw : false;
	}

	/**
	 * Default range: the half-year we are in.
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function default_range(): array {
		$year  = (int) current_time( 'Y' );
		$month = (int) current_time( 'n' );

		return $month <= 6
			? array( $year . '-01-01', $year . '-06-30' )
			: array( $year . '-07-01', $year . '-12-31' );
	}

	/**
	 * @return array<int, array{label: string, start: string, end: string}>
	 */
	private static function presets(): array {
		$year    = (int) current_time( 'Y' );
		$presets = array();

		foreach ( array( $year, $year - 1 ) as $y ) {
			$presets[] = array(
				'label' => sprintf( 'Jan–Jun %d', $y ),
				'start' => $y . '-01-01',
				'end'   => $y . '-06-30',
			);
			$presets[] = array(
				'label' => sprintf( 'Jul–Dec %d', $y ),
				'start' => $y . '-07-01',
				'end'   => $y . '-12-31',
			);
		}

		$presets[] = array(
			'label' => sprintf( 'All of %d', $year - 1 ),
			'start' => ( $year - 1 ) . '-01-01',
			'end'   => ( $year - 1 ) . '-12-31',
		);
		$presets[] = array(
			'label' => 'All dates',
			'start' => '',
			'end'   => '',
		);

		return $presets;
	}

	public static function filename( ?string $start, ?string $end ): string {
		if ( null === $start && null === $end ) {
			$range = 'all dates';
		} else {
			$range = ( $start ?? 'start' ) . ' to ' . ( $end ?? current_time( 'Y-m-d' ) );
		}

		return 'LLSCE Certificates - ' . $range . '.xlsx';
	}

	public function render_page(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to run this report.', 'llsce-certificate-report' ) );
		}

		list( $start, $end ) = self::default_range();
		$error      = '';
		$previewing = false;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified via wp_verify_nonce below.
		if ( isset( $_GET['llsce_preview'] ) ) {
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), self::PREVIEW_NONCE ) ) {
				$error = __( 'That preview link has expired. Please choose the dates again.', 'llsce-certificate-report' );
			} else {
				$start = self::clean_date( wp_unslash( $_GET['start'] ?? '' ) );
				$end   = self::clean_date( wp_unslash( $_GET['end'] ?? '' ) );
				// phpcs:enable

				if ( false === $start || false === $end ) {
					$error = __( 'Please enter dates as YYYY-MM-DD.', 'llsce-certificate-report' );
					list( $start, $end ) = self::default_range();
				} elseif ( null !== $start && null !== $end && $start > $end ) {
					$error = __( 'The start date must be on or before the end date.', 'llsce-certificate-report' );
				} else {
					$previewing = true;
				}
			}
		}

		$page_url = menu_page_url( self::SLUG, false );
		?>
		<div class="wrap llsce-cr">
			<h1><?php esc_html_e( 'LLSCE Certificate Report', 'llsce-certificate-report' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'One row per certificate earned in the date range, with the learner\'s contact details, license number and state, and pharmacist details. Leave a date blank to leave that end open; leave both blank for every certificate ever issued.', 'llsce-certificate-report' ); ?>
			</p>

			<?php if ( '' !== $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>

			<form method="get" action="<?php echo esc_url( $page_url ); ?>" class="llsce-cr-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<input type="hidden" name="llsce_preview" value="1">
				<?php wp_nonce_field( self::PREVIEW_NONCE ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="llsce-cr-start"><?php esc_html_e( 'Certificates from', 'llsce-certificate-report' ); ?></label></th>
						<td><input type="date" id="llsce-cr-start" name="start" value="<?php echo esc_attr( (string) $start ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="llsce-cr-end"><?php esc_html_e( 'through', 'llsce-certificate-report' ); ?></label></th>
						<td><input type="date" id="llsce-cr-end" name="end" value="<?php echo esc_attr( (string) $end ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Quick ranges', 'llsce-certificate-report' ); ?></th>
						<td>
							<?php foreach ( self::presets() as $preset ) : ?>
								<button type="button" class="button llsce-cr-preset" data-start="<?php echo esc_attr( $preset['start'] ); ?>" data-end="<?php echo esc_attr( $preset['end'] ); ?>"><?php echo esc_html( $preset['label'] ); ?></button>
							<?php endforeach; ?>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Preview counts', 'llsce-certificate-report' ); ?></button>
				</p>
			</form>

			<?php if ( $previewing ) : ?>
				<?php $this->render_preview( $start, $end ); ?>
			<?php endif; ?>

			<h2><?php esc_html_e( 'How the columns are filled', 'llsce-certificate-report' ); ?></h2>
			<ul class="llsce-cr-notes">
				<li><?php esc_html_e( 'certificate_date is the moment the certificate was issued, in the site\'s timezone. The date filter is inclusive of both ends.', 'llsce-certificate-report' ); ?></li>
				<li><?php esc_html_e( 'license_num and license_state hold the values the learner entered for the course this certificate belongs to (matched by the activity code at the start of certificate_title). They stay blank when that course did not collect them.', 'llsce-certificate-report' ); ?></li>
				<li><?php esc_html_e( 'LIC_NUM and LIC_STATE are the values to use: the course\'s own entry when there is one, otherwise the learner\'s most recently saved entry from another course, otherwise their profile entry.', 'llsce-certificate-report' ); ?></li>
				<li><?php esc_html_e( 'NEW INFO NUM / NEW INFO STATE mark rows whose LIC_NUM or LIC_STATE came from another course or the profile rather than this certificate\'s course. MISSING INFO marks rows where either is still blank.', 'llsce-certificate-report' ); ?></li>
				<li><?php esc_html_e( 'PHARM DOB and PHARM NAPB are resolved the same way from the pharmacist fields. Trashed certificates are excluded.', 'llsce-certificate-report' ); ?></li>
			</ul>
		</div>

		<style>
			.llsce-cr .llsce-cr-preset { margin: 0 6px 6px 0; }
			.llsce-cr .llsce-cr-summary { display: flex; flex-wrap: wrap; gap: 12px; margin: 12px 0 16px; }
			.llsce-cr .llsce-cr-tile { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 12px 16px; min-width: 150px; }
			.llsce-cr .llsce-cr-tile strong { display: block; font-size: 24px; line-height: 1.2; }
			.llsce-cr .llsce-cr-tile span { color: #50575e; }
			.llsce-cr table.llsce-cr-titles { max-width: 640px; }
			.llsce-cr table.llsce-cr-titles td:last-child { text-align: right; }
			.llsce-cr .llsce-cr-notes { list-style: disc; margin-left: 20px; max-width: 900px; }
		</style>
		<script>
			document.querySelectorAll('.llsce-cr-preset').forEach(function (button) {
				button.addEventListener('click', function () {
					document.getElementById('llsce-cr-start').value = button.dataset.start;
					document.getElementById('llsce-cr-end').value = button.dataset.end;
				});
			});
		</script>
		<?php
	}

	private function render_preview( ?string $start, ?string $end ): void {
		global $wpdb;

		$report  = new LLSCE_CR_Report( $wpdb, $start, $end );
		$summary = LLSCE_CR_Report::summarize( $report->rows() );
		?>
		<hr>
		<h2><?php echo esc_html( self::range_label( $start, $end ) ); ?></h2>

		<?php if ( 0 === $summary['certificates'] ) : ?>
			<p><?php esc_html_e( 'No certificates were issued in this range.', 'llsce-certificate-report' ); ?></p>
			<?php
			return;
		endif;
		?>

		<div class="llsce-cr-summary">
			<div class="llsce-cr-tile"><strong><?php echo esc_html( number_format_i18n( $summary['certificates'] ) ); ?></strong><span><?php esc_html_e( 'certificates (rows)', 'llsce-certificate-report' ); ?></span></div>
			<div class="llsce-cr-tile"><strong><?php echo esc_html( number_format_i18n( $summary['learners'] ) ); ?></strong><span><?php esc_html_e( 'learners', 'llsce-certificate-report' ); ?></span></div>
			<div class="llsce-cr-tile"><strong><?php echo esc_html( number_format_i18n( $summary['missing_info'] ) ); ?></strong><span><?php esc_html_e( 'missing license info', 'llsce-certificate-report' ); ?></span></div>
			<div class="llsce-cr-tile"><strong><?php echo esc_html( number_format_i18n( $summary['new_info'] ) ); ?></strong><span><?php esc_html_e( 'license from another course or profile', 'llsce-certificate-report' ); ?></span></div>
			<div class="llsce-cr-tile"><strong><?php echo esc_html( number_format_i18n( $summary['no_address'] ) ); ?></strong><span><?php esc_html_e( 'no address on file', 'llsce-certificate-report' ); ?></span></div>
		</div>

		<p>
			<?php
			printf(
				/* translators: 1: first date, 2: last date */
				esc_html__( 'Earliest certificate %1$s, latest %2$s.', 'llsce-certificate-report' ),
				esc_html( (string) $summary['first_date'] ),
				esc_html( (string) $summary['last_date'] )
			);
			?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::DOWNLOAD_ACTION ); ?>">
			<input type="hidden" name="start" value="<?php echo esc_attr( (string) $start ); ?>">
			<input type="hidden" name="end" value="<?php echo esc_attr( (string) $end ); ?>">
			<?php wp_nonce_field( self::DOWNLOAD_NONCE ); ?>
			<p class="submit">
				<button type="submit" class="button button-primary button-hero">
					<?php
					printf(
						/* translators: %s: file name */
						esc_html__( 'Download %s', 'llsce-certificate-report' ),
						esc_html( self::filename( $start, $end ) )
					);
					?>
				</button>
			</p>
		</form>

		<h3><?php esc_html_e( 'Certificates by template', 'llsce-certificate-report' ); ?></h3>
		<table class="widefat striped llsce-cr-titles">
			<thead><tr><th><?php esc_html_e( 'certificate_title', 'llsce-certificate-report' ); ?></th><th><?php esc_html_e( 'Rows', 'llsce-certificate-report' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $summary['by_title'] as $title => $count ) : ?>
					<tr><td><?php echo esc_html( $title ); ?></td><td><?php echo esc_html( number_format_i18n( $count ) ); ?></td></tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function range_label( ?string $start, ?string $end ): string {
		if ( null === $start && null === $end ) {
			return __( 'All certificates', 'llsce-certificate-report' );
		}
		if ( null === $start ) {
			/* translators: %s: date */
			return sprintf( __( 'Certificates through %s', 'llsce-certificate-report' ), $end );
		}
		if ( null === $end ) {
			/* translators: %s: date */
			return sprintf( __( 'Certificates from %s onward', 'llsce-certificate-report' ), $start );
		}

		/* translators: 1: start date, 2: end date */
		return sprintf( __( 'Certificates from %1$s through %2$s', 'llsce-certificate-report' ), $start, $end );
	}

	/**
	 * admin-post.php handler: build the workbook and stream it.
	 */
	public function handle_download(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to run this report.', 'llsce-certificate-report' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::DOWNLOAD_NONCE );

		$start = self::clean_date( wp_unslash( $_POST['start'] ?? '' ) );
		$end   = self::clean_date( wp_unslash( $_POST['end'] ?? '' ) );

		if ( false === $start || false === $end || ( null !== $start && null !== $end && $start > $end ) ) {
			wp_die( esc_html__( 'Please enter a valid date range as YYYY-MM-DD.', 'llsce-certificate-report' ), '', array( 'response' => 400 ) );
		}

		global $wpdb;

		wp_raise_memory_limit( 'admin' );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$path = wp_tempnam( 'llsce-certificates.xlsx' );

		try {
			$report = new LLSCE_CR_Report( $wpdb, $start, $end );
			$writer = new LLSCE_CR_Xlsx_Writer(
				'Users',
				LLSCE_CR_Report::COLUMNS,
				LLSCE_CR_Report::COLUMN_TYPES,
				LLSCE_CR_Report::COLUMN_WIDTHS,
				'USERS'
			);
			$writer->write( $report->rows(), $path );
		} catch ( Throwable $throwable ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
			wp_die( esc_html( $throwable->getMessage() ), '', array( 'response' => 500 ) );
		}

		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="' . self::filename( $start, $end ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );

		if ( ob_get_level() ) {
			ob_end_clean();
		}

		readfile( $path );
		unlink( $path );
		exit;
	}
}
