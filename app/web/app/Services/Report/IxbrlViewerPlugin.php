<?php

namespace App\Services\Report;

/**
 * Locates the Workiva iXBRL Viewer plugin (pip package `ixbrl-viewer`) inside the
 * Python environment that owns the Arelle command, so the candidate validation run
 * can also emit a viewer page and the application can serve the viewer script.
 *
 * `services.report.ixbrl_viewer_plugin` forces the plugin on (true) or off (false);
 * null (default) detects it next to the Arelle executable.
 */
class IxbrlViewerPlugin
{
    public const PLUGIN_NAME = 'iXBRLViewerPlugin';

    /** @param  list<string>  $prefix  Arelle command as resolved by ArelleCommandLocator. */
    public static function enabled(array $prefix): bool
    {
        $configured = config('services.report.ixbrl_viewer_plugin');
        if (is_bool($configured)) {
            return $configured;
        }
        if (is_string($configured) && $configured !== '') {
            return filter_var($configured, FILTER_VALIDATE_BOOLEAN);
        }

        return self::packageDirectory($prefix) !== null;
    }

    /** Absolute path of the bundled `ixbrlviewer.js`, when the plugin is installed. */
    public static function scriptPath(?array $prefix = null): ?string
    {
        $prefix ??= ArelleCommandLocator::resolve();
        if (! is_array($prefix) || $prefix === []) {
            return null;
        }
        $package = self::packageDirectory($prefix);
        if ($package === null) {
            return null;
        }
        $script = $package.'/viewer/dist/ixbrlviewer.js';

        return is_file($script) ? $script : null;
    }

    /**
     * The plugin package lives in the site-packages of the virtual environment that
     * provides `bin/arelleCmdLine` (or next to a plain `python -m` style command).
     *
     * @param  list<string>  $prefix
     */
    private static function packageDirectory(array $prefix): ?string
    {
        $executable = $prefix[0] ?? '';
        if (! is_string($executable) || ! str_starts_with($executable, '/')) {
            return null;
        }
        $venv = dirname(dirname($executable));
        foreach (glob($venv.'/lib/python3*/site-packages/'.self::PLUGIN_NAME, GLOB_ONLYDIR) ?: [] as $directory) {
            if (is_file($directory.'/__init__.py')) {
                return $directory;
            }
        }

        return null;
    }
}
