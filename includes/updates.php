<?php
/**
 * Milieus by Therum — self-update from GitHub releases + ZIP upload + rollback.
 *
 * Three sources of truth:
 *
 *   GITHUB     latest release from github.com/TherumCs/Milieus (cached 15 min)
 *   ZIP        admin uploads a Pure release ZIP from disk
 *   BACKUP    restore from any prior milieus.bak.{ts}/ sibling directory
 *
 * Apply flow (same for github + zip):
 *   1. validate the ZIP (size, format, has milieus/ at root)
 *   2. extract to a temp dir
 *   3. rename the live milieus/ → milieus.bak.{timestamp}/
 *   4. move extracted milieus/ into place
 *   5. flush rewrites
 *
 * Rollback flow: rename live milieus/ → milieus.bak.rollback-of-{ts}/, then
 * rename the chosen backup back to milieus/. Nothing is deleted; everything
 * stays beside the plugin folder until the user manually clears them.
 *
 * Routes (admin-post.php):
 *   POST milieus_updates_check    — refresh the GitHub cache
 *   POST milieus_updates_apply    — apply latest GitHub release
 *   POST milieus_updates_upload   — apply uploaded ZIP
 *   POST milieus_updates_rollback — restore a chosen backup
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const MILIEUS_REPO        = 'TherumCs/Milieus';
const MILIEUS_UPDATES_TTL = 15 * MINUTE_IN_SECONDS;
const MILIEUS_MAX_ZIP_MB  = 50;

// ── GitHub release fetch ──────────────────────────────────────────────

/**
 * Latest release metadata. Cached in a transient so we don't hammer GitHub.
 * Returns null on network error (also cached so the UI doesn't spin).
 *
 * @return array{ version:string, body:string, html_url:string, zip_url:string }|null
 */
function milieus_updates_latest( bool $force = false ): ?array {
	$key = 'milieus_updates_latest';
	if ( ! $force ) {
		$cached = get_transient( $key );
		if ( $cached !== false ) return $cached ?: null;
	}

	$url = 'https://api.github.com/repos/' . MILIEUS_REPO . '/releases/latest';
	$res = wp_remote_get( $url, [
		'timeout' => 8,
		'headers' => [
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'Milieus/' . MILIEUS_VERSION,
		],
	] );

	if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) !== 200 ) {
		set_transient( $key, '', MILIEUS_UPDATES_TTL );
		return null;
	}
	$json = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( ! is_array( $json ) ) {
		set_transient( $key, '', MILIEUS_UPDATES_TTL );
		return null;
	}

	// Prefer milieus.zip asset if it exists (built by our release pipeline);
	// fall back to the auto-generated source zipball.
	$zip_url = $json['zipball_url'] ?? '';
	foreach ( (array) ( $json['assets'] ?? [] ) as $asset ) {
		if ( ( $asset['name'] ?? '' ) === 'milieus.zip' ) {
			$zip_url = $asset['browser_download_url'] ?? $zip_url;
			break;
		}
	}

	$out = [
		'version'  => ltrim( (string) ( $json['tag_name'] ?? '' ), 'v' ),
		'body'     => (string) ( $json['body'] ?? '' ),
		'html_url' => (string) ( $json['html_url'] ?? '' ),
		'zip_url'  => $zip_url,
	];
	set_transient( $key, $out, MILIEUS_UPDATES_TTL );
	return $out;
}

// ── Apply ─────────────────────────────────────────────────────────────

/**
 * Apply a ZIP file to the live plugin directory. Backs up first.
 *
 * @throws RuntimeException on any failure.
 * @return array{ from:string, to:string, backup_path:string }
 */
