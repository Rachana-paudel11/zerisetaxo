<?php
/**
 * Admin Dashboard Status Overview View for ML Category Predictor.
 *
 * @var string $service_url
 * @var bool $is_url_from_const
 * @var array|\WP_Error $health
 * @var bool $is_healthy
 * @var array|null $site_intel
 * @var int $total_posts
 * @var int $total_categories
 * @var string $site_id
 * @var bool $is_recommended
 * @var string $service_status
 * @var string $service_label
 * @var string $sync_status
 * @var int $last_synced_at
 * @var int $synced_post_count
 * @var string $site_model_status
 * @var string $site_model_ver
 * @var int $last_rebuilt_at
 * @var int $pending_count
 */
defined('ABSPATH') || exit;

$zerisetaxo_format_ts = function ( int $ts ): string {
    if ( empty( $ts ) ) return __( 'Never', 'zerisetaxo' );
    if ( function_exists( 'wp_date' ) ) return wp_date( 'M j, Y · H:i', $ts );
    if ( function_exists( 'date_i18n' ) ) return date_i18n( 'M j, Y · H:i', $ts );
    return gmdate( 'M j, Y · H:i', $ts );
};

$zerisetaxo_settings_url = admin_url( 'admin.php?page=' . \ZeriseTaxo\Admin\SettingsPage::MENU_SLUG . '-settings' );
$zerisetaxo_advanced_url = add_query_arg( [ 'subtab' => 'advanced' ], $zerisetaxo_settings_url );

// Service status classes
$zerisetaxo_is_ok       = ( 'connected' === $service_status || $is_healthy );
$zerisetaxo_is_warn     = ( 'setup_required' === $service_status );
$zerisetaxo_is_err      = in_array( $service_status, [ 'error', 'disconnected' ], true );
$zerisetaxo_model_ready = in_array( $site_model_status ?? '', [ 'ready', 'healthy', 'limited' ], true );

// Accuracy
$zerisetaxo_accuracy_val = ( isset( $site_intel['accuracy'] ) && null !== $site_intel['accuracy'] )
    ? round( $site_intel['accuracy'] * 100, 1 )
    : null;
