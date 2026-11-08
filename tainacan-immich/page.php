<div class="wrap tainacan-page-container-content">
	<div class="tainacan-fixed-subheader">
		<h1 class="tainacan-page-title">
			<?php _e('Immich', 'tainacan-immich'); ?>
		</h1>
	</div>
	<div class="tainacan-immich">
		<?php if ( ! current_user_can( 'manage_options' ) ) : ?>
			<p><?php echo esc_html__( 'You do not have permission to change the Immich settings.', 'tainacan-immich' ); ?></p>
		<?php else : ?>
			<?php settings_errors(); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" class="tainacan-immich-settings">
				<?php settings_fields( \Tainacan\Immich::OPTION_GROUP ); ?>
				<p>
					<label for="tainacan_immich_url"><?php echo esc_html__( 'Immich server URL', 'tainacan-immich' ); ?></label>
					<input type="url" id="tainacan_immich_url" name="tainacan_immich_url" class="regular-text" value="<?php echo esc_attr( get_option( \Tainacan\Immich::OPTION_URL, '' ) ); ?>" placeholder="https://immich.example.org">
					<span class="description"><?php echo esc_html__( 'The base URL of the Immich server, without /api.', 'tainacan-immich' ); ?></span>
				</p>
				<p>
					<label for="tainacan_immich_api_key"><?php echo esc_html__( 'Immich API key', 'tainacan-immich' ); ?></label>
					<input type="password" id="tainacan_immich_api_key" name="tainacan_immich_api_key" class="regular-text" value="" autocomplete="new-password">
					<span class="description">
						<?php
						if ( '' !== (string) get_option( \Tainacan\Immich::OPTION_API_KEY, '' ) ) {
							echo esc_html__( 'An API key is saved. Leave the field empty to keep it.', 'tainacan-immich' );
						} else {
							echo esc_html__( 'No API key is saved.', 'tainacan-immich' );
						}
						?>
					</span>
				</p>
				<p>
					<label for="tainacan_immich_update_check">
						<input type="checkbox" id="tainacan_immich_update_check" name="tainacan_immich_update_check" value="1"<?php echo '1' === (string) get_option( \Tainacan\Immich::OPTION_UPDATE_CHECK, '1' ) ? ' checked="checked"' : ''; ?>>
						<?php echo esc_html__( 'Check for plugin updates', 'tainacan-immich' ); ?>
					</label>
					<span class="description"><?php echo esc_html__( 'The plugin asks GitHub for a new release about two times a day. No site data is sent.', 'tainacan-immich' ); ?></span>
				</p>
				<?php submit_button( __( 'Save settings', 'tainacan-immich' ) ); ?>
			</form>
		<?php endif; ?>
    </div>
</div>