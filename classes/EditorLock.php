<?php

namespace JosephCrowell\BlogsMd\Classes;

use Backend\Facades\Backend;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Response;
use System\Classes\PluginManager;
use Winter\Storm\Support\Facades\Event;
use Winter\Storm\Support\Facades\Flash;

/**
 * Makes Winter.Blog's backend read-only: posts and categories are managed in Git.
 *
 * The decisions live in `redirectFor()` and `isBlockedHandler()`, so they can be tested without a backend
 * controller. The hooks only act on backend HTTP requests; model saves from a sync are unaffected.
 */
class EditorLock
{
    public const POSTS = 'Winter\Blog\Controllers\Posts';
    public const CATEGORIES = 'Winter\Blog\Controllers\Categories';

    public const BLOCKED_HANDLERS = [
        'onSave',
        'onDelete',
        'onImport',
        'onImportLoadForm',
        'onImportLoadColumnSampleForm',
        'onReorder',
    ];

    public function register(): void
    {
        if (!PluginManager::instance()->exists('Winter.Blog')) {
            return;
        }

        Event::listen('backend.menu.extendItems', function ($manager) {
            $manager->removeSideMenuItem('Winter.Blog', 'blog', 'new_post');
        });

        Event::listen('backend.page.beforeDisplay', function ($controller, $action, $params) {
            if ($path = static::redirectFor(get_class($controller), (string) $action, (array) $params)) {
                Flash::warning(Lang::get('josephcrowell.blogsmd::lang.locked'));
                return Backend::redirect($path);
            }
        });

        Event::listen('backend.ajax.beforeRunHandler', function ($controller, $handler) {
            if (static::isBlockedHandler(get_class($controller), (string) $handler)) {
                return Response::make(Lang::get('josephcrowell.blogsmd::lang.locked'), 403);
            }
        });

        \Winter\Blog\Controllers\Posts::extend(function ($controller) {
            static::mergeListConfig($controller, '$/josephcrowell/blogsmd/config/posts_config_list.yaml');
        });

        \Winter\Blog\Controllers\Categories::extend(function ($controller) {
            static::mergeListConfig($controller, '$/josephcrowell/blogsmd/config/categories_config_list.yaml');
        });

        \Winter\Blog\Controllers\Posts::extendFormFields(function ($form, $model, $context) {
            if ($model instanceof \Winter\Blog\Models\Post && $context === 'preview') {
                $form->addFields([
                    '_managed_in_git' => [
                        'type' => 'partial',
                        'path' => '$/josephcrowell/blogsmd/partials/_managed_in_git.htm',
                    ],
                ]);
            }
        });

        Event::listen('backend.form.extendFields', function ($widget) {
            if (
                !$widget->isNested
                && $widget->model instanceof \Winter\Blog\Models\Settings
                && static::hidesRichEditorSwitch((bool) $widget->model->getSettingsValue('use_rich_editor', false))
            ) {
                $widget->removeField('use_rich_editor');
            }
        });
    }

    /**
     * Merges our list overrides over the controller's list config, before its ListController is built.
     *
     * Winter.Blog's controllers don't declare `$listConfig` (the behavior supplies `config_list.yaml`), and
     * Storm silently drops an assignment to an undeclared property, so it must be added as a dynamic
     * property; ListController reads it through `extendableGet()`.
     */
    protected static function mergeListConfig($controller, string $overrides): void
    {
        $merged = $controller->mergeConfig($controller->listConfig ?: 'config_list.yaml', $overrides);

        if (property_exists($controller, 'listConfig')) {
            $controller->listConfig = $merged;
        } else {
            $controller->addDynamicProperty('listConfig', $merged);
        }
    }

    /**
     * Whether Winter.Blog's rich-editor switch is hidden from its settings form. Only while it's off: when it's
     * on (sync refuses to run), the switch stays visible so it can be turned off.
     */
    public static function hidesRichEditorSwitch(bool $richEditorOn): bool
    {
        return !$richEditorOn;
    }

    /**
     * The backend path a locked page action redirects to, or null when the action is allowed.
     */
    public static function redirectFor(string $controllerClass, string $action, array $params): ?string
    {
        if ($controllerClass === static::POSTS) {
            if (in_array($action, ['create', 'import'], true)) {
                return 'winter/blog/posts';
            }
            if ($action === 'update' && !empty($params[0])) {
                return 'winter/blog/posts/preview/' . $params[0];
            }
        }

        if ($controllerClass === static::CATEGORIES) {
            if (in_array($action, ['create', 'reorder'], true)) {
                return 'winter/blog/categories';
            }
            if ($action === 'update' && !empty($params[0])) {
                return 'winter/blog/categories/preview/' . $params[0];
            }
        }

        return null;
    }

    /**
     * Whether an AJAX handler on a Winter.Blog controller writes data and must be refused.
     */
    public static function isBlockedHandler(string $controllerClass, string $handler): bool
    {
        return in_array($controllerClass, [static::POSTS, static::CATEGORIES], true)
            && in_array($handler, static::BLOCKED_HANDLERS, true);
    }
}
