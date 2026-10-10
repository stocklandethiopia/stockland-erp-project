<?php
/** Stockland ERP uninstall policy: operational data is preserved by default. */
if (!defined('WP_UNINSTALL_PLUGIN')) exit;
// Intentionally do not delete wp_stock_* tables or options. This prevents accidental loss of ERP history.
