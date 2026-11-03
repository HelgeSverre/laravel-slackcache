<?php

use Illuminate\Support\Facades\Blade;

it('ships Boost guidelines that render as Blade', function () {
    $rendered = Blade::render(file_get_contents(__DIR__.'/../../resources/boost/guidelines/core.blade.php'));

    expect($rendered)->toContain('SlackCache::fake()')->toContain('<code-snippet')->not->toContain('@verbatim');
});

it('ships a Boost skill with name and description frontmatter', function () {
    $skill = file_get_contents(__DIR__.'/../../resources/boost/skills/slackcache-development/SKILL.md');

    expect($skill)->toMatch('/\A---\nname: slackcache-development\ndescription: .+\n---\n/');
});
