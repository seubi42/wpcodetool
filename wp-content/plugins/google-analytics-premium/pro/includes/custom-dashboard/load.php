<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Ensure base API and Report classes are loaded first
if ( ! class_exists( 'MonsterInsights_API_Error' ) ) {
	require_once MONSTERINSIGHTS_PLUGIN_DIR . 'includes/api/class-monsterinsights-api-error.php';
}
if ( ! class_exists( 'MonsterInsights_API_Client' ) ) {
	require_once MONSTERINSIGHTS_PLUGIN_DIR . 'includes/api/class-monsterinsights-api.php';
}
if ( ! class_exists( 'MonsterInsights_Report' ) ) {
	require_once MONSTERINSIGHTS_PLUGIN_DIR . 'includes/admin/reports/abstract-report.php';
}

require_once MONSTERINSIGHTS_PLUGIN_DIR . 'pro/includes/api/class-monsterinsights-api-custom-dashboard.php';
require_once 'widgets/interface-monsterinsights-widget.php';
require_once 'widgets/class-monsterinsights-abstract-widget.php';
require_once 'class-custom-dashboard-widgets.php';
require_once 'class-custom-dashboard-cpt.php';
require_once 'class-custom-dashboard-ajax.php';
require_once 'class-custom-dashboard-templates.php';

// Instantiate the ajax and CPT classes
new MonsterInsights_Custom_Dashboard_CPT();
new MonsterInsights_Custom_Dashboard_Ajax(); 
new MonsterInsights_Custom_Dashboard_Templates();