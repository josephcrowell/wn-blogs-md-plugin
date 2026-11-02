<?php

namespace JosephCrowell\BlogsMd\Tests;

use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Bus;
use JosephCrowell\BlogsMd\Models\Settings;
use System\Behaviors\SettingsModel;
use System\Tests\Bootstrap\PluginTestCase;
use Winter\Storm\Support\Facades\Config;

class GitHubWebhookTest extends PluginTestCase
{
    protected const URI = '/webhooks/blogsmd/github';
    protected const SECRET = 'test-secret';

    public function setUp(): void
    {
        parent::setUp();

        // PluginTestCase replaces the route collection and reloads only module routes.
        require plugins_path('josephcrowell/blogsmd/routes.php');

        Config::set('josephcrowell.blogsmd::webhook_secret', static::SECRET);
        Config::set('josephcrowell.blogsmd::repository', 'https://github.com/Sonic-DE/sonic-blogs.git');
        Config::set('josephcrowell.blogsmd::branch', 'master');
        SettingsModel::clearInternalCache();
        Bus::fake();
    }

    public function testRefusesWhenSecretIsNotConfigured()
    {
        Config::set('josephcrowell.blogsmd::webhook_secret', '');

        $this->send('ping', [])->assertStatus(500);
    }

    public function testRejectsUnsignedRequest()
    {
        $this->send('ping', [], false)->assertStatus(401);
    }

    public function testRejectsWrongSignature()
    {
        $this->send('ping', [], 'sha256=' . str_repeat('0', 64))->assertStatus(401);
    }

    public function testAnswersPing()
    {
        $this->send('ping', ['zen' => 'Design for failure.'])->assertStatus(200);
    }

    public function testIgnoresPushToOtherBranch()
    {
        $this->send('push', $this->pushPayload('refs/heads/feature'))->assertStatus(202);

        Bus::assertNothingDispatched();
    }

    public function testIgnoresPushFromAnotherRepository()
    {
        $this->send('push', $this->pushPayload('refs/heads/master', 'Someone/else'))->assertStatus(202);

        Bus::assertNothingDispatched();
    }

    public function testPushToBranchDispatchesSyncAfterResponse()
    {
        $this->send('push', $this->pushPayload('refs/heads/master', 'sonic-de/Sonic-Blogs'))->assertStatus(202);

        Bus::assertDispatchedAfterResponse(CallQueuedClosure::class);
    }

    public function testBackendSettingsReplaceTheConfig()
    {
        Settings::set([
            'webhook_secret' => 'backend-secret',
            'repository' => 'https://github.com/Sonic-DE/other-blog',
            'branch' => 'main',
        ]);
        $payload = $this->pushPayload('refs/heads/main', 'Sonic-DE/other-blog');
        $signature = 'sha256=' . hash_hmac('sha256', json_encode($payload), 'backend-secret');

        // The config secret no longer works; the backend one does, for the backend's repository and branch.
        $this->send('push', $payload)->assertStatus(401);
        $this->send('push', $payload, $signature)->assertStatus(202);
        Bus::assertDispatchedAfterResponse(CallQueuedClosure::class);
    }

    public function testEmptyBackendSettingsFallBackToTheConfig()
    {
        Settings::set(['webhook_secret' => '', 'repository' => '', 'branch' => '']);

        $this->send('push', $this->pushPayload('refs/heads/master'))->assertStatus(202);
        Bus::assertDispatchedAfterResponse(CallQueuedClosure::class);
    }

    protected function pushPayload(string $ref, string $repository = 'Sonic-DE/sonic-blogs'): array
    {
        return [
            'ref' => $ref,
            'repository' => ['full_name' => $repository],
        ];
    }

    /**
     * Sends a webhook request. `$signature` true signs with the configured secret, false sends none,
     * and a string is sent as the signature header verbatim.
     */
    protected function send(string $event, array $payload, bool|string $signature = true)
    {
        $body = json_encode($payload);
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => $event,
        ];

        if ($signature === true) {
            $server['HTTP_X_HUB_SIGNATURE_256'] = 'sha256=' . hash_hmac('sha256', $body, static::SECRET);
        } elseif (is_string($signature)) {
            $server['HTTP_X_HUB_SIGNATURE_256'] = $signature;
        }

        return $this->call('POST', static::URI, [], [], [], $server, $body);
    }
}
