<?php

declare(strict_types=1);

namespace Phan\Plugin\Internal;

use ast\Node;
use Closure;
use Phan\AST\UnionTypeVisitor;
use Phan\CodeBase;
use Phan\Config;
use Phan\Language\Context;
use Phan\Language\Element\Func;
use Phan\Language\Element\FunctionInterface;
use Phan\Language\Type;
use Phan\Language\Type\CallableInterface;
use Phan\Language\Type\ClassStringType;
use Phan\Language\Type\ClosureType;
use Phan\Plugin\ConfigPluginSet;
use Phan\PluginV3;
use Phan\PluginV3\AnalyzeFunctionCallCapability;
use Phan\PluginV3\HandleLazyLoadInternalFunctionCapability;

use function count;

/**
 * NOTE: This is automatically loaded by phan. Do not include it in a config.
 *
 * TODO: Analyze returning callables (function() : callable) for any callables that are returned as literals?
 * This would be difficult.
 */
final class CallableParamPlugin extends PluginV3 implements
    AnalyzeFunctionCallCapability,
    HandleLazyLoadInternalFunctionCapability
{

    public const PARAM_HAS_CALLABLE = (1 << 0);
    public const PARAM_HAS_CLASSSTRING = (1 << 1);

    /**
     * @param array<int,int> $params
     * @phan-return Closure(CodeBase,Context,FunctionInterface,array,?Node):void
     */
    private static function generateClosure(array $params): Closure
    {
        $key = \json_encode($params);
        static $cache = [];
        $closure = $cache[$key] ?? null;
        if ($closure !== null) {
            return $closure;
        }
        /**
         * @param list<Node|int|float|string> $args
         */
        $closure = static function (CodeBase $code_base, Context $context, FunctionInterface $function, array $args, ?Node $_) use ($params): void {
            // TODO: Implement support for variadic callable arguments.
            foreach ($params as $i => $flags) {
                $arg = $args[$i] ?? null;
                $param = $function->getParameterForCaller($i);
                if ($arg === null || $param === null) {
                    continue;
                }

                $references = [];

                if ($flags & self::PARAM_HAS_CALLABLE) {
                    // Fetch possible functions for the provided callable argument.
                    $function_like_list = UnionTypeVisitor::functionLikeListFromNodeAndContext($code_base, $context, $arg, false);
                    if ($function_like_list) {
                        $references[] = $function_like_list;
                    }
                }

                if ($flags & self::PARAM_HAS_CLASSSTRING) {
                    // Fetch possible classes.
                    $class_list = UnionTypeVisitor::classListFromClassNameNode($code_base, $context, $arg, false);
                    if ($class_list) {
                        $references[] = $class_list;
                    }
                }

                if ($references && Config::get_track_references()) {
                    foreach ($references as $reference_list) {
                        foreach ($reference_list as $addressable) {
                            $addressable->addReference($context);
                        }
                    }
                }

                if (!$references) {
                    // It's not a valid callable and/or class-string, but before we emit issues,
                    // first check if we're dealing with a union type where some other types are valid.
                    $other_param_types = $param->getUnionType()->eraseRealTypeSet()->makeFromFilter(static function (Type $type) use ($flags): bool {
                        if ($type instanceof CallableInterface && ($flags & self::PARAM_HAS_CALLABLE)) {
                            return false;
                        }
                        if ($type instanceof ClassStringType && ($flags & self::PARAM_HAS_CLASSSTRING)) {
                            return false;
                        }
                        return true;
                    });
                    $valid_as_other_types = !$other_param_types->isEmpty() &&
                        UnionTypeVisitor::unionTypeFromNode($code_base, $context, $arg)
                            ->canCastToUnionType($other_param_types, $code_base);
                    if (!$valid_as_other_types) {
                        // Do it again, emitting issues this time.
                        if ($flags & self::PARAM_HAS_CALLABLE) {
                            UnionTypeVisitor::functionLikeListFromNodeAndContext($code_base, $context, $arg, true);
                        }
                        if ($flags & self::PARAM_HAS_CLASSSTRING) {
                            UnionTypeVisitor::classListFromClassNameNode($code_base, $context, $arg, true);
                        }
                    }
                }
            }
        };

        $cache[$key] = $closure;
        return $closure;
    }

    /**
     * @return ?Closure(CodeBase,Context,FunctionInterface,array,?Node):void
     */
    private static function generateClosureForFunctionInterface(FunctionInterface $function): ?Closure
    {
        $params = [];
        foreach ($function->getParameterList() as $i => $param) {
            $params[$i] = 0;
            // If there's a type such as Closure|string|int, don't automatically assume that any string or array passed in is meant to be a callable.
            // Explicitly require at least one type to be `callable`
            if ($param->getUnionType()->hasTypeMatchingCallback(static function (Type $type): bool {
                // TODO: More specific closure for CallableDeclarationType
                // TODO: Use `Type::isCallable`? It might be slower though.
                return $type instanceof CallableInterface || $type instanceof ClosureType;
            })) {
                $params[$i] |= self::PARAM_HAS_CALLABLE;
            }
            if ($param->getUnionType()->hasTypeMatchingCallback(static function (Type $type): bool {
                return $type instanceof ClassStringType;
            })) {
                $params[$i] |= self::PARAM_HAS_CLASSSTRING;
            }
        }

        $params = array_filter($params);
        if (count($params) === 0) {
            return null;
        }
        // Generate a de-duplicated closure.
        // fqsen can be global_function or ClassName::method
        return self::generateClosure($params);
    }

    /**
     * @return array<string,Closure(CodeBase,Context,FunctionInterface,array,?Node):void>
     */
    private static function getAnalyzeFunctionCallClosuresStatic(CodeBase $code_base): array
    {
        $result = [];
        $add_callable_checker_closure = static function (FunctionInterface $function) use (&$result): void {
            // Generate a de-duplicated closure.
            // fqsen can be global_function or ClassName::method
            $closure = self::generateClosureForFunctionInterface($function);
            if ($closure) {
                $result[$function->getFQSEN()->__toString()] = $closure;
            }
        };

        $add_another_closure = static function (string $fqsen, Closure $closure) use (&$result): void {
            $result[$fqsen] = ConfigPluginSet::mergeAnalyzeFunctionCallClosures(
                $closure,
                $result[$fqsen] ?? null
            );
        };

        $add_misc_closures = static function (FunctionInterface $function) use ($add_callable_checker_closure, $add_another_closure, $code_base): void {
            $add_callable_checker_closure($function);
            // @phan-suppress-next-line PhanAccessMethodInternal
            $closure = $function->getCommentParamAssertionClosure($code_base);
            if ($closure) {
                $add_another_closure($function->getFQSEN()->__toString(), $closure);
            }
        };

        foreach ($code_base->getFunctionMap() as $function) {
            $add_misc_closures($function);
        }
        foreach ($code_base->getMethodSet() as $function) {
            $add_misc_closures($function);
        }

        // new ReflectionFunction('my_func') is a usage of my_func()
        // See https://github.com/phan/phan/issues/1204 for note on function_exists() (not supported right now)
        $result['\\ReflectionFunction::__construct'] = self::generateClosure([0 => self::PARAM_HAS_CALLABLE]);
        $result['\\ReflectionClass::__construct'] = self::generateClosure([0 => self::PARAM_HAS_CLASSSTRING]);

        // Don't do redundant work extracting function definitions for commonly invoked functions.
        // TODO: Get actual statistics on how frequently used these are
        unset($result['\\call_user_func']);
        unset($result['\\call_user_func_array']);
        unset($result['\\array_map']);
        unset($result['\\array_filter']);
        // End of commonly used functions.

        return $result;
    }

    /**
     * When a function is loaded into the CodeBase for the first time during analysis
     * (e.g. `register_shutdown_function()`, this is called to conditionally add any checkers for callable/closure.
     * @unused-param $code_base
     */
    public function handleLazyLoadInternalFunction(
        CodeBase $code_base,
        Func $function
    ): void {
        $closure = self::generateClosureForFunctionInterface($function);
        if ($closure) {
            $function->addFunctionCallAnalyzer($closure, $this);
        }
    }

    /**
     * @return array<string,Closure(CodeBase,Context,FunctionInterface,array,?Node):void>
     * @override
     */
    public function getAnalyzeFunctionCallClosures(CodeBase $code_base): array
    {
        // Cannot cache this as it depends on the CodeBase.
        return self::getAnalyzeFunctionCallClosuresStatic($code_base);
    }
}
