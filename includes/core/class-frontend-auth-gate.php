<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Lightweight config-driven auth gate for internal frontend tools.
 *
 * Expected wp-config.php constants:
 * - KIWI_FRONTEND_AUTH_USERNAME
 * - KIWI_FRONTEND_AUTH_PASSWORD_HASH
 */
class Kiwi_Frontend_Auth_Gate
{
    private const COOKIE_NAME = 'kiwi_frontend_auth';
    private const COOKIE_TTL_SECONDS = 28800; // 8 hours
    private const ACTION_FIELD = 'kiwi_frontend_auth_action';
    private const ACTION_LOGIN = 'login';
    private const ACTION_LOGOUT = 'logout';
    private const USERNAME_FIELD = 'kiwi_frontend_auth_username';
    private const PASSWORD_FIELD = 'kiwi_frontend_auth_password';
    private const REDIRECT_FIELD = 'kiwi_frontend_auth_redirect';
    private const NONCE_FIELD = 'kiwi_frontend_auth_nonce';
    private const NONCE_ACTION = 'kiwi_frontend_auth_submit';
    private const ERROR_QUERY_ARG = 'kiwi_frontend_auth_error';
    private $configured_username;
    private $configured_password_hash;
    private $cookie_ttl_seconds;
    public function __construct(array $overrides = [])
    {
        $this->configured_username = array_key_exists('username', $overrides)
            ? $this->normalize_username((string) $overrides['username'])
            : $this->normalize_username($this->read_config_username());
        $this->configured_password_hash = array_key_exists('password_hash', $overrides)
            ? trim((string) $overrides['password_hash'])
            : trim($this->read_config_password_hash());
        $this->cookie_ttl_seconds = array_key_exists('cookie_ttl_seconds', $overrides)
            ? max(300, (int) $overrides['cookie_ttl_seconds'])
            : self::COOKIE_TTL_SECONDS;
    }
    public function handle_auth_request(): void
    {
        if (!$this->is_enforced()) {
            return;
        }
        $action = $this->read_requested_action();
        if ($action === self::ACTION_LOGIN) {
            $this->handle_login_request();
            return;
        }
        if ($action === self::ACTION_LOGOUT) {
            $this->logout();
            $this->redirect($this->resolve_redirect_target_from_request());
        }
    }
    public function can_access_tools(): bool
    {
        $this->send_tool_nocache_headers();

        return !$this->is_enforced() || $this->is_authenticated();
    }
    public function is_enforced(): bool
    {
        return $this->configured_username !== '' && $this->configured_password_hash !== '';
    }
    public function is_authenticated(): bool
    {
        if (!$this->is_enforced()) {
            return true;
        }
        $cookie_value = isset($_COOKIE[self::COOKIE_NAME])
            ? trim((string) $_COOKIE[self::COOKIE_NAME])
            : '';
        if ($cookie_value === '') {
            return false;
        }
        $decoded = base64_decode($cookie_value, true);
        if (!is_string($decoded) || $decoded === '') {
            return false;
        }
        $parts = explode('|', $decoded);
        if (count($parts) !== 3) {
            return false;
        }
        $username = $this->normalize_username((string) $parts[0]);
        $expires_at = (int) $parts[1];
        $signature = trim((string) $parts[2]);
        if ($username === '' || $signature === '') {
            return false;
        }
        if (!hash_equals($this->configured_username, $username)) {
            return false;
        }
        if ($expires_at < time()) {
            return false;
        }
        return hash_equals(
            $this->build_signature($username, $expires_at),
            $signature
        );
    }
    public function login(string $username, string $password): bool
    {
        if (!$this->is_enforced()) {
            return false;
        }
        $username = $this->normalize_username($username);
        if ($username === '') {
            return false;
        }
        if (!hash_equals($this->configured_username, $username)) {
            return false;
        }
        if (!password_verify($password, $this->configured_password_hash)) {
            return false;
        }
        $expires_at = time() + $this->cookie_ttl_seconds;
        $cookie_value = base64_encode(
            $username . '|' . $expires_at . '|' . $this->build_signature($username, $expires_at)
        );
        if (!headers_sent()) {
            setcookie(self::COOKIE_NAME, $cookie_value, [
                'expires' => $expires_at,
                'path' => '/',
                'secure' => $this->is_secure_request(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        $_COOKIE[self::COOKIE_NAME] = $cookie_value;
        return true;
    }
    public function logout(): void
    {
        if (!headers_sent()) {
            setcookie(self::COOKIE_NAME, '', [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => $this->is_secure_request(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        unset($_COOKIE[self::COOKIE_NAME]);
    }
    public function render_login_form(array $context = []): string
    {
        $this->send_tool_nocache_headers();

        if (!$this->is_enforced()) {
            return $this->render_not_configured_notice();
        }
        $message = trim((string) ($context['message'] ?? 'Please sign in to access this tool.'));
        $redirect_url = $this->sanitize_redirect_target(
            (string) ($context['redirect_url'] ?? $this->get_current_request_url())
        );
        $error_message = $this->resolve_error_message($this->read_error_code());
        $output = '<section class="kiwi-page-shell" aria-label="Kiwi Tools Login">';
        $output .= '<header class="kiwi-section-header">';
        $output .= '<div class="kiwi-section-header-content">';
        $output .= '<h2 class="kiwi-page-title">Kiwi Tools Login</h2>';
        $output .= '<p class="kiwi-page-subtitle">' . esc_html($message) . '</p>';
        $output .= '</div>';
        $output .= '</header>';
        if ($error_message !== '') {
            $output .= '<div class="kiwi-notice kiwi-notice--error"><p>' . esc_html($error_message) . '</p></div>';
        }
        $output .= '<form method="post" class="kiwi-form kiwi-form-card" autocomplete="off">';
        $output .= '<input type="hidden" name="' . esc_attr(self::ACTION_FIELD) . '" value="' . esc_attr(self::ACTION_LOGIN) . '">';
        $output .= '<input type="hidden" name="' . esc_attr(self::REDIRECT_FIELD) . '" value="' . esc_attr($redirect_url) . '">';
        $output .= $this->render_nonce_field();
        $output .= '<div class="kiwi-form-row kiwi-field">';
        $output .= '<label class="kiwi-field-label" for="kiwi_frontend_auth_username">Username</label>';
        $output .= '<input class="kiwi-input kiwi-width-medium" type="text" name="' . esc_attr(self::USERNAME_FIELD) . '" id="kiwi_frontend_auth_username" required>';
        $output .= '</div>';
        $output .= '<div class="kiwi-form-row kiwi-field">';
        $output .= '<label class="kiwi-field-label" for="kiwi_frontend_auth_password">Password</label>';
        $output .= '<input class="kiwi-input kiwi-width-medium" type="password" name="' . esc_attr(self::PASSWORD_FIELD) . '" id="kiwi_frontend_auth_password" required>';
        $output .= '</div>';
        $output .= '<div class="kiwi-form-actions kiwi-actions">';
        $output .= '<button type="submit" class="kiwi-button kiwi-submit-button">Sign in</button>';
        $output .= '</div>';
        $output .= '</form>';
        $output .= '</section>';
        return $output;
    }
    public function render_login_required_and_exit(string $message = ''): void
    {
        if (function_exists('status_header')) {
            status_header(401);
        }
        if (function_exists('nocache_headers')) {
            nocache_headers();
        }
        echo $this->render_login_form([
            'message' => trim($message) !== ''
                ? $message
                : 'Please sign in to continue.',
            'redirect_url' => $this->get_current_request_url(),
        ]);
        exit;
    }
    public function get_logout_url(string $redirect_url = ''): string
    {
        $redirect_url = $this->sanitize_redirect_target(
            $redirect_url !== '' ? $redirect_url : $this->get_current_request_url()
        );
        $url = $this->add_query_args($redirect_url, [
            self::ACTION_FIELD => self::ACTION_LOGOUT,
            self::REDIRECT_FIELD => $redirect_url,
        ]);
        if (function_exists('wp_create_nonce')) {
            $url = $this->add_query_args($url, [
                self::NONCE_FIELD => wp_create_nonce(self::NONCE_ACTION),
            ]);
        }
        return $url;
    }
    private function handle_login_request(): void
    {
        $redirect_url = $this->resolve_redirect_target_from_request();
        if (!$this->is_post_request() || !$this->is_valid_nonce_from_request()) {
            $this->redirect($this->add_query_args($redirect_url, [
                self::ERROR_QUERY_ARG => 'invalid_request',
            ]));
        }
        $username = isset($_POST[self::USERNAME_FIELD])
            ? (string) wp_unslash($_POST[self::USERNAME_FIELD])
            : '';
        $password = isset($_POST[self::PASSWORD_FIELD])
            ? (string) wp_unslash($_POST[self::PASSWORD_FIELD])
            : '';
        if (!$this->login($username, $password)) {
            $this->redirect($this->add_query_args($redirect_url, [
                self::ERROR_QUERY_ARG => 'invalid_credentials',
            ]));
        }
        $this->redirect($this->remove_query_args($redirect_url, [self::ERROR_QUERY_ARG]));
    }
    private function is_valid_nonce_from_request(): bool
    {
        if (!function_exists('wp_verify_nonce')) {
            return true;
        }
        $nonce = isset($_POST[self::NONCE_FIELD])
            ? (string) wp_unslash($_POST[self::NONCE_FIELD])
            : '';
        if ($nonce === '') {
            return false;
        }
        return wp_verify_nonce($nonce, self::NONCE_ACTION) === 1
            || wp_verify_nonce($nonce, self::NONCE_ACTION) === true;
    }
    private function read_requested_action(): string
    {
        if (isset($_POST[self::ACTION_FIELD])) {
            return strtolower($this->sanitize_scalar((string) wp_unslash($_POST[self::ACTION_FIELD])));
        }
        if (isset($_GET[self::ACTION_FIELD])) {
            return strtolower($this->sanitize_scalar((string) wp_unslash($_GET[self::ACTION_FIELD])));
        }
        return '';
    }
    private function resolve_redirect_target_from_request(): string
    {
        if (isset($_POST[self::REDIRECT_FIELD])) {
            return $this->sanitize_redirect_target((string) wp_unslash($_POST[self::REDIRECT_FIELD]));
        }
        if (isset($_GET[self::REDIRECT_FIELD])) {
            return $this->sanitize_redirect_target((string) wp_unslash($_GET[self::REDIRECT_FIELD]));
        }
        return $this->get_current_request_url();
    }
    private function sanitize_redirect_target(string $redirect_target): string
    {
        $redirect_target = trim($redirect_target);
        if ($redirect_target === '') {
            return $this->get_current_request_url();
        }
        if (function_exists('wp_validate_redirect')) {
            return (string) wp_validate_redirect($redirect_target, $this->get_current_request_url());
        }
        if (strpos($redirect_target, '://') !== false) {
            $current_host = (string) parse_url($this->get_current_request_url(), PHP_URL_HOST);
            $target_host = (string) parse_url($redirect_target, PHP_URL_HOST);
            if ($current_host !== '' && $target_host !== '' && strcasecmp($current_host, $target_host) !== 0) {
                return $this->get_current_request_url();
            }
            return $redirect_target;
        }
        return strpos($redirect_target, '/') === 0
            ? $redirect_target
            : '/' . ltrim($redirect_target, '/');
    }
    private function add_query_args(string $url, array $query_args): string
    {
        $parts = parse_url($url);
        $base = $this->build_base_url($parts, $url);
        $query = [];
        if (is_array($parts) && isset($parts['query']) && is_string($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        foreach ($query_args as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $query[(string) $key] = (string) $value;
        }
        $query_string = http_build_query($query);
        $fragment = (is_array($parts) && isset($parts['fragment'])) ? '#' . $parts['fragment'] : '';
        return $query_string !== ''
            ? $base . '?' . $query_string . $fragment
            : $base . $fragment;
    }
    private function remove_query_args(string $url, array $keys): string
    {
        $parts = parse_url($url);
        $base = $this->build_base_url($parts, $url);
        $query = [];
        if (is_array($parts) && isset($parts['query']) && is_string($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        foreach ($keys as $key) {
            unset($query[(string) $key]);
        }
        $query_string = http_build_query($query);
        $fragment = (is_array($parts) && isset($parts['fragment'])) ? '#' . $parts['fragment'] : '';
        return $query_string !== ''
            ? $base . '?' . $query_string . $fragment
            : $base . $fragment;
    }
    private function build_base_url($parts, string $fallback): string
    {
        if (!is_array($parts) || empty($parts)) {
            return $fallback;
        }
        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $host = isset($parts['host']) ? $parts['host'] : '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = isset($parts['path']) ? $parts['path'] : '';
        if ($scheme === '' && $host === '' && $path === '') {
            return $fallback;
        }
        return $scheme . $host . $port . $path;
    }
    private function render_nonce_field(): string
    {
        if (function_exists('wp_nonce_field')) {
            return (string) wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD, true, false);
        }
        if (function_exists('wp_create_nonce')) {
            return '<input type="hidden" name="' . esc_attr(self::NONCE_FIELD) . '" value="' . esc_attr((string) wp_create_nonce(self::NONCE_ACTION)) . '">';
        }
        return '';
    }
    private function render_not_configured_notice(): string
    {
        return '<section class="kiwi-page-shell" aria-label="Kiwi Tools Login">'
            . '<header class="kiwi-section-header"><div class="kiwi-section-header-content">'
            . '<h2 class="kiwi-page-title">Kiwi Tools Login</h2>'
            . '<p class="kiwi-page-subtitle">Frontend auth is not configured.</p>'
            . '</div></header>'
            . '<div class="kiwi-notice kiwi-notice--warning">'
            . '<p>Please set <code>KIWI_FRONTEND_AUTH_USERNAME</code> and <code>KIWI_FRONTEND_AUTH_PASSWORD_HASH</code> in <code>wp-config.php</code>.</p>'
            . '</div></section>';
    }

    private function send_tool_nocache_headers(): void
    {
        if (headers_sent()) {
            return;
        }

        if (function_exists('nocache_headers')) {
            nocache_headers();
        } else {
            header('Expires: Wed, 11 Jan 1984 05:00:00 GMT', true);
            header('Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private', true);
            header('Pragma: no-cache', true);
        }

        header('CDN-Cache-Control: no-store', false);
        header('X-LiteSpeed-Cache-Control: no-cache', false);
    }

    private function read_error_code(): string
    {
        if (!isset($_GET[self::ERROR_QUERY_ARG])) {
            return '';
        }
        return $this->sanitize_scalar((string) wp_unslash($_GET[self::ERROR_QUERY_ARG]));
    }
    private function resolve_error_message(string $error_code): string
    {
        if ($error_code === 'invalid_credentials') {
            return 'Invalid credentials. Please try again.';
        }
        if ($error_code === 'invalid_request') {
            return 'Invalid login request. Please try again.';
        }
        return '';
    }
    private function redirect(string $target_url): void
    {
        $target_url = $this->sanitize_redirect_target($target_url);
        if (function_exists('wp_safe_redirect')) {
            wp_safe_redirect($target_url);
            exit;
        }
        if (!headers_sent()) {
            header('Location: ' . $target_url, true, 302);
        }
        exit;
    }
    private function sanitize_scalar(string $value): string
    {
        if (function_exists('sanitize_text_field')) {
            return trim((string) sanitize_text_field($value));
        }
        return trim($value);
    }
    private function normalize_username(string $username): string
    {
        $username = strtolower(trim($username));
        if ($username === '') {
            return '';
        }
        return preg_replace('/[^a-z0-9._-]/', '', $username) ?? '';
    }
    private function build_signature(string $username, int $expires_at): string
    {
        return hash_hmac('sha256', $username . '|' . $expires_at, $this->resolve_signing_key());
    }
    private function resolve_signing_key(): string
    {
        if (function_exists('wp_salt')) {
            return (string) wp_salt('auth');
        }
        if (defined('AUTH_KEY') && trim((string) AUTH_KEY) !== '') {
            return (string) AUTH_KEY;
        }
        if (defined('NONCE_SALT') && trim((string) NONCE_SALT) !== '') {
            return (string) NONCE_SALT;
        }
        return __FILE__;
    }
    private function get_current_request_url(): string
    {
        $request_uri = isset($_SERVER['REQUEST_URI'])
            ? trim((string) $_SERVER['REQUEST_URI'])
            : '/';
        if ($request_uri === '') {
            $request_uri = '/';
        }
        if (strpos($request_uri, 'http://') === 0 || strpos($request_uri, 'https://') === 0) {
            return $request_uri;
        }
        if (function_exists('home_url')) {
            return (string) home_url($request_uri);
        }
        return $request_uri;
    }
    private function is_post_request(): bool
    {
        $method = isset($_SERVER['REQUEST_METHOD'])
            ? strtoupper((string) $_SERVER['REQUEST_METHOD'])
            : '';
        return $method === 'POST';
    }
    private function is_secure_request(): bool
    {
        if (function_exists('is_ssl')) {
            return (bool) is_ssl();
        }
        return !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    }
    private function read_config_username(): string
    {
        return defined('KIWI_FRONTEND_AUTH_USERNAME')
            ? (string) KIWI_FRONTEND_AUTH_USERNAME
            : '';
    }
    private function read_config_password_hash(): string
    {
        return defined('KIWI_FRONTEND_AUTH_PASSWORD_HASH')
            ? (string) KIWI_FRONTEND_AUTH_PASSWORD_HASH
            : '';
    }
}
