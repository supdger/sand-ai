<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

use app\Api\Support\ApiProblem;
use app\Infrastructure\Capability\CapabilityDriverRegistry;

/** Versioned, code-owned Chinese setup catalogue; it never creates configuration. */
final class SolutionGuideCatalog
{
    private const VERSION = 'solution_guide@1';

    /** @return array<string,mixed> */
    public static function index(): array
    {
        $configuration = require dirname(__DIR__, 3) . '/config/sand_ai_solution_guides.php';
        if (!is_array($configuration)) {
            self::invalid();
        }

        return self::fromConfiguration($configuration);
    }

    /** @param array<string,mixed> $configuration @return array<string,mixed> */
    public static function fromConfiguration(array $configuration): array
    {
        self::assertRoot($configuration);
        $moduleCodes = self::assertModules($configuration['modules']);
        $templates = self::projectTemplates($configuration['templates'], $moduleCodes);

        return [
            'display_name' => $configuration['display_name'],
            'plain_summary' => $configuration['plain_summary'],
            'templates' => $templates,
            'modules' => self::projectModules($configuration['modules']),
            'professional_mode_hint' => $configuration['professional_mode_hint'],
            'human_collaboration' => self::projectHumanCollaboration($configuration['human_collaboration']),
            'technical' => [
                'catalog_code' => 'solution_guide',
                'version' => self::VERSION,
            ],
        ];
    }

    /** @param array<string,mixed> $configuration */
    private static function assertRoot(array $configuration): void
    {
        self::assertKeys($configuration, ['version', 'display_name', 'plain_summary', 'professional_mode_hint', 'templates', 'modules', 'human_collaboration']);
        if (($configuration['version'] ?? null) !== self::VERSION
            || !is_array($configuration['templates'] ?? null) || !array_is_list($configuration['templates']) || $configuration['templates'] === []
            || !is_array($configuration['modules'] ?? null) || !array_is_list($configuration['modules']) || $configuration['modules'] === []) {
            self::invalid();
        }
        foreach (['display_name', 'plain_summary', 'professional_mode_hint'] as $field) {
            self::assertChineseText($configuration[$field] ?? null);
        }
        self::assertHumanCollaboration($configuration['human_collaboration'] ?? null);
        self::assertNoSensitiveConfiguration($configuration);
    }

    /** @param list<mixed> $modules @return array<string,true> */
    private static function assertModules(array $modules): array
    {
        $codes = [];
        foreach ($modules as $module) {
            if (!is_array($module)) {
                self::invalid();
            }
            self::assertKeys($module, ['code', 'display_name', 'plain_summary', 'when_to_use', 'combination_relation', 'capability_refs']);
            $code = self::assertCode($module['code'] ?? null);
            if (isset($codes[$code])) {
                self::invalid();
            }
            $codes[$code] = true;
            foreach (['display_name', 'plain_summary', 'when_to_use', 'combination_relation'] as $field) {
                self::assertChineseText($module[$field] ?? null);
            }
            self::assertCapabilities($module['capability_refs'] ?? null);
        }

        return $codes;
    }

    /** @param list<mixed> $templates @param array<string,true> $moduleCodes @return list<array<string,mixed>> */
    private static function projectTemplates(array $templates, array $moduleCodes): array
    {
        $codes = [];
        $result = [];
        foreach ($templates as $template) {
            if (!is_array($template)) {
                self::invalid();
            }
            self::assertKeys($template, ['code', 'display_name', 'plain_summary', 'when_to_use', 'what_you_need', 'outcome', 'setup_steps', 'module_explanation', 'readiness_note', 'module_refs', 'capability_refs']);
            $code = self::assertCode($template['code'] ?? null);
            if (isset($codes[$code]) || isset($moduleCodes[$code])) {
                self::invalid();
            }
            $codes[$code] = true;
            foreach (['display_name', 'plain_summary', 'when_to_use', 'outcome', 'module_explanation', 'readiness_note'] as $field) {
                self::assertChineseText($template[$field] ?? null);
            }
            self::assertChineseList($template['what_you_need'] ?? null);
            self::assertChineseList($template['setup_steps'] ?? null);
            self::assertModuleRefs($template['module_refs'] ?? null, $moduleCodes);
            self::assertCapabilities($template['capability_refs'] ?? null);
            $result[] = [
                'display_name' => $template['display_name'],
                'plain_summary' => $template['plain_summary'],
                'when_to_use' => $template['when_to_use'],
                'what_you_need' => $template['what_you_need'],
                'outcome' => $template['outcome'],
                'setup_steps' => $template['setup_steps'],
                'module_explanation' => $template['module_explanation'],
                'readiness_note' => $template['readiness_note'],
                'technical' => ['code' => $code, 'version' => self::VERSION, 'capability_codes' => $template['capability_refs'], 'module_codes' => $template['module_refs']],
            ];
        }

        usort($result, static fn (array $left, array $right): int => $left['technical']['code'] <=> $right['technical']['code']);
        return $result;
    }

