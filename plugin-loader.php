<?php
/**
 * Generic loader for optional bot features that live outside this repo.
 *
 * Each feature is a directory under plugins/ with a plugin.php that returns an
 * object exposing a tick() method. The manager loads them and ticks them once
 * per bot cycle. Any failure — loading or ticking — is logged and swallowed so
 * a feature can never break the core bot.
 *
 * The plugins/ directory itself is gitignored (private features).
 */

class BotPluginManager
{
    private $plugins = [];

    public function load($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*/plugin.php') as $file) {
            try {
                $plugin = require $file;
                if (is_object($plugin) && method_exists($plugin, 'tick')) {
                    $this->plugins[] = $plugin;
                }
            } catch (\Throwable $e) {
                error_log('[plugins] failed to load ' . $file . ': ' . $e->getMessage());
            }
        }
    }

    public function tick()
    {
        foreach ($this->plugins as $plugin) {
            try {
                $plugin->tick();
            } catch (\Throwable $e) {
                error_log('[plugins] ' . get_class($plugin) . ' tick error: ' . $e->getMessage());
            }
        }
    }
}
