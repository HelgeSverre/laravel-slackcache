<?php

use HelgeSverre\SlackCache\Exceptions\SlackCacheException;

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->each->not->toBeUsed();

arch('exceptions extend the package base exception')
    ->expect('HelgeSverre\SlackCache\Exceptions')
    ->toExtend(SlackCacheException::class)
    ->ignoring(SlackCacheException::class);

arch('the package never reads the environment directly')
    ->expect('HelgeSverre\\SlackCache')
    ->not->toUse('env');