function milieus_updates_apply_zip( string $zip_path, string $declared_version = '' ): array {
	if ( ! file_exists( $zip_path ) || filesize( $zip_path ) === 0 ) {
		throw new RuntimeException( 'ZIP file is missing or empty.' );
	}
	if ( filesize( $zip_path ) > MILIEUS_MAX_ZIP_MB * MB_IN_BYTES ) {
		throw new RuntimeException( 'ZIP exceeds ' . MILIEUS_MAX_ZIP_MB . ' MB cap.' );
	}

	$plugin_dir = untrailingslashit( MILIEUS_DIR );
	$parent     = dirname( $plugin_dir );

	// Stage in WP uploads tmp.
	$upload  = wp_upload_dir();
	$stage   = trailingslashit( $upload['basedir'] ) . 'milieus-stage-' . wp_generate_password( 8, false );
	if ( ! wp_mkdir_p( $stage ) ) throw new RuntimeException( 'Cannot create staging directory.' );

	try {
		// Extract.
		WP_Filesystem();
		global $wp_filesystem;
		$result = unzip_file( $zip_path, $stage );
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( 'Unzip failed: ' . $result->get_error_message() );
		}

		// Find the milieus/ root inside (handles GitHub's "TherumCs-Milieus-{sha}/" wrapper).
		$root = milieus_updates_find_plugin_root( $stage );
		if ( ! $root ) throw new RuntimeException( 'ZIP does not contain a milieus/ directory.' );

		if ( ! file_exists( $root . '/milieus.php' ) ) {
			throw new RuntimeException( 'ZIP is missing milieus.php at the root.' );
		}

		$from_version = MILIEUS_VERSION;
		$to_version   = $declared_version ?: milieus_updates_read_version( $root . '/milieus.php' ) ?: 'unknown';

		// Backup live dir.
		$backup_name = 'milieus.bak.' . gmdate( 'Ymd-His' );
		$backup_path = $parent . '/' . $backup_name;
		if ( ! rename( $plugin_dir, $backup_path ) ) {
			throw new RuntimeException( 'Could not back up live plugin directory (permissions?).' );
		}

		// Move staged into place.
		if ( ! rename( $root, $plugin_dir ) ) {
			// Rollback the rename if move failed.
			rename( $backup_path, $plugin_dir );
			throw new RuntimeException( 'Could not move new release into place. Restored previous version.' );
		}

		flush_rewrite_rules();

		return [
			'from'        => $from_version,
			'to'          => $to_version,
			'backup_path' => $backup_path,
		];
	} finally {
		// Best-effort cleanup of staging dir.
		milieus_updates_rm_recursive( $stage );
	}
}

/**
 * Apply the latest GitHub release — downloads the ZIP first, then hands off
 * to milieus_updates_apply_zip().
 */
function milieus_updates_apply_github(): array {
	$latest = milieus_updates_latest( true );
	if ( ! $latest || empty( $latest['zip_url'] ) ) {
		throw new RuntimeException( 'No release ZIP found on GitHub.' );
	}

	$tmp = download_url( $latest['zip_url'], 60 );
	if ( is_wp_error( $tmp ) ) {
		throw new RuntimeException( 'Download failed: ' . $tmp->get_error_message() );
	}

	try {
		return milieus_updates_apply_zip( $tmp, $latest['version'] );
	} finally {
		@unlink( $tmp );
	}
}

// ── Backups + rollback ────────────────────────────────────────────────

/**
 * List all milieus.bak.* directories beside the live plugin folder.
 * Newest first.
 *
 * @return array<array{ name:string, path:string, created:int, version:string }>
 */
function milieus_updates_list_backups(): array {
	$parent = dirname( untrailingslashit( MILIEUS_DIR ) );
	$out    = [];
	foreach ( (array) glob( $parent . '/milieus.bak.*', GLOB_ONLYDIR ) as $dir ) {
		$out[] = [
			'name'    => basename( $dir ),
			'path'    => $dir,
			'created' => (int) filemtime( $dir ),
			'version' => milieus_updates_read_version( $dir . '/milieus.php' ),
		];
	}
	usort( $out, fn( $a, $b ) => $b['created'] - $a['created'] );
	return $out;
}

