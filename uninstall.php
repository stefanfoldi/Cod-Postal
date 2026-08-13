<?php
/**
 * Removes the plugin options when it is deleted from WordPress.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }

delete_option('wc_ro_validator_api_key');
delete_option('wc_ro_validator_enable_logging');
delete_option('_wc_ro_safe_last_api_error');