    /** @param list<mixed> $modules @return list<array<string,mixed>> */
    private static function projectModules(array $modules): array
    {
        $result = [];
        foreach ($modules as $module) {
            $result[] = [
                'display_name' => $module['display_name'],
                'plain_summary' => $module['plain_summary'],
                'when_to_use' => $module['when_to_use'],
                'combination_relation' => $module['combination_relation'],
                'technical' => ['code' => $module['code'], 'version' => self::VERSION, 'capability_codes' => $module['capability_refs']],
            ];
        }
        usort($result, static fn (array $left, array $right): int => $left['technical']['code'] <=> $right['technical']['code']);
        return $result;
    }

    /** @param array<string,mixed> $collaboration @return array<string,mixed> */
    private static function projectHumanCollaboration(array $collaboration): array
    {
        return [
            'display_name' => $collaboration['display_name'],
            'plain_summary' => $collaboration['plain_summary'],
            'when_to_use' => $collaboration['when_to_use'],
            'combination_relation' => $collaboration['combination_relation'],
            'technical' => ['code' => 'human_collaboration', 'version' => self::VERSION],
        ];
    }

    private static function assertHumanCollaboration(mixed $collaboration): void
    {
        if (!is_array($collaboration)) {
            self::invalid();
        }
        self::assertKeys($collaboration, ['display_name', 'plain_summary', 'when_to_use', 'combination_relation']);
        foreach (['display_name', 'plain_summary', 'when_to_use', 'combination_relation'] as $field) {
            self::assertChineseText($collaboration[$field] ?? null);
        }
    }

    /** @param array<string,mixed> $value @param list<string> $keys */
    private static function assertKeys(array $value, array $keys): void
    {
        if (array_diff(array_keys($value), $keys) !== [] || array_diff($keys, array_keys($value)) !== []) {
            self::invalid();
        }
    }

    private static function assertCode(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9_]{1,63}$/', $value) !== 1) {
            self::invalid();
        }
        return $value;
    }

    private static function assertChineseText(mixed $value): void
    {
        if (!is_string($value) || trim($value) === '' || preg_match('/\\p{Han}/u', $value) !== 1) {
            self::invalid();
        }
    }

    /** @param mixed $values */
    private static function assertChineseList(mixed $values): void
    {
        if (!is_array($values) || !array_is_list($values) || $values === []) {
            self::invalid();
        }
        foreach ($values as $value) {
            self::assertChineseText($value);
        }
    }

    /** @param mixed $references @param array<string,true> $moduleCodes */
    private static function assertModuleRefs(mixed $references, array $moduleCodes): void
    {
        if (!is_array($references) || !array_is_list($references) || $references === [] || count($references) !== count(array_unique($references))) {
            self::invalid();
        }
        foreach ($references as $reference) {
            if (!is_string($reference) || !isset($moduleCodes[$reference])) {
                self::invalid();
            }
        }
    }

    /** @param mixed $references */
    private static function assertCapabilities(mixed $references): void
    {
        if (!is_array($references) || !array_is_list($references) || count($references) !== count(array_unique($references))) {
            self::invalid();
        }
        foreach ($references as $reference) {
            if (!is_string($reference)) {
                self::invalid();
            }
            try {
                CapabilityDriverRegistry::capability($reference);
            } catch (ApiProblem) {
                self::invalid();
            }
        }
    }

    private static function assertNoSensitiveConfiguration(mixed $value, ?string $key = null): void
    {
        if ($key !== null && preg_match('/(?:secret|key|credential|password|token|provider|url|endpoint)/i', $key) === 1) {
            self::invalid();
        }
        if (is_string($value) && preg_match('/https?:\\/\\//i', $value) === 1) {
            self::invalid();
        }
        if (is_array($value)) {
            foreach ($value as $childKey => $child) {
                self::assertNoSensitiveConfiguration($child, is_string($childKey) ? $childKey : null);
            }
        }
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_SOLUTION_GUIDE_INVALID', 'Solution guide catalog is unavailable');
    }
}
