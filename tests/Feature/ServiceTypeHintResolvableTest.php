<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 搬运方法时的两类"静默失效"静态护栏：
 *   1. 方法类型提示必须能解析到真实存在的类；
 *   2. `$this->方法名(` 必须真的存在这个方法。
 *
 * 为什么需要这两条（都是 2026-09-21 拆分 SkillAgentRunnerService 时实测踩到的）：
 *   1. 类型提示**不会跟着 import 走**：`markdownChunkData(AiProviderCallResult $llm)` 搬进
 *      `SkillChunkPlan` 时漏了 `use App\Services\DTOs\AiProviderCallResult;`，PHP 立刻去
 *      `App\Services\SkillAgent\` 下找（不存在）——静态检查与构造都不报错，只有真调用才炸；
 *   2. 改方法名容易漏掉类内部的调用点：把 `countClaimedFields` 改名成 `countClaimed` 时，
 *      类内那句 `$this->countClaimedFields(...)` 没跟着改 —— 同样只有真跑到才炸
 *      （实测是探针一调就报 "Call to undefined method"）。
 *
 * 两条都用反射 + 源码扫描实现，覆盖本轮拆分集中的三个目录。
 */
class ServiceTypeHintResolvableTest extends TestCase
{
    /** 关注这几个目录：本轮拆分集中在这里，且都是"被搬来搬去"的重灾区。 */
    private const DIRECTORIES = [
        'Services/SkillAgent',
        'Services/Billing',
        'Console/Commands',
    ];

    public function test_all_service_type_hints_resolve_to_existing_classes(): void
    {
        $unresolvable = [];
        $checked = 0;

        foreach (self::DIRECTORIES as $directory) {
            foreach (\Illuminate\Support\Facades\File::files(app_path($directory)) as $fileInfo) {
                // 从绝对路径推出 FQCN：去掉 app 根前缀与 .php，再把分隔符换成反斜杠。
                $path = str_replace('\\', '/', $fileInfo->getPathname());
                $path = str_replace(str_replace('\\', '/', app_path()).'/', '', $path);
                $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $path);
                if (! class_exists($class)) {
                    continue;
                }
                $reflection = new \ReflectionClass($class);

                foreach ($reflection->getMethods() as $method) {
                    $types = [];
                    foreach ($method->getParameters() as $parameter) {
                        $types[] = $parameter->getType();
                    }
                    $types[] = $method->getReturnType();

                    foreach ($types as $type) {
                        foreach ($this->typeNames($type) as $name) {
                            $checked++;
                            if ($this->isBuiltin($name)) {
                                continue;
                            }
                            if (class_exists($name) || interface_exists($name) || enum_exists($name)) {
                                continue;
                            }
                            $unresolvable[] = "{$class}::{$method->getName()}() → {$name}";
                        }
                    }
                }
            }
        }

        $this->assertGreaterThan(200, $checked, '扫到的类型提示太少，八成是路径写错了');
        $this->assertSame([], array_values(array_unique($unresolvable)), implode("\n", array_unique($unresolvable)));
    }

    public function test_no_calls_to_missing_methods(): void
    {
        $missing = [];
        $checked = 0;

        foreach ($this->classes() as $class) {
            $reflection = new \ReflectionClass($class);
            $source = (string) file_get_contents($reflection->getFileName());

            // 只保留 `$this->name(` 形式；命名空间/静态调用与字符串里的同形文本都不参与。
            preg_match_all('/\$this->([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $matches);
            foreach (array_unique($matches[1]) as $name) {
                $checked++;
                // 魔术方法与动态调用不在本护栏范围；属性调用（`$this->foo(` 不可能是属性）都已计入。
                if (method_exists($class, $name)) {
                    continue;
                }
                if (str_starts_with($name, '__')) {
                    continue;
                }
                $missing[] = "{$class}::\${$name}() 不存在";
            }
        }

        $this->assertGreaterThan(100, $checked, '扫到的方法调用太少，八成是路径写错了');
        $this->assertSame([], array_values(array_unique($missing)), implode("\n", array_unique($missing)));
    }

    /** @return list<class-string> */
    private function classes(): array
    {
        $classes = [];
        foreach (self::DIRECTORIES as $directory) {
            foreach (\Illuminate\Support\Facades\File::files(app_path($directory)) as $fileInfo) {
                $path = str_replace('\\', '/', $fileInfo->getPathname());
                $path = str_replace(str_replace('\\', '/', app_path()).'/', '', $path);
                $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $path);
                if (class_exists($class)) {
                    $classes[] = $class;
                }
            }
        }

        return $classes;
    }

    /** @return list<string> */
    private function typeNames(?\ReflectionType $type): array
    {
        if ($type === null) {
            return [];
        }
        if ($type instanceof \ReflectionNamedType) {
            return [$type->getName()];
        }
        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            $names = [];
            foreach ($type->getTypes() as $inner) {
                $names = array_merge($names, $this->typeNames($inner));
            }

            return $names;
        }

        return [];
    }

    private function isBuiltin(string $name): bool
    {
        return in_array(strtolower($name), [
            'int', 'float', 'string', 'bool', 'array', 'mixed', 'void', 'null', 'object',
            'callable', 'iterable', 'self', 'static', 'false', 'true', 'never',
        ], true);
    }
}
