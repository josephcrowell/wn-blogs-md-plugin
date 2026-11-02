<?php

namespace JosephCrowell\BlogsMd\Tests;

use JosephCrowell\BlogsMd\Classes\EditorLock;
use System\Tests\Bootstrap\PluginTestCase;

class EditorLockTest extends PluginTestCase
{
    public function testRedirectsLockedPageActions()
    {
        $cases = [
            [EditorLock::POSTS, 'create', [], 'winter/blog/posts'],
            [EditorLock::POSTS, 'import', [], 'winter/blog/posts'],
            [EditorLock::POSTS, 'update', ['7'], 'winter/blog/posts/preview/7'],
            [EditorLock::CATEGORIES, 'create', [], 'winter/blog/categories'],
            [EditorLock::CATEGORIES, 'reorder', [], 'winter/blog/categories'],
            [EditorLock::CATEGORIES, 'update', ['3'], 'winter/blog/categories/preview/3'],
        ];

        foreach ($cases as [$controller, $action, $params, $expected]) {
            $this->assertSame($expected, EditorLock::redirectFor($controller, $action, $params), $controller . '@' . $action);
        }
    }

    public function testAllowsReadOnlyActionsAndOtherControllers()
    {
        $this->assertNull(EditorLock::redirectFor(EditorLock::POSTS, 'index', []));
        $this->assertNull(EditorLock::redirectFor(EditorLock::POSTS, 'preview', ['7']));
        $this->assertNull(EditorLock::redirectFor(EditorLock::POSTS, 'export', []));
        $this->assertNull(EditorLock::redirectFor(EditorLock::POSTS, 'update', []));
        $this->assertNull(EditorLock::redirectFor('Backend\Controllers\Users', 'create', []));
    }

    public function testBlocksWriteHandlers()
    {
        foreach ([EditorLock::POSTS, EditorLock::CATEGORIES] as $controller) {
            foreach (EditorLock::BLOCKED_HANDLERS as $handler) {
                $this->assertTrue(EditorLock::isBlockedHandler($controller, $handler), $controller . '::' . $handler);
            }
        }
    }

    public function testRichEditorSwitchIsHiddenOnlyWhileOff()
    {
        $this->assertTrue(EditorLock::hidesRichEditorSwitch(false));
        $this->assertFalse(EditorLock::hidesRichEditorSwitch(true));
    }

    public function testAllowsReadHandlersAndOtherControllers()
    {
        $this->assertFalse(EditorLock::isBlockedHandler(EditorLock::POSTS, 'onExport'));
        $this->assertFalse(EditorLock::isBlockedHandler(EditorLock::POSTS, 'onRefreshPreview'));
        $this->assertFalse(EditorLock::isBlockedHandler(EditorLock::POSTS, 'listPosts::onSort'));
        $this->assertFalse(EditorLock::isBlockedHandler('Backend\Controllers\Users', 'onSave'));
    }
}