/**
 * Restore a backup. The current live directory is itself backed up first
 * (named milieus.bak.rollback-of-{originalTimestamp}), so rollback is
 * itself reversible.
 *
 * @return array{ from:string, to:string, backup_path:string }
 */
function milieus_updates_rollback( string $backup_name ): array {
	$parent = dirname( untrailingslashit( MILIEUS_DIR ) );
	$source = $parent . '/' . basename( $backup_name );
	if ( ! is_dir( $source ) || strpos( basename( $source ), 'milieus.bak.' ) !== 0 ) {
		throw new RuntimeException( 'Backup not found or invalid name.' );
	}
	if ( ! file_exists( $source . '/milieus.php' ) ) {
		throw new RuntimeException( 'Backup is missing milieus.php — refusing to swap.' );
	}

	$from_version = MILIEUS_VERSION;
	$to_version   = milieus_updates_read_version( $source . '/milieus.php' ) ?: 'unknown';

	$plugin_dir = untrailingslashit( MILIEUS_DIR );
	$snapshot   = $parent . '/milieus.bak.rollback-' . gmdate( 'Ymd-His' );
	if ( ! rename( $plugin_dir, $snapshot ) ) {
		throw new RuntimeException( 'Could not snapshot current version for rollback.' );
	}
	if ( ! rename( $source, $plugin_dir ) ) {
		rename( $snapshot, $plugin_dir );
		throw new RuntimeException( 'Could not restore backup. Reverted to current.' );
	}

	flush_rewrite_rules();

	return [
		'from'        => $from_version,
		'to'          => $to_version,
		'backup_path' => $snapshot,
	];
}

/**
 * Permanently delete a backup directory.
 */
function milieus_updates_delete_backup( string $backup_name ): void {
	$parent = dirname( untrailingslashit( MILIEUS_DIR ) );
	$path   = $parent . '/' . basename( $backup_name );
	if ( ! is_dir( $path ) || strpos( basename( $path ), 'milieus.bak.' ) !== 0 ) {
		throw new RuntimeException( 'Refusing to delete: invalid backup name.' );
	}
	milieus_updates_rm_recursive( $path );
}

// ── Helpers ───────────────────────────────────────────────────────────

/**
 * Recursively find the directory inside $stage that looks like the plugin
 * root (contains milieus.php). Handles both:
 *   stage/milieus/milieus.php          (clean zip)
 *   stage/TherumCs-Milieus-abc123/milieus.php  (GitHub source zip wraps it)
 */
function milieus_updates_find_plugin_root( string $stage ): ?string {
	// Direct hit?
	if ( file_exists( $stage . '/milieus/milieus.php' ) ) {
		return $stage . '/milieus';
	}
	// One-level wrapper?
	foreach ( (array) glob( $stage . '/*', GLOB_ONLYDIR ) as $dir ) {
		if ( file_exists( $dir . '/milieus.php' ) ) {
			return $dir;
		}
		if ( file_exists( $dir . '/milieus/milieus.php' ) ) {
			return $dir . '/milieus';
		}
	}
	return null;
}

/**
 * Read the Version header from a milieus.php file. Returns '' if unreadable.
 */
function milieus_updates_read_version( string $file ): string {
	if ( ! is_readable( $file ) ) return '';
	$head = file_get_contents( $file, false, null, 0, 4096 );
	if ( $head === false ) return '';
	return preg_match( '/^\s*\*\s*Version:\s*(.+)$/mi', $head, $m ) ? trim( $m[1] ) : '';
}

function milieus_updates_rm_recursive( string $path ): void {
	if ( ! file_exists( $path ) ) return;
	if ( is_file( $path ) || is_link( $path ) ) { @unlink( $path ); return; }
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $it as $entry ) {
		$entry->isDir() ? @rmdir( $entry->getPathname() ) : @unlink( $entry->getPathname() );
	}
	@rmdir( $path );
}

// ── admin-post.php handlers ───────────────────────────────────────────

