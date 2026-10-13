<?php

declare(strict_types=1);

use Cbox\TelemetryUi\Mcp\Servers\TelemetryServer;
use Cbox\TelemetryUi\Mcp\Tools\MonitorTool;
use Laravel\Mcp\Server\Tool;
use Symfony\Component\Yaml\Yaml;

/*
 * Laravel Boost installs a package's skills from
 * resources/boost/skills/{name}/SKILL.md. A skill whose frontmatter does not
 * parse, or that has no description, is skipped without a word, and a skill
 * that names a tool or argument the server does not have sends an agent
 * looking for something that is not there. Both are pinned here.
 */

function uiPackageRoot(): string
{
    return dirname(__DIR__, 2);
}

/**
 * @return array<string, string> skill directory name => SKILL.md path
 */
function uiBoostSkills(): array
{
    $skills = [];

    foreach (glob(uiPackageRoot().'/resources/boost/skills/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $skills[basename($dir)] = $dir.'/SKILL.md';
    }

    return $skills;
}

/**
 * Tool name => its argument names, for every tool the server can offer.
 *
 * @return array<string, list<string>>
 */
function uiMcpTools(): array
{
    $builtIn = (new ReflectionClass(TelemetryServer::class))->getProperty('tools')->getDefaultValue();
    $tools = [];

    foreach ([...$builtIn, MonitorTool::class] as $class) {
        /** @var Tool $tool */
        $tool = app($class);
        $schema = $tool->toArray()['inputSchema'];
        $tools[$tool->name()] = array_keys((array) $schema['properties']);
    }

    return $tools;
}

it('ships skills Boost can parse', function () {
    expect(uiBoostSkills())->not->toBeEmpty();

    foreach (uiBoostSkills() as $dir => $file) {
        expect($file)->toBeFile();

        // The same split Boost's SkillComposer does.
        $matched = preg_match('/^\s*---[^\S\r\n]*\R(.*?)\R---[^\S\r\n]*(?:\R|$)(.*)$/s', (string) file_get_contents($file), $m);
        expect($matched)->toBe(1, "{$dir}: no frontmatter");

        $meta = Yaml::parse($m[1]);

        expect($meta)->toBeArray()
            ->and($meta['name'] ?? null)->toBe($dir, "{$dir}: name must match its directory")
            ->and($dir)->toMatch('/^telemetry-ui-[a-z0-9]+(-[a-z0-9]+)*$/')
            ->and($meta['description'] ?? null)->toBeString()->not->toBeEmpty()
            ->and(strlen((string) $meta['description']))->toBeLessThanOrEqual(1024)
            ->and(trim($m[2]))->not->toBeEmpty();
    }
});

it('keeps resources/boost in the dist archive', function () {
    $patterns = collect(file(uiPackageRoot().'/.gitattributes', FILE_IGNORE_NEW_LINES) ?: [])
        ->filter(fn (string $line) => str_contains($line, 'export-ignore'))
        ->map(fn (string $line) => ltrim((string) strtok(trim($line), " \t"), '/'));

    foreach (uiBoostSkills() as $file) {
        $path = ltrim(str_replace(uiPackageRoot(), '', $file), '/');

        foreach ($patterns as $pattern) {
            expect($path === $pattern || str_starts_with($path, $pattern.'/') || fnmatch($pattern, $path))
                ->toBeFalse("{$path} is export-ignored by [{$pattern}]");
        }
    }
});

it('documents every MCP tool, and only tools and arguments that exist', function () {
    $tools = uiMcpTools();
    $content = (string) file_get_contents(uiBoostSkills()['telemetry-ui-mcp']);

    foreach (array_keys($tools) as $name) {
        expect($content)->toContain("`{$name}`");
    }

    // The tools table: | `tool` / `tool` | what | `arg`, `arg` (default) |
    preg_match_all('/^\| (`[a-z_]+`(?: \/ `[a-z_]+`)*) \|[^|]*\|([^|]*)\|$/m', $content, $rows, PREG_SET_ORDER);
    expect($rows)->toHaveCount(count($tools) - 1); // query_metrics / query_range share a row

    foreach ($rows as [, $names, $args]) {
        preg_match_all('/`([a-z_]+)`/', $names, $named);
        preg_match_all('/`([a-z_]+)`/', $args, $arguments);

        foreach ($named[1] as $name) {
            expect($tools)->toHaveKey($name);
        }

        $known = array_merge(...array_map(fn (string $name) => $tools[$name], $named[1]));

        foreach ($arguments[1] as $argument) {
            expect($known)->toContain($argument);
        }
    }
});
