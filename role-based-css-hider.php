<?php
/**
 * Plugin Name: Role-based CSS Hider
 * Description: Blende beliebige CSS-Selektoren für bestimmte Benutzerrollen aus (inkl. Gäste & optional im Adminbereich).
 * Version:     1.0.1
 * Author:      Remo Lepori
 * License:     GPLv2 or later
 * Text Domain: rbch
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('RBCH_Plugin')):

final class RBCH_Plugin {
  const OPTION_KEY   = 'rbch_rules_v1';
  const OPTION_ADMIN = 'rbch_apply_in_admin_v1';

  public function __construct() {
    add_action('admin_menu',        [$this, 'add_settings_page']);
    add_action('admin_init',        [$this, 'register_settings']);
    add_action('wp_enqueue_scripts',    [$this, 'maybe_print_css'],  9999);
    add_action('admin_enqueue_scripts', [$this, 'maybe_print_css_admin'], 9999);
  }

  /** Einstellungen registrieren */
  public function register_settings() {
    register_setting('rbch_group', self::OPTION_KEY, [
      'type'              => 'array',
      'sanitize_callback' => [$this, 'sanitize_rules'],
      'default'           => [],
      'show_in_rest'      => false,
    ]);

    register_setting('rbch_group', self::OPTION_ADMIN, [
      'type'              => 'boolean',
      'sanitize_callback' => function($v){ return !empty($v) ? 1 : 0; },
      'default'           => 0,
      'show_in_rest'      => false,
    ]);
  }

  /** Menüpunkt */
  public function add_settings_page() {
    add_options_page(
      __('Role-based CSS Hider', 'rbch'),
      __('Role-CSS Hider', 'rbch'),
      'manage_options',
      'rbch',
      [$this, 'render_settings_page']
    );
  }

  /** Seite rendern */
  public function render_settings_page() {
    if (!current_user_can('manage_options')) return;

    $rules       = get_option(self::OPTION_KEY, []);
    $apply_admin = (bool) get_option(self::OPTION_ADMIN, 0);
    $roles       = $this->get_all_roles_with_guest();
    ?>
    <div class="wrap">
      <h1><?php esc_html_e('Role-based CSS Hider', 'rbch'); ?></h1>
      <p><?php esc_html_e('Hinterlege pro Rolle CSS-Selektoren (einer pro Zeile), die für diese Rolle ausgeblendet werden sollen.', 'rbch'); ?></p>

      <form method="post" action="options.php">
        <?php settings_fields('rbch_group'); ?>

        <table class="form-table" role="presentation">
          <tbody>
          <tr>
            <th scope="row"><?php esc_html_e('Auch im Adminbereich anwenden', 'rbch'); ?></th>
            <td>
              <label>
                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_ADMIN); ?>" value="1" <?php checked($apply_admin, true); ?> />
                <?php esc_html_e('Regeln auch im WordPress-Admin aktivieren (Vorsicht).', 'rbch'); ?>
              </label>
              <p class="description"><?php esc_html_e('Falls du dich aussperrst: Plugin per FTP im Ordner /wp-content/plugins/ deaktivieren.', 'rbch'); ?></p>
            </td>
          </tr>
          </tbody>
        </table>

        <hr />
        <h2><?php esc_html_e('Regeln pro Rolle', 'rbch'); ?></h2>
        <p class="description"><?php esc_html_e('Ein Selektor pro Zeile (z. B. ".nur-profi", "#promo", "header .login").', 'rbch'); ?></p>

        <?php foreach ($roles as $role_key => $role_label):
          $list = (isset($rules[$role_key]) && is_array($rules[$role_key])) ? $rules[$role_key] : [];
          $textarea = implode("\n", $list);
        ?>
          <fieldset style="margin:1.25rem 0; padding:1rem; background:#fff; border:1px solid #ccd0d4; border-radius:6px;">
            <legend style="font-weight:600;"><?php echo esc_html($role_label); ?></legend>
            <textarea
              name="<?php echo esc_attr(self::OPTION_KEY . '[' . $role_key . ']'); ?>"
              rows="6"
              style="width:100%; font-family: monospace;"
              placeholder=".klasse-ausblenden