add_action( 'admin_post_milieus_updates_check', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'forbidden', 403 );
	check_admin_referer( 'milieus_updates' );
	milieus_updates_latest( true );
	wp_safe_redirect( admin_url( 'admin.php?page=milieus-updates&flash=checked' ) );
	exit;
} );

add_action( 'admin_post_milieus_updates_apply', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'forbidden', 403 );
	check_admin_referer( 'milieus_updates' );
	try {
		$r = milieus_updates_apply_github();
		wp_safe_redirect( admin_url( 'admin.php?page=milieus-updates&flash=applied&from=' . rawurlencode( $r['from'] ) . '&to=' . rawurlencode( $r['to'] ) . '&backup=' . rawurlencode( basename( $r['backup_path'] ) ) ) );
	} catch ( Throwable $e ) {
		wp_safe_redirect( admin_url( 'admin.php?page=milieus-updates&error=' . rawurlencode( $e->getMessage() ) ) );
	}
	exit;
} );

add_action( 'admin_post_milieus_updates_upload', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'forbidden', 403 );
	check_admin_referer( 'milieus_updates' );

	if ( empty( $_FILES['milieus_zip']['tmp_name'] ) ) {
		wp_safe_redirect( admin_url( 'admin.php?page=milieus-updates&error=' . rawurlencode( 'No file uploaded.' ) ) );
		exit;
	}
	try {
		$r = milieus_updates_apply_zip( $_FILES['milieus_zip']['tmp_name'] );
		wp_safe_redirect( admin_url( 'admin.php?page=milieus-updates&flash=applied&from=' . rawurlencode( $r['from'] ) . '&to=' . rawurlencode( $r['to'] ) . '&backup=' . rawurlencode( basename( $r['backup_path'] ) ) ) );
	} catch ( Throwable $e ) {
		wp_safe_redirect( admin_url( 'admin.php?page=milieus-updates&error=' . rawurlencode( $e->getMessage() ) ) );
	}
	exit;
} );

add_action( 'admin_post_milieus_updates_rollback', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'forbidden', 403 );
	check_admin_referer( 'milieus_updates' );
	$backup = sanitize_file_name( $_POST['backup'] ?? '' );
	try {
		$r = milieus_updates_rollback( $backup );
		wp_safe_redirect( admin_url( 'admin.php?page=milieus-updates&flash=rolledback&from=' . rawurlencode( $r['from'] ) . '&to=' . rawurlencode( $r['to'] ) ) );
	} catch ( Throwable $e ) {
		wp_safe_redirect( admin_url( 'admin.php?page=milieus-updates&error=' . rawurlencode( $e->getMessage() ) ) );
	}
	exit;
} );

add_action( 'admin_post_milieus_updates_delete_backup', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'forbidden', 403 );
	check_admin_referer( 'milieus_updates' );
	$backup = sanitize_file_name( $_POST['backup'] ?? '' );
	try {
		milieus_updates_delete_backup( $backup );
		wp_safe_redirect( admin_url( 'admin.php?page=milieus-updates&flash=deleted' ) );
	} catch ( Throwable $e ) {
		wp_safe_redirect( admin_url( 'admin.php?page=milieus-updates&error=' . rawurlencode( $e->getMessage() ) ) );
	}
	exit;
} );

// ── Admin menu + view ─────────────────────────────────────────────────

add_action( 'admin_menu', function() {
	add_submenu_page(
		'milieus-roles',
		__( 'Updates', 'milieus' ),
		__( 'Updates', 'milieus' ),
		'manage_options',
		'milieus-updates',
		'milieus_render_updates_page'
	);
}, 20 );

