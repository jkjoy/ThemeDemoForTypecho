<?php

namespace TypechoPlugin\ThemeDemo;

use Typecho\Plugin\PluginInterface;
use Typecho\Request as HttpRequest;
use Typecho\Widget\Helper\Form;
use Widget\Options;
use Widget\User;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Typecho theme preview plugin.
 *
 * @package ThemeDemo
 * @author jkjoy
 * @version 1.0.6
 * @link https://github.com/jkjoy/ThemeDemoForTypecho
 */
class Plugin implements PluginInterface
{
    private const PREVIEW_COOKIE = 'themedemo_preview_theme';
    private const PREVIEW_CLEAR_KEYWORD = 'clear';
    private const PREVIEW_COOKIE_TTL = 2592000;

    public static function activate()
    {
        \Typecho\Plugin::factory('Widget_Archive')->beforeRender = [__CLASS__, 'processTheme'];
        self::log('activate', 'Plugin activated by ' . self::getCurrentUser());

        return _t('Plugin activated. Use ?theme=theme_dir to preview, ?theme=clear to exit preview.');
    }

    public static function deactivate()
    {
        self::log('deactivate', 'Plugin deactivated by ' . self::getCurrentUser());
        return _t('Plugin deactivated');
    }

    public static function config(Form $form)
    {
        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Checkbox(
            'allowPreview',
            ['visitors' => _t('Allow visitors to preview theme')],
            [],
            _t('Preview permission'),
            _t('By default, only logged-in users can preview themes')
        ));

        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Checkbox(
            'debugMode',
            ['enable' => _t('Enable debug mode')],
            [],
            _t('Debug mode'),
            _t('When enabled, detailed debug info is written to log file')
        ));
    }

    public static function personalConfig(Form $form)
    {
    }

    public static function processTheme($archive)
    {
        try {
            $request = new HttpRequest();
            $requestTheme = trim((string) $request->get('theme', ''));
            $themeSource = 'request';

            if ($requestTheme === self::PREVIEW_CLEAR_KEYWORD) {
                self::clearPreviewCookie();
                self::log('info', 'Preview theme cleared by ' . self::getCurrentUser());
                return;
            }

            $themeName = $requestTheme;
            if ($themeName === '') {
                $themeName = self::getPreviewThemeFromCookie();
                $themeSource = 'cookie';
            }

            if ($themeName === '') {
                return;
            }

            $user = User::alloc();
            $options = Options::alloc();

            self::debug('User status', [
                'hasLogin' => $user->hasLogin(),
                'uid' => $user->uid,
                'name' => $user->screenName,
                'themeSource' => $themeSource,
            ]);

            $allowVisitors = false;
            try {
                $pluginOptions = $options->plugin('ThemeDemo');
                $allowPreview = $pluginOptions->allowPreview;
                $allowVisitors = is_array($allowPreview) && in_array('visitors', $allowPreview, true);
            } catch (\Exception $e) {
                self::log('warning', 'Failed to get plugin options: ' . $e->getMessage());
            }

            if (!$user->hasLogin() && !$allowVisitors) {
                self::log('access', sprintf(
                    'Access denied for user: %s',
                    $user->hasLogin() ? $user->screenName : 'visitor'
                ));

                if ($themeSource === 'cookie') {
                    self::clearPreviewCookie();
                }
                return;
            }

            if (!self::checkTheme($themeName)) {
                self::log('error', "Theme not found or invalid: {$themeName}");
                if ($themeSource === 'cookie') {
                    self::clearPreviewCookie();
                }
                return;
            }

            if ($themeSource === 'request') {
                self::setPreviewCookie($themeName);
            }

            $currentTheme = (string) $options->theme;
            if ($currentTheme === $themeName) {
                self::debug('Theme already active', [
                    'theme' => $themeName,
                    'source' => $themeSource,
                ]);
                return;
            }

            $currentConfig = [];
            $currentRowName = 'theme:' . $currentTheme;
            if ($options->__isSet($currentRowName)) {
                $currentConfigRaw = $options->$currentRowName;
                $parsedConfig = @unserialize($currentConfigRaw);
                if (is_array($parsedConfig)) {
                    $currentConfig = $parsedConfig;
                }
            }

            $options->__set('theme', $themeName);

            $configFile = __TYPECHO_ROOT_DIR__ . __TYPECHO_THEME_DIR__ . '/' . $themeName . '/functions.php';
            if (is_file($configFile)) {
                require_once $configFile;
                if (function_exists('themeConfig')) {
                    $form = new Form();
                    themeConfig($form);
                    $config = $form->getValues();
                    if (is_array($config) && !empty($config)) {
                        $options->__set('theme:' . $themeName, serialize($config));
                        foreach ($config as $key => $value) {
                            $options->__set($key, $value);
                        }
                    }
                }
            }

            self::debug('Theme switched', [
                'from' => $currentTheme,
                'to' => $themeName,
                'source' => $themeSource,
                'time' => date('Y-m-d H:i:s'),
            ]);

            register_shutdown_function(function () use ($options, $currentTheme, $currentConfig, $currentRowName): void {
                try {
                    $options->__set('theme', $currentTheme);
                    if (!empty($currentConfig)) {
                        $options->__set($currentRowName, serialize($currentConfig));
                        foreach ($currentConfig as $key => $value) {
                            $options->__set($key, $value);
                        }
                    }
                } catch (\Exception $e) {
                    self::log('error', 'Failed to restore theme: ' . $e->getMessage());
                }
            });
        } catch (\Exception $e) {
            self::log('error', 'Error in processTheme: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        }
    }

    private static function checkTheme($themeName)
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $themeName)) {
            self::debug('Invalid theme name format', ['theme' => $themeName]);
            return false;
        }

        $themeRoot = realpath(__TYPECHO_ROOT_DIR__ . __TYPECHO_THEME_DIR__);
        if ($themeRoot === false) {
            self::debug('Theme root not found');
            return false;
        }

        $themeDir = realpath($themeRoot . DIRECTORY_SEPARATOR . $themeName);
        if ($themeDir === false || strpos($themeDir, $themeRoot) !== 0) {
            self::debug('Theme directory not found', ['theme' => $themeName]);
            return false;
        }

        if (!is_file($themeDir . DIRECTORY_SEPARATOR . 'index.php')) {
            self::debug('Required theme file missing', [
                'theme' => $themeName,
                'file' => 'index.php',
            ]);
            return false;
        }

        return true;
    }

    private static function getPreviewThemeFromCookie()
    {
        if (empty($_COOKIE[self::PREVIEW_COOKIE])) {
            return '';
        }

        return trim((string) $_COOKIE[self::PREVIEW_COOKIE]);
    }

    private static function setPreviewCookie($themeName)
    {
        $expiresAt = time() + self::PREVIEW_COOKIE_TTL;

        if (PHP_VERSION_ID >= 70300) {
            setcookie(self::PREVIEW_COOKIE, $themeName, [
                'expires' => $expiresAt,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        } else {
            setcookie(self::PREVIEW_COOKIE, $themeName, $expiresAt, '/', '', false, true);
        }

        $_COOKIE[self::PREVIEW_COOKIE] = $themeName;
    }

    private static function clearPreviewCookie()
    {
        if (PHP_VERSION_ID >= 70300) {
            setcookie(self::PREVIEW_COOKIE, '', [
                'expires' => time() - 3600,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        } else {
            setcookie(self::PREVIEW_COOKIE, '', time() - 3600, '/', '', false, true);
        }

        unset($_COOKIE[self::PREVIEW_COOKIE]);
    }

    private static function getCurrentUser()
    {
        try {
            $user = User::alloc();
            if ($user->hasLogin()) {
                return $user->screenName . ' (uid:' . $user->uid . ')';
            }
            return 'visitor';
        } catch (\Exception $e) {
            return 'unknown';
        }
    }

    private static function debug($message, array $context = [])
    {
        try {
            $options = Options::alloc();
            $pluginConfig = $options->plugin('ThemeDemo');
            $debugMode = is_array($pluginConfig->debugMode) && isset($pluginConfig->debugMode['enable']);

            if ($debugMode) {
                $contextStr = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                self::log('debug', $message . "\nContext: " . $contextStr);
            }
        } catch (\Exception $e) {
            self::log('error', 'Debug failed: ' . $e->getMessage());
        }
    }

    private static function log($type, $message)
    {
        try {
            $logDir = __TYPECHO_ROOT_DIR__ . __TYPECHO_PLUGIN_DIR__ . '/ThemeDemo/logs';
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }

            $logFile = $logDir . '/preview.log';
            $date = date('Y-m-d H:i:s');
            $logMessage = "[$date][$type] $message" . PHP_EOL;
            file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
        } catch (\Exception $e) {
            error_log('ThemeDemo plugin logging failed: ' . $e->getMessage());
        }
    }
}