#irgendwas
.main .teaser"><?php echo esc_textarea($textarea); ?></textarea>
            <p class="description"><?php esc_html_e('Diese Selektoren werden für Nutzer dieser Rolle ausgeblendet.', 'rbch'); ?></p>
          </fieldset>
        <?php endforeach; ?>

        <?php submit_button(); ?>
      </form>
    </div>
    <?php
  }

  /** CSS im Frontend */
  public function maybe_print_css() {
    $css = $this->build_css_for_current_user();
    if ($css) {
      wp_register_style('rbch-inline', false, [], '1.0.1');
      wp_enqueue_style('rbch-inline');
      wp_add_inline_style('rbch-inline', $css);
    }
  }

  /** CSS im Admin (wenn aktiviert) */
  public function maybe_print_css_admin() {
    $apply_admin = (bool) get_option(self::OPTION_ADMIN, 0);
    if (!$apply_admin) return;

    $css = $this->build_css_for_current_user();
    if ($css) {
      wp_register_style('rbch-admin-inline', false, [], '1.0.1');
      wp_enqueue_style('rbch-admin-inline');
      wp_add_inline_style('rbch-admin-inline', $css);
    }
  }

  /** CSS bauen */
  private function build_css_for_current_user() {
    $rules = get_option(self::OPTION_KEY, []);
    if (empty($rules) || !is_array($rules)) return '';

    $user_roles = $this->get_current_user_roles_including_guest();
    if (empty($user_roles)) return '';

    $selectors = [];
    foreach ($user_roles as $role) {
      if (!empty($rules[$role]) && is_array($rules[$role])) {
        $selectors = array_merge($selectors, $rules[$role]);
      }
    }

    // Sanitizen & Duplikate killen
    $selectors = array_unique(array_filter(array_map([$this, 'sanitize_selector'], $selectors)));
    if (empty($selectors)) return '';

    $css  = implode(",\n", $selectors);
    $css .= " {\n  display: none !important;\n  pointer-events: none !important;\n}\n";
    $css .= "/* RBCH active for roles: " . implode(',', $user_roles) . " */\n";
    return $css;
  }

  /** Regeln sanitizen: Textareas -> Arrays */
  public function sanitize_rules($input) {
    $clean = [];
    if (!is_array($input)) return $clean;

    foreach ($input as $role => $value) {
      if (is_string($value)) {
        $lines = preg_split('/\r\n|\r|\n/', $value);
      } elseif (is_array($value)) {
        $lines = $value;
      } else {
        $lines = [];
      }

      $lines = array_map('trim', $lines);
      $lines = array_filter($lines, static function($s){ return $s !== ''; });

      $lines = array_map([$this, 'sanitize_selector'], $lines);
      $lines = array_filter($lines, static function($s){ return $s !== ''; });

      $clean[$role] = array_values(array_unique($lines));
    }
    return $clean;
  }

  /** Einzelnen Selektor sanitizen (defensiv, keine Fatals) */
  private function sanitize_selector($selector) {
    $s = trim((string) $selector);
    if ($s === '') return '';

    $s = wp_strip_all_tags($s);

    // Konservative Whitelist, aber keine Fatal-Errors bei exotischen Zeichen
    // Erlaubt: Buchstaben/Ziffern, Leerzeichen, . # - _ : , > + ~ * = ^ $ [ ] ( ) " ' \ 
    if (!preg_match('/^[A-Za-z0-9\s\.\#\-\_\:\,>\+\~\*\=\^\$\[\]\(\)\"\'\\\\]+$/', $s)) {
      // Wenn etwas total Exotisches drin ist, verwerfen wir die Zeile
      return '';
    }
    return $s;
  }

  /** Alle Rollen + "Gast" */
  private function get_all_roles_with_guest() {
    $roles = [];

    if (function_exists('wp_roles')) {
      $wp_roles = wp_roles();
      if ($wp_roles && !empty($wp_roles->roles)) {
        foreach ($wp_roles->roles as $key => $def) {
          $label = isset($def['name']) ? $def['name'] : $key;
          if (function_exists('translate_user_role')) {
            $label = translate_user_role($label);
          }
          $roles[$key] = $label;
        }
      }
    }

    $roles['__guest'] = __('Guest (Nicht eingeloggte Benutzer)', 'rbch');
    asort($roles, SORT_NATURAL | SORT_FLAG_CASE);
    return $roles;
  }

  /** Aktuelle Benutzerrollen (oder Gast) */
  private function get_current_user_roles_including_guest() {
    if (is_user_logged_in()) {
      $u = wp_get_current_user();
      if ($u && is_array($u->roles) && !empty($u->roles)) {
        return $u->roles;
      }
      // Fallback, wenn keine Rolle gesetzt ist
      return ['subscriber'];
    }
    return ['__guest'];
  }

  /** Uninstall: Optionen löschen */
  public static function on_uninstall() {
    delete_option(self::OPTION_KEY);
    delete_option(self::OPTION_ADMIN);
  }
}

endif; // class exists

// ----- Plugin initialisieren -----
add_action('plugins_loaded', function(){
  // Klasse nur einmal instanzieren
  if (class_exists('RBCH_Plugin')) {
    $GLOBALS['rbch_plugin'] = new RBCH_Plugin();
  }
});

// ----- Uninstall-Hook außerhalb der Klasse registrieren -----
if (function_exists('register_uninstall_hook')) {
  register_uninstall_hook(__FILE__, ['RBCH_Plugin', 'on_uninstall']);
}