function milieus_render_updates_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'forbidden', 403 );

	$current = MILIEUS_VERSION;
	$latest  = milieus_updates_latest();
	$backups = milieus_updates_list_backups();
	$nonce   = wp_create_nonce( 'milieus_updates' );

	// Flash messages from PRG redirects.
	$flash = sanitize_key( $_GET['flash'] ?? '' );
	$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
	$from  = isset( $_GET['from'] )  ? sanitize_text_field( wp_unslash( $_GET['from'] ) )  : '';
	$to    = isset( $_GET['to'] )    ? sanitize_text_field( wp_unslash( $_GET['to'] ) )    : '';

	?>
	<div class="wrap"><div class="th-cx">
		<div class="th-cx-head">
			<h1 class="th-cx-title"><?php esc_html_e( 'Updates', 'milieus' ); ?></h1>
			<p class="th-cx-sub">
				<?php esc_html_e( 'Read the latest release from GitHub, apply a ZIP from disk, or roll back to any prior version. Backups stay on disk beside the plugin until you delete them.', 'milieus' ); ?>
				<code style="margin-left:8px"><?php echo esc_html( MILIEUS_REPO ); ?></code>
			</p>
		</div>

		<?php if ( $error ): ?>
			<div class="th-flash th-flash-err">✗ <?php echo esc_html( $error ); ?></div>
		<?php elseif ( $flash === 'applied' ): ?>
			<div class="th-flash th-flash-ok">✓ Applied <?php echo esc_html( $from . ' → ' . $to ); ?>. Backup retained.</div>
		<?php elseif ( $flash === 'rolledback' ): ?>
			<div class="th-flash th-flash-ok">✓ Rolled back <?php echo esc_html( $from . ' → ' . $to ); ?>. Previous version saved as a new backup.</div>
		<?php elseif ( $flash === 'checked' ): ?>
			<div class="th-flash th-flash-ok">✓ Refreshed from GitHub.</div>
		<?php elseif ( $flash === 'deleted' ): ?>
			<div class="th-flash th-flash-ok">✓ Backup deleted.</div>
		<?php endif; ?>

		<?php milieus_settings_group(
			__( 'GitHub release', 'milieus' ),
			__( 'Compares the installed version with the latest release at the configured repo. Cached for 15 minutes.', 'milieus' ),
			function() use ( $current, $latest, $nonce ) {
				?>
				<div class="th-updates-grid">
					<div class="th-updates-card">
						<div class="th-updates-card-label"><?php esc_html_e( 'Installed', 'milieus' ); ?></div>
						<div class="th-updates-card-num"><?php echo esc_html( $current ); ?></div>
					</div>
					<div class="th-updates-card">
						<div class="th-updates-card-label"><?php esc_html_e( 'Latest on GitHub', 'milieus' ); ?></div>
						<?php if ( $latest ): $newer = version_compare( $latest['version'], $current, '>' ); ?>
							<div class="th-updates-card-num" style="color:<?php echo $newer ? 'var(--ok)' : 'var(--tx3)'; ?>"><?php echo esc_html( $latest['version'] ); ?></div>
							<div class="th-updates-status"><?php echo $newer ? esc_html__( 'UPDATE AVAILABLE', 'milieus' ) : esc_html__( 'UP TO DATE', 'milieus' ); ?></div>
						<?php else: ?>
							<div class="th-updates-card-num" style="color:var(--tx3)">—</div>
							<div class="th-updates-status"><?php esc_html_e( "Couldn't reach GitHub", 'milieus' ); ?></div>
						<?php endif; ?>
					</div>
				</div>

				<div class="th-updates-actions">
					<?php if ( $latest && version_compare( $latest['version'], $current, '>' ) ): ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Replace the live milieus/ directory with <?php echo esc_js( $latest['version'] ); ?>? A backup will be taken automatically.')">
							<input type="hidden" name="action" value="milieus_updates_apply">
							<?php wp_nonce_field( 'milieus_updates' ); ?>
							<button class="th-button th-button-primary"><?php printf( esc_html__( 'Apply %s', 'milieus' ), esc_html( $latest['version'] ) ); ?></button>
						</form>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="milieus_updates_check">
						<?php wp_nonce_field( 'milieus_updates' ); ?>
						<button class="th-button"><?php esc_html_e( 'Refresh', 'milieus' ); ?></button>
					</form>
					<?php if ( $latest && ! empty( $latest['html_url'] ) ): ?>
						<a class="th-link-btn" href="<?php echo esc_url( $latest['html_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View on GitHub ↗', 'milieus' ); ?></a>
					<?php endif; ?>
				</div>

				<?php if ( $latest && ! empty( $latest['body'] ) ): ?>
					<h4 class="th-updates-sectionhead"><?php esc_html_e( 'Release notes', 'milieus' ); ?></h4>
					<pre class="th-updates-notes"><?php echo esc_html( $latest['body'] ); ?></pre>
				<?php endif; ?>
				<?php
			}
		); ?>

		<?php milieus_settings_group(
			__( 'Drop a ZIP', 'milieus' ),
			__( "Apply a Milieus release ZIP from disk — useful for offline installs, fork builds, or testing a local build. Same backup-and-swap flow as GitHub. Max " . MILIEUS_MAX_ZIP_MB . " MB.", 'milieus' ),
			function() use ( $nonce ) {
				?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" onsubmit="return confirm('Apply this ZIP? The live milieus/ directory will be backed up first.')" class="th-updates-uploader">
					<input type="hidden" name="action" value="milieus_updates_upload">
					<?php wp_nonce_field( 'milieus_updates' ); ?>
					<input type="file" name="milieus_zip" accept=".zip,application/zip" required>
					<button type="submit" class="th-button th-button-primary"><?php esc_html_e( 'Apply ZIP', 'milieus' ); ?></button>
				</form>
				<p class="th-updates-help">
					<?php esc_html_e( 'Accepts a ZIP with milieus/ at the root, or a single-dir wrapper (GitHub source ZIPs are auto-detected).', 'milieus' ); ?>
				</p>
				<?php
			}
		); ?>

		<?php milieus_settings_group(
			__( 'Backups', 'milieus' ),
			__( 'Every apply and rollback leaves a backup beside the plugin folder. Restore any of them, or delete the ones you no longer need.', 'milieus' ),
			function() use ( $backups, $nonce ) {
				if ( ! $backups ) {
					echo '<p class="th-updates-help">' . esc_html__( 'No backups yet. The first one is taken automatically the next time you apply an update.', 'milieus' ) . '</p>';
					return;
				}
				?>
				<table class="th-roles-table">
					<thead><tr>
						<th><?php esc_html_e( 'Backup', 'milieus' ); ?></th>
						<th style="width:120px"><?php esc_html_e( 'Version', 'milieus' ); ?></th>
						<th style="width:200px"><?php esc_html_e( 'Created', 'milieus' ); ?></th>
						<th style="width:240px;text-align:right"></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $backups as $b ): ?>
						<tr>
							<td><code style="font-size:12px"><?php echo esc_html( $b['name'] ); ?></code></td>
							<td><?php echo esc_html( $b['version'] ?: '—' ); ?></td>
							<td class="th-roles-caps"><?php echo esc_html( wp_date( 'M j, Y · g:i a', $b['created'] ) ); ?></td>
							<td style="text-align:right">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('Roll back to <?php echo esc_js( $b['name'] ); ?>? The current version will be saved as a new backup.')">
									<input type="hidden" name="action" value="milieus_updates_rollback">
									<input type="hidden" name="backup" value="<?php echo esc_attr( $b['name'] ); ?>">
									<?php wp_nonce_field( 'milieus_updates' ); ?>
									<button class="th-link-btn"><?php esc_html_e( 'Restore', 'milieus' ); ?></button>
								</form>
								&nbsp;·&nbsp;
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('Delete this backup permanently?')">
									<input type="hidden" name="action" value="milieus_updates_delete_backup">
									<input type="hidden" name="backup" value="<?php echo esc_attr( $b['name'] ); ?>">
									<?php wp_nonce_field( 'milieus_updates' ); ?>
									<button class="th-link-btn danger"><?php esc_html_e( 'Delete', 'milieus' ); ?></button>
								</form>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php
			}
		); ?>

	</div></div>
	<?php
}
