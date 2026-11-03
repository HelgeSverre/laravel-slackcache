<?php

use HelgeSverre\SlackCache\Testing\FakeSlack;

beforeEach(function () {
    $this->slack = FakeSlack::install();
    $this->travelTo(now()->setTimestamp(1_800_000_000));
});

it('passes on a healthy setup and leaves the channel as it was', function () {
    $this->artisan('slackcache:doctor')
        ->expectsOutputToContain('bot token · Fake Workspace')
        ->expectsOutputToContain('chat:write, channels:read')
        ->expectsOutputToContain('Post and delete #slackcache')
        ->expectsOutputToContain('All checks passed')
        ->assertSuccessful();

    expect($this->slack->messages('slackcache'))->toBeEmpty();
});

it('fails on an invalid token', function () {
    $this->slack->fail('invalid_auth', times: 10);

    $this->artisan('slackcache:doctor')
        ->expectsOutputToContain('invalid_auth')
        ->assertFailed();
});

it('names missing scopes', function () {
    $this->slack->scopes = ['chat:write', 'channels:read'];

    $this->artisan('slackcache:doctor')
        ->expectsOutputToContain('missing channels:history, channels:join')
        ->assertFailed();
});

it('only warns about channels:manage when the channel already exists', function () {
    $this->slack->createChannel('slackcache');
    $this->slack->scopes = ['chat:write', 'channels:read', 'channels:history', 'channels:join'];

    $this->artisan('slackcache:doctor')
        ->expectsOutputToContain('All checks passed')
        ->assertSuccessful();
});

it('warns that slackcache:topic needs channels:manage or channels:write.topic', function () {
    $this->slack->createChannel('slackcache');
    $this->slack->scopes = ['chat:write', 'channels:read', 'channels:history', 'channels:join'];

    $this->artisan('slackcache:doctor')->expectsOutputToContain('slackcache:topic needs it or channels:manage')->assertSuccessful();

    $this->slack->scopes[] = 'channels:write.topic';

    $this->artisan('slackcache:doctor')->doesntExpectOutputToContain('slackcache:topic needs it')->assertSuccessful();
});

it('warns about a per-process replica', function () {
    config()->set('cache.stores.slack.replica', null);

    $this->artisan('slackcache:doctor')
        ->expectsOutputToContain('per-process')
        ->assertSuccessful();
});

it('checks encryption can encrypt', function () {
    config()->set('cache.stores.slack.encrypt', true);
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));

    $this->artisan('slackcache:doctor')
        ->expectsOutputToContain('Encryption')
        ->assertSuccessful();
});

it('skips the scope check for browser session tokens', function () {
    $this->slack->isBot = false;
    config()->set('cache.stores.slack.token', 'xoxc-session');

    $this->artisan('slackcache:doctor')
        ->expectsOutputToContain('not checked for browser session tokens')
        ->assertSuccessful();
});

it('needs reactions:write when reactions are on', function () {
    config()->set('cache.stores.slack.reactions', true);

    $this->artisan('slackcache:doctor')->expectsOutputToContain('the reactions option needs it')->assertFailed();

    $this->slack->scopes[] = 'reactions:write';
    $this->artisan('slackcache:doctor')->expectsOutputToContain('All checks passed')->assertSuccessful();
});
