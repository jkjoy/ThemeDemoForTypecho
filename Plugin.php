<?php

namespace TypechoPlugin\ThemeDemo;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Widget\Options;
use Widget\User;
use Typecho\Request as HttpRequest;
use Typecho\Widget;
use Typecho\Router;
use Typecho\Db;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Typecho 主题预览插件
 * 
 * @package ThemeDemo
 * @author jkjoy
 * @version 1.0.5
 * @link https://github.com/jkjoy/ThemeDemoForTypecho
 */
class Plugin implements PluginInterface
{
    /**
     * 激活插件
     */
    public static function activate()
    {
        // 全局监听请求，支持所有页面
        \Typecho\Plugin::factory('Widget_Archive')->beforeRender = [__CLASS__, 'processTheme'];
        
        self::log('activate', 'Plugin activated by ' . self::getCurrentUser());
        return _t('插件已经激活，现在可以通过 ?theme=主题目录名 来预览主题');
    }

    /**
     * 禁用插件
     */
    public static function deactivate()
    {
        self::log('deactivate', 'Plugin deactivated by ' . self::getCurrentUser());
        return _t('插件已被禁用');
    }

    /**
     * 获取插件配置面板
     */
    public static function config(Form $form)
    {
        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Checkbox(
            'allowPreview',
            ['visitors' => _t('允许访客预览主题')],
            [],
            _t('预览权限'),
            _t('默认只有管理员可以预览主题')
        ));

        $form->addInput(new \Typecho\Widget\Helper\Form\Element\Checkbox(
            'debugMode',
            ['enable' => _t('启用调试模式')],
            [],
            _t('调试模式'),
            _t('启用后将输出详细的调试信息到日志文件')
        ));
    }

    /**
     * 个人用户的配置面板
     */
    public static function personalConfig(Form $form)
    {
    }

    /**
     * 处理主题预览请求
     */
    public static function processTheme($archive)
    {
        try {
            $request = new HttpRequest();
            $themeName = trim($request->get('theme', ''));
            
            if (empty($themeName)) {
                return;
            }

            // 获取用户和选项
            $user = User::alloc();
            $options = Options::alloc();
            
            // 调试用户状态
            self::debug('User status', [
                'hasLogin' => $user->hasLogin(),
                'uid' => $user->uid,
                'name' => $user->screenName
            ]);

            // 检查权限
            $allowVisitors = false;
            try {
                $pluginOptions = $options->plugin('ThemeDemo');
                $allowVisitors = !empty($pluginOptions->allowPreview) && 
                               in_array('visitors', $pluginOptions->allowPreview);
            } catch (\Exception $e) {
                self::log('warning', 'Failed to get plugin options: ' . $e->getMessage());
            }

            // 权限检查
            if (!$user->hasLogin() && !$allowVisitors) {
                self::log('access', sprintf('Access denied for user: %s', 
                    $user->hasLogin() ? $user->screenName : 'visitor'));
                return;
            }

            self::debug('Access granted', [
                'user' => $user->screenName,
                'isAdmin' => $user->hasLogin(),
                'allowVisitors' => $allowVisitors
            ]);

            // 检查主题是否存在
            if (!self::check($themeName)) {
                self::log('error', "Theme not found or invalid: {$themeName}");
                return;
            }

            // 保存当前主题设置
            $currentTheme = $options->theme;
            $currentConfig = [];
            $currentRowName = 'theme:' . $currentTheme;
            
            if ($options->__isSet($currentRowName)) {
                $currentConfig = @unserialize($options->$currentRowName);
                if ($currentConfig === false) {
                    $currentConfig = [];
                }
            }

            // 设置新主题
            $options->__set('theme', $themeName);
            
            // 加载新主题配置
            $configFile = __TYPECHO_ROOT_DIR__ . __TYPECHO_THEME_DIR__ . '/' . $themeName . '/functions.php';
            if (file_exists($configFile)) {
                require_once $configFile;
                if (function_exists('themeConfig')) {
                    $form = new Form();
                    themeConfig($form);
                    $config = $form->getValues();
                    if ($config) {
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
                'time' => date('Y-m-d H:i:s')
            ]);

            // 注册关闭时的回调，恢复原主题设置
            register_shutdown_function(function() use ($options, $currentTheme, $currentConfig, $currentRowName) {
                try {
                    $options->__set('theme', $currentTheme);
                    if ($currentConfig) {
                        $options->__set($currentRowName, serialize($currentConfig));
                        foreach ($currentConfig as $key => $value) {
                            $options->__set($key, $value);
                        }
                    }
                    self::debug('Theme restored', [
                        'theme' => $currentTheme,
                        'time' => date('Y-m-d H:i:s')
                    ]);
                } catch (\Exception $e) {
                    self::log('error', 'Failed to restore theme: ' . $e->getMessage());
                }
            });

        } catch (\Exception $e) {
            self::log('error', 'Error in processTheme: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        }
    }

    /**
     * 检查主题是否存在
     */
    private static function check($path)
    {
        $dir = __TYPECHO_ROOT_DIR__ . __TYPECHO_THEME_DIR__ . '/' . $path;
        self::debug('Checking theme directory', ['path' => $dir]);
        
        if (!is_dir($dir)) {
            self::debug('Theme directory not found', ['path' => $dir]);
            return false;
        }
        
        // 检查必需的主题文件
        $requiredFiles = ['index.php'];
        foreach ($requiredFiles as $file) {
            if (!file_exists($dir . '/' . $file)) {
                self::debug('Required theme file missing', ['file' => $file]);
                return false;
            }
        }
        
        self::debug('Theme directory exists and valid', ['path' => $dir]);
        return true;
    }

    /**
     * 获取当前用户
     */
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

    /**
     * 记录调试信息
     */
    private static function debug($message, array $context = [])
    {
        try {
            $options = Options::alloc();
            $debugMode = isset($options->plugin('ThemeDemo')->debugMode['enable']);
            
            if ($debugMode) {
                $contextStr = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                self::log('debug', $message . "\nContext: " . $contextStr);
            }
        } catch (\Exception $e) {
            self::log('error', 'Debug failed: ' . $e->getMessage());
        }
    }

    /**
     * 记录日志
     */
    private static function log($type, $message)
    {
        try {
            $logDir = __TYPECHO_ROOT_DIR__ . __TYPECHO_PLUGIN_DIR__ .'/ThemeDemo/logs';
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            
            $logFile = $logDir . '/preview.log';
            $date = date('Y-m-d H:i:s');
            $logMessage = "[$date][$type] $message" . PHP_EOL;
            file_put_contents($logFile, $logMessage, FILE_APPEND);
        } catch (\Exception $e) {
            error_log('ThemeDemo plugin logging failed: ' . $e->getMessage());
        }
    }
}