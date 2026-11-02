<?php

namespace JosephCrowell\BlogsMd;

use JosephCrowell\BlogsMd\Classes\EditorLock;
use System\Classes\PluginBase;
use System\Classes\SettingsManager;

/**
 * Publishes Winter.Blog posts and categories from Markdown files in a Git repository.
 */
class Plugin extends PluginBase
{
    /**
     * @var array Plugins required by this plugin.
     */
    public $require = ['Winter.Blog'];

    public function pluginDetails(): array
    {
        return [
            'name' => 'josephcrowell.blogsmd::lang.plugin.name',
            'description' => 'josephcrowell.blogsmd::lang.plugin.description',
            'author' => 'Joseph Crowell',
            'icon' => 'icon-code-fork',
            'homepage' => 'https://github.com/josephcrowell/wn-blogs-md-plugin',
        ];
    }

    public function register(): void
    {
        $this->registerConsoleCommand('blogsmd.sync', Console\Sync::class);
    }

    public function boot(): void
    {
        (new EditorLock)->register();
    }

    public function registerSettings(): array
    {
        return [
            'settings' => [
                'label' => 'josephcrowell.blogsmd::lang.plugin.name',
                'description' => 'josephcrowell.blogsmd::lang.settings.description',
                'category' => SettingsManager::CATEGORY_CMS,
                'icon' => 'icon-code-fork',
                'class' => Models\Settings::class,
                'permissions' => ['josephcrowell.blogsmd.access_settings'],
                'order' => 500,
            ],
        ];
    }

    public function registerPermissions(): array
    {
        return [
            'josephcrowell.blogsmd.access_settings' => [
                'tab' => 'josephcrowell.blogsmd::lang.permissions.tab',
                'label' => 'josephcrowell.blogsmd::lang.permissions.access_settings',
            ],
        ];
    }

    public function registerSchedule($schedule): void
    {
        $schedule->command('blogsmd:sync')->everyFifteenMinutes()->withoutOverlapping();
    }
}