?>
<?php
$zerisetaxo_card_class  = $zerisetaxo_is_ok ? 'ok' : ( $zerisetaxo_is_warn ? 'warn' : ( $zerisetaxo_is_err ? 'err' : 'idle' ) );
?>
<div class="zerisetaxo-db-wrap" style="max-width: 100%;">

    <!-- =================== OPERATIONAL CARDS GRID =================== -->
    <div class="zerisetaxo-db-cards" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 18px; margin-bottom: 22px;">

        <!-- CARD 1: Prediction Engine -->
        <div class="zerisetaxo-db-card" style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 10px; padding: 20px 22px; box-shadow: 0 1px 4px rgba(0,0,0,0.03); display: flex; flex-direction: column; gap: 14px;">
            <div class="zerisetaxo-db-card-head" style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                <div class="zerisetaxo-db-card-title" style="display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 700; color: #0F172A;">
                    <i class="fa-solid fa-cpu" style="color: #4F46E5; font-size: 15px;"></i>
                    <span><?php echo ('fastapi' === ($engine_mode ?? 'php')) ? esc_html__('Inference Service', 'zerisetaxo') : esc_html__('Inference Service (PHP-Native)', 'zerisetaxo'); ?></span>
                </div>
                <span class="zerisetaxo-db-pill <?php echo esc_attr($zerisetaxo_card_class); ?>" style="display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 12px; font-size: 11.5px; font-weight: 700;">
                    <?php echo esc_html($service_label); ?>
                </span>
            </div>

            <?php if ('fastapi' === ($engine_mode ?? 'php')) : ?>
                <div>
                    <div style="font-size: 11px; color: #64748B; margin-bottom: 4px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;">
                        <?php esc_html_e('Endpoint URL', 'zerisetaxo'); ?>
                    </div>
                    <div class="zerisetaxo-db-endpoint" style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 6px; padding: 8px 12px; font-family: monospace; font-size: 12px; color: #334155; word-break: break-all;">
                        <?php echo $service_url ? esc_html($service_url) : '<em style="color:#94A3B8;">' . esc_html__('Not configured', 'zerisetaxo') . '</em>'; ?>
                    </div>
                </div>

                <div class="zerisetaxo-db-meta" style="display: flex; flex-direction: column; gap: 8px;">
                    <div class="zerisetaxo-db-meta-row" style="display: flex; justify-content: space-between; font-size: 12.5px;">
                        <span class="zerisetaxo-db-meta-label" style="color: #64748B;"><?php esc_html_e('Last Health Check', 'zerisetaxo'); ?></span>
                        <span class="zerisetaxo-db-meta-val" style="color: #0F172A; font-weight: 600;"><?php echo esc_html($zerisetaxo_format_ts($last_health_check ?? 0)); ?></span>
                    </div>
                </div>

                <div class="zerisetaxo-db-card-actions" style="display: flex; gap: 8px; margin-top: auto; padding-top: 10px; border-top: 1px solid #F1F5F9; align-items: center;">
                    <button type="button" class="button button-secondary zerisetaxo-test-conn-btn" id="zerisetaxo-dashboard-test-conn" style="font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                        <i class="fa-solid fa-rotate-right"></i>
                        <span><?php esc_html_e('Test Connection', 'zerisetaxo'); ?></span>
                    </button>
                    <a href="<?php echo esc_url($zerisetaxo_advanced_url); ?>" style="font-size: 12px; font-weight: 600; color: #4F46E5; text-decoration: none; margin-left: auto;">
                        <?php esc_html_e('Configure →', 'zerisetaxo'); ?>
                    </a>
                </div>
            <?php else : ?>
                <div>
                    <div style="font-size: 11px; color: #64748B; margin-bottom: 4px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;">
                        <?php esc_html_e('Engine Runtime', 'zerisetaxo'); ?>
                    </div>
                    <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-left: 3px solid #4F46E5; border-radius: 6px; padding: 8px 12px; font-size: 12px; color: #0F172A; font-weight: 600;">
                        <i class="fa-solid fa-bolt" style="color: #4F46E5; margin-right: 6px;"></i><?php esc_html_e('PHP-Native · In-Process Inference', 'zerisetaxo'); ?>
                    </div>
                </div>

                <div class="zerisetaxo-db-meta" style="display: flex; flex-direction: column; gap: 8px;">
                    <div class="zerisetaxo-db-meta-row" style="display: flex; justify-content: space-between; font-size: 12.5px;">
                        <span class="zerisetaxo-db-meta-label" style="color: #64748B;"><?php esc_html_e('Weights Format', 'zerisetaxo'); ?></span>
                        <span class="zerisetaxo-db-meta-val" style="color: #0F172A; font-weight: 600;"><?php echo esc_html($site_intel['active_version'] ?? '2.0.0 (float64)'); ?></span>
                    </div>
                    <div class="zerisetaxo-db-meta-row" style="display: flex; justify-content: space-between; font-size: 12.5px;">
                        <span class="zerisetaxo-db-meta-label" style="color: #64748B;"><?php esc_html_e('Inference Latency', 'zerisetaxo'); ?></span>
                        <span class="zerisetaxo-db-meta-val" style="color: #4F46E5; font-weight: 700;"><?php esc_html_e('~0.2 ms / post', 'zerisetaxo'); ?></span>
                    </div>
                    <div class="zerisetaxo-db-meta-row" style="display: flex; justify-content: space-between; font-size: 12.5px;">
                        <span class="zerisetaxo-db-meta-label" style="color: #64748B;"><?php esc_html_e('External Servers', 'zerisetaxo'); ?></span>
                        <span class="zerisetaxo-db-meta-val">
                            <span style="background: #EEF2FF; color: #4338CA; border: 1px solid #C7D2FE; font-size: 10.5px; font-weight: 700; padding: 2px 7px; border-radius: 10px;"><?php esc_html_e('Zero Required', 'zerisetaxo'); ?></span>
                        </span>
                    </div>
                </div>

                <div class="zerisetaxo-db-card-actions" style="display: flex; gap: 8px; margin-top: auto; padding-top: 10px; border-top: 1px solid #F1F5F9; align-items: center;">
                    <a href="<?php echo esc_url($zerisetaxo_advanced_url); ?>" class="button button-secondary" title="<?php esc_attr_e('Configure prediction engine runtime, endpoints, and performance parameters', 'zerisetaxo'); ?>" style="font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                        <i class="fa-solid fa-sliders"></i>
                        <span><?php esc_html_e('Engine Settings', 'zerisetaxo'); ?></span>
                    </a>
                    <a href="<?php echo esc_url($zerisetaxo_settings_url); ?>" title="<?php esc_attr_e('Open plugin general configuration options', 'zerisetaxo'); ?>" style="font-size: 12px; font-weight: 600; color: #4F46E5; text-decoration: none; margin-left: auto;">
                        <?php esc_html_e('View Settings →', 'zerisetaxo'); ?>
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- CARD 2: Site Model -->
        <div class="zerisetaxo-db-card" style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 10px; padding: 20px 22px; box-shadow: 0 1px 4px rgba(0,0,0,0.03); display: flex; flex-direction: column; gap: 14px;">
            <div class="zerisetaxo-db-card-head" style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                <div class="zerisetaxo-db-card-title" style="display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 700; color: #0F172A;">
                    <i class="fa-solid fa-brain" style="color: #4F46E5; font-size: 15px;"></i>
                    <span><?php esc_html_e('Site Model', 'zerisetaxo'); ?></span>
                </div>
                <?php
                $zerisetaxo_site_pill_class = $zerisetaxo_model_ready ? ('limited' === $site_model_status ? 'warn' : 'ok') : 'idle';
                $zerisetaxo_site_pill_label = ('healthy' === $site_model_status || 'ready' === $site_model_status) ? __('Ready', 'zerisetaxo') : (('limited' === $site_model_status) ? __('Active (Limited Data)', 'zerisetaxo') : __('Not built', 'zerisetaxo'));
                ?>
                <span class="zerisetaxo-db-pill <?php echo esc_attr($zerisetaxo_site_pill_class); ?>" style="display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 12px; font-size: 11.5px; font-weight: 700;">
                    <?php echo esc_html($zerisetaxo_site_pill_label); ?>
                </span>
            </div>

            <div class="zerisetaxo-db-meta" style="display: flex; flex-direction: column; gap: 8px;">
                <div class="zerisetaxo-db-meta-row" style="display: flex; justify-content: space-between; font-size: 12.5px;">
                    <span class="zerisetaxo-db-meta-label" style="color: #64748B;"><?php esc_html_e('Active Version', 'zerisetaxo'); ?></span>
                    <span class="zerisetaxo-db-meta-val" style="color: #0F172A; font-weight: 600;"><?php echo esc_html($site_model_ver ?: '—'); ?></span>
                </div>
                <div class="zerisetaxo-db-meta-row" style="display: flex; justify-content: space-between; font-size: 12.5px;">
                    <span class="zerisetaxo-db-meta-label" style="color: #64748B;"><?php esc_html_e('Last Synced', 'zerisetaxo'); ?></span>
                    <span class="zerisetaxo-db-meta-val" style="color: #0F172A; font-weight: 600;"><?php echo esc_html($zerisetaxo_format_ts($last_synced_at ?? 0)); ?></span>
                </div>
                <div class="zerisetaxo-db-meta-row" style="display: flex; justify-content: space-between; font-size: 12.5px;">
                    <span class="zerisetaxo-db-meta-label" style="color: #64748B;"><?php esc_html_e('Last Rebuilt', 'zerisetaxo'); ?></span>
                    <span class="zerisetaxo-db-meta-val" style="color: #0F172A; font-weight: 600;"><?php echo esc_html($zerisetaxo_format_ts($last_rebuilt_at ?? 0)); ?></span>
                </div>
                <?php if (null !== $zerisetaxo_accuracy_val) : ?>
                <div class="zerisetaxo-db-meta-row" style="flex-direction: column; align-items: stretch; margin-top: 4px;">
                    <div style="display:flex; justify-content:space-between; margin-bottom:4px; font-size: 12px;">
                        <span class="zerisetaxo-db-meta-label" style="color: #64748B;"><?php esc_html_e('Model Accuracy', 'zerisetaxo'); ?></span>
                        <span class="zerisetaxo-db-meta-val" style="color: #4F46E5; font-weight: 700;"><?php echo esc_html($zerisetaxo_accuracy_val); ?>%</span>
                    </div>
                    <div class="zerisetaxo-db-progress-bar-bg" style="background: #E2E8F0; border-radius: 4px; height: 6px; overflow: hidden;">
                        <div class="zerisetaxo-db-progress-bar-fill" style="width: <?php echo esc_attr($zerisetaxo_accuracy_val); ?>%; height: 100%; background: #4F46E5; border-radius: 4px;"></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="zerisetaxo-db-card-actions" style="display: flex; gap: 8px; margin-top: auto; padding-top: 10px; border-top: 1px solid #F1F5F9; align-items: center;">
                <button type="button" class="button button-secondary zerisetaxo-sync-site-btn" id="zerisetaxo-dashboard-sync-btn" title="<?php esc_attr_e('Synchronize published post content and taxonomy structure with the ML service', 'zerisetaxo'); ?>" style="font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                    <i class="fa-solid fa-cloud-arrow-up"></i>
                    <span><?php esc_html_e('Sync Index', 'zerisetaxo'); ?></span>
                </button>
                <button type="button" class="button button-secondary zerisetaxo-rebuild-model-btn" id="zerisetaxo-dashboard-rebuild-btn" title="<?php esc_attr_e('Train and rebuild site-specific supervised ML classification model', 'zerisetaxo'); ?>" style="font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                    <i class="fa-solid fa-brain"></i>
                    <span><?php esc_html_e('Rebuild Model', 'zerisetaxo'); ?></span>
                </button>
            </div>
        </div>

        <!-- CARD 3: Site Info -->
        <?php if (!empty($site_id)) : ?>
        <div class="zerisetaxo-db-card" style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 10px; padding: 20px 22px; box-shadow: 0 1px 4px rgba(0,0,0,0.03); display: flex; flex-direction: column; gap: 14px;">
            <div class="zerisetaxo-db-card-head" style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                <div class="zerisetaxo-db-card-title" style="display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 700; color: #0F172A;">
                    <i class="fa-solid fa-network-wired" style="color: #4F46E5; font-size: 15px;"></i>
                    <span><?php esc_html_e('Site Identity', 'zerisetaxo'); ?></span>
                </div>
                <?php if ($is_recommended) : ?>
                <span class="zerisetaxo-db-pill info" style="background: #EEF2FF; color: #4338CA; border: 1px solid #C7D2FE; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 10px;"><?php esc_html_e('Hosted', 'zerisetaxo'); ?></span>
                <?php endif; ?>
            </div>

            <div class="zerisetaxo-db-meta" style="display: flex; flex-direction: column; gap: 8px;">
                <div class="zerisetaxo-db-meta-row" style="display: flex; justify-content: space-between; font-size: 12.5px;">
                    <span class="zerisetaxo-db-meta-label" style="color: #64748B;"><?php esc_html_e('Site ID', 'zerisetaxo'); ?></span>
                    <span class="zerisetaxo-db-meta-val" style="font-family: monospace; font-size: 11.5px; color: #0F172A; font-weight: 600;"><?php echo esc_html(substr($site_id, 0, 16) . '…'); ?></span>
                </div>
                <div class="zerisetaxo-db-meta-row" style="display: flex; justify-content: space-between; font-size: 12.5px;">
                    <span class="zerisetaxo-db-meta-label" style="color: #64748B;"><?php esc_html_e('Posts Synced', 'zerisetaxo'); ?></span>
                    <span class="zerisetaxo-db-meta-val" style="color: #0F172A; font-weight: 600;"><?php echo esc_html(number_format_i18n((int) ($synced_post_count ?? 0))); ?></span>
                </div>
            </div>

            <div class="zerisetaxo-db-card-actions" style="display: flex; gap: 8px; margin-top: auto; padding-top: 10px; border-top: 1px solid #F1F5F9; align-items: center;">
                <a href="<?php echo esc_url($zerisetaxo_settings_url); ?>" class="button button-secondary" title="<?php esc_attr_e('Manage general settings and taxonomy recommendation behavior', 'zerisetaxo'); ?>" style="font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                    <i class="fa-solid fa-sliders"></i>
                    <span><?php esc_html_e('Settings', 'zerisetaxo'); ?></span>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- CARD 4: Taxonomy Intelligence & Learning State -->
        <?php
        $zerisetaxo_state_detector = new \ZeriseTaxo\ML\Site\SiteStateDetector();
        $zerisetaxo_state_info     = $zerisetaxo_state_detector->detect();
        $zerisetaxo_state_key      = $zerisetaxo_state_info['state'];
        $zerisetaxo_state_pill_map = [
            'EMPTY'      => ['warn', __('Empty (Cold-Start)', 'zerisetaxo')],
            'PAGES_ONLY' => ['info', __('Pages Only (Domain Context)', 'zerisetaxo')],
            'LOW_DATA'   => ['warn', __('Low Data', 'zerisetaxo')],
            'TRAINABLE'  => ['info', __('Trainable', 'zerisetaxo')],
            'MATURE'     => ['ok',   __('Mature', 'zerisetaxo')],
        ];
        $zerisetaxo_state_pill = $zerisetaxo_state_pill_map[$zerisetaxo_state_key] ?? ['idle', $zerisetaxo_state_key];
        ?>
        <div class="zerisetaxo-db-card" style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 10px; padding: 20px 22px; box-shadow: 0 1px 4px rgba(0,0,0,0.03); display: flex; flex-direction: column; gap: 14px;">
            <div class="zerisetaxo-db-card-head" style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                <div class="zerisetaxo-db-card-title" style="display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 700; color: #0F172A;">
                    <i class="fa-solid fa-graduation-cap" style="color: #4F46E5; font-size: 15px;"></i>
                    <span><?php esc_html_e('Taxonomy Intelligence', 'zerisetaxo'); ?></span>
                </div>
                <span class="zerisetaxo-db-pill <?php echo esc_attr($zerisetaxo_state_pill[0]); ?>" style="display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 12px; font-size: 11.5px; font-weight: 700;">
                    <?php echo esc_html($zerisetaxo_state_pill[1]); ?>
                </span>
            </div>

            <div class="zerisetaxo-db-meta" style="display: flex; flex-direction: column; gap: 8px;">
                <div class="zerisetaxo-db-meta-row" style="display: flex; justify-content: space-between; font-size: 12.5px;">
                    <span class="zerisetaxo-db-meta-label" style="color: #64748B;"><?php esc_html_e('Published Posts', 'zerisetaxo'); ?></span>
                    <span class="zerisetaxo-db-meta-val" style="color: #0F172A; font-weight: 600;"><?php echo esc_html(number_format_i18n($zerisetaxo_state_info['published_posts'])); ?></span>
                </div>
                <div class="zerisetaxo-db-meta-row" style="display: flex; justify-content: space-between; font-size: 12.5px;">
                    <span class="zerisetaxo-db-meta-label" style="color: #64748B;"><?php esc_html_e('Labelled Posts', 'zerisetaxo'); ?></span>
                    <span class="zerisetaxo-db-meta-val" style="color: #0F172A; font-weight: 600;"><?php echo esc_html(number_format_i18n($zerisetaxo_state_info['labelled_posts'])); ?></span>
                </div>
                <div class="zerisetaxo-db-meta-row" style="display: flex; justify-content: space-between; font-size: 12.5px;">
                    <span class="zerisetaxo-db-meta-label" style="color: #64748B;"><?php esc_html_e('Pages (Domain Context)', 'zerisetaxo'); ?></span>
                    <span class="zerisetaxo-db-meta-val" style="color: #0F172A; font-weight: 600;"><?php echo esc_html(number_format_i18n($zerisetaxo_state_info['pages'])); ?></span>
                </div>
                <div class="zerisetaxo-db-meta-row" style="display: flex; justify-content: space-between; font-size: 12.5px;">
                    <span class="zerisetaxo-db-meta-label" style="color: #64748B;"><?php esc_html_e('Categories', 'zerisetaxo'); ?></span>
                    <span class="zerisetaxo-db-meta-val" style="color: #0F172A; font-weight: 600;"><?php echo esc_html(number_format_i18n($zerisetaxo_state_info['categories'])); ?></span>
                </div>
            </div>

            <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 10px 12px; font-size: 12px; color: #334155; line-height: 1.4;">
                <div style="font-weight: 700; color: #0F172A; margin-bottom: 2px; display: flex; align-items: center; gap: 4px;">
                    <i class="fa-solid fa-lightbulb" style="color: #EAB308;"></i>
                    <span><?php esc_html_e('Recommendation', 'zerisetaxo'); ?></span>
                </div>
                <?php echo esc_html($zerisetaxo_state_info['recommendation']); ?>
            </div>
        </div>

    </div>

    <!-- COLD-START TAXONOMY BOOTSTRAP PANEL -->
    <div class="zerisetaxo-db-card" style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 10px; padding: 22px 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.03); margin-bottom: 22px;">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
            <div>
                <h3 style="margin: 0 0 4px 0; font-size: 15px; font-weight: 700; color: #0F172A; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-wand-magic-sparkles" style="color: #4F46E5;"></i>
                    <span><?php esc_html_e('Help ZeriseTaxo Understand Your Website', 'zerisetaxo'); ?></span>
                </h3>
                <p style="margin: 0; font-size: 12.5px; color: #64748B;">
                    <?php esc_html_e('Provide a site description and primary topics to generate tailored category taxonomy recommendations.', 'zerisetaxo'); ?>
                </p>
            </div>
        </div>

        <form id="zerisetaxo-bootstrap-form" style="display: flex; flex-direction: column; gap: 14px;">
            <div>
                <label style="display: block; font-size: 12.5px; font-weight: 700; color: #0F172A; margin-bottom: 4px;">
                    <?php esc_html_e('Website Description', 'zerisetaxo'); ?>
                </label>
                <textarea id="zerisetaxo_bootstrap_description" rows="2" style="width: 100%; border: 1px solid #CBD5E1; border-radius: 6px; padding: 8px 12px; font-size: 12.5px; color: #0F172A;" placeholder="<?php esc_attr_e('Technology education website covering programming, AI and web development.', 'zerisetaxo'); ?>"></textarea>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px;">
                <div>
                    <label style="display: block; font-size: 12.5px; font-weight: 700; color: #0F172A; margin-bottom: 4px;">
                        <?php esc_html_e('Primary Topics (comma-separated)', 'zerisetaxo'); ?>
                    </label>
                    <input type="text" id="zerisetaxo_bootstrap_topics" style="width: 100%; border: 1px solid #CBD5E1; border-radius: 6px; padding: 7px 12px; font-size: 12.5px; color: #0F172A;" placeholder="<?php esc_attr_e('Technology, Programming, Artificial Intelligence, Web Development', 'zerisetaxo'); ?>" />
                </div>
                <div>
                    <label style="display: block; font-size: 12.5px; font-weight: 700; color: #0F172A; margin-bottom: 4px;">
                        <?php esc_html_e('Industry / Niche (optional)', 'zerisetaxo'); ?>
                    </label>
                    <input type="text" id="zerisetaxo_bootstrap_niche" style="width: 100%; border: 1px solid #CBD5E1; border-radius: 6px; padding: 7px 12px; font-size: 12.5px; color: #0F172A;" placeholder="<?php esc_attr_e('Software & Tech Education', 'zerisetaxo'); ?>" />
                </div>
            </div>

            <div>
                <button type="submit" id="zerisetaxo-generate-bootstrap-btn" class="button button-primary" style="font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-wand-magic-sparkles"></i>
                    <span><?php esc_html_e('Generate Taxonomy Suggestions', 'zerisetaxo'); ?></span>
                </button>
            </div>
        </form>

        <div id="zerisetaxo-bootstrap-status-notice" class="hidden" style="margin-top: 14px; padding: 10px 14px; border-radius: 6px; font-size: 12.5px;"></div>

        <!-- GENERATED BOOTSTRAP SUGGESTIONS RESULTS PANEL -->
        <div id="zerisetaxo-bootstrap-results-panel" class="hidden" style="margin-top: 18px; border-top: 1px solid #E2E8F0; padding-top: 16px;">
            <h4 style="margin: 0 0 10px 0; font-size: 13.5px; font-weight: 700; color: #0F172A; display: flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-layer-group" style="color: #4F46E5;"></i>
                <span><?php esc_html_e('Recommended Category Taxonomy Structure', 'zerisetaxo'); ?></span>
            </h4>
            <p style="font-size: 12px; color: #64748B; margin: 0 0 12px 0;">
                <?php esc_html_e('All suggestions require explicit approval before categories are created in WordPress.', 'zerisetaxo'); ?>
            </p>

            <div id="zerisetaxo-bootstrap-suggestions-list" style="display: flex; flex-direction: column; gap: 10px;"></div>
        </div>
    </div>

    <!-- =================== HOW IT WORKS FOOTER =================== -->
    <div class="zerisetaxo-db-howto" style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 10px; padding: 20px 24px;">
        <h4 class="zerisetaxo-db-howto-title" style="font-size: 13.5px; font-weight: 700; color: #0F172A; margin: 0 0 14px 0; display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-circle-info" style="color: #4F46E5;"></i>
            <span><?php esc_html_e('How the prediction cascade works', 'zerisetaxo'); ?></span>
        </h4>
        <div class="zerisetaxo-db-tier-list" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px;">
            <div class="zerisetaxo-db-tier" style="display: flex; gap: 12px; align-items: flex-start;">
                <div class="zerisetaxo-db-tier-num" style="width: 26px; height: 26px; border-radius: 6px; background: #4F46E5; color: #FFFFFF; font-size: 12px; font-weight: 800; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 2px;">1</div>
                <div class="zerisetaxo-db-tier-body">
                    <p class="zerisetaxo-db-tier-head" style="font-size: 13px; font-weight: 700; color: #0F172A; margin: 0 0 3px;"><?php esc_html_e('Site Intelligence', 'zerisetaxo'); ?></p>
                    <p class="zerisetaxo-db-tier-desc" style="font-size: 12px; color: #64748B; margin: 0; line-height: 1.5;"><?php esc_html_e('A personalized ML model trained on your own posts and categories makes the first call.', 'zerisetaxo'); ?></p>
                </div>
            </div>
            <div class="zerisetaxo-db-tier" style="display: flex; gap: 12px; align-items: flex-start;">
                <div class="zerisetaxo-db-tier-num" style="width: 26px; height: 26px; border-radius: 6px; background: #4F46E5; color: #FFFFFF; font-size: 12px; font-weight: 800; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 2px;">2</div>
                <div class="zerisetaxo-db-tier-body">
                    <p class="zerisetaxo-db-tier-head" style="font-size: 13px; font-weight: 700; color: #0F172A; margin: 0 0 3px;"><?php esc_html_e('Zero-Shot Semantic', 'zerisetaxo'); ?></p>
                    <p class="zerisetaxo-db-tier-desc" style="font-size: 12px; color: #64748B; margin: 0; line-height: 1.5;"><?php esc_html_e('When confidence is low, a semantic embedding model finds the nearest existing category.', 'zerisetaxo'); ?></p>
                </div>
            </div>
            <div class="zerisetaxo-db-tier" style="display: flex; gap: 12px; align-items: flex-start;">
                <div class="zerisetaxo-db-tier-num" style="width: 26px; height: 26px; border-radius: 6px; background: #4F46E5; color: #FFFFFF; font-size: 12px; font-weight: 800; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 2px;">3</div>
                <div class="zerisetaxo-db-tier-body">
                    <p class="zerisetaxo-db-tier-head" style="font-size: 13px; font-weight: 700; color: #0F172A; margin: 0 0 3px;"><?php esc_html_e('AI Cloud Discovery', 'zerisetaxo'); ?></p>
                    <p class="zerisetaxo-db-tier-desc" style="font-size: 12px; color: #64748B; margin: 0; line-height: 1.5;"><?php esc_html_e('If still ambiguous, an LLM can propose entirely new categories based on content context.', 'zerisetaxo'); ?></p>
                </div>
            </div>
        </div>
    </div>

</div>
