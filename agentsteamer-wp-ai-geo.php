<?php
/**
 * Plugin Name:       AgentSteamer AI SEO & GEO
 * Plugin URI:        https://www.agentsteamer.com/
 * Description:       WordPress 原生 AI SEO / GEO 优化插件：基础 SEO、结构化数据、llms.txt、Markdown 与 AI 一键成文。自包含，不依赖任何外部平台。
 * Version:           0.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            上海临境绘谷信息科技有限公司
 * Author URI:        https://www.agentsteamer.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       agentsteamer-ai
 * Domain Path:       /languages
 *
 * @package AgentSteamer_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AGENTSTEAMER_AI_VERSION', '0.1.0' );
define( 'AGENTSTEAMER_AI_FILE', __FILE__ );
define( 'AGENTSTEAMER_AI_DIR', plugin_dir_path( __FILE__ ) );
define( 'AGENTSTEAMER_AI_URL', plugin_dir_url( __FILE__ ) );
define( 'AGENTSTEAMER_AI_BASENAME', plugin_basename( __FILE__ ) );

require_once AGENTSTEAMER_AI_DIR . 'inc/helpers.php';
require_once AGENTSTEAMER_AI_DIR . 'inc/class-plugin.php';

register_activation_hook( __FILE__, array( 'AgentSteamer_AI_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AgentSteamer_AI_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'AgentSteamer_AI_Plugin', 'instance' ) );
