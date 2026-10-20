<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use Pest\PendingCalls\UsesCall;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Type\FunctionParameterClosureThisExtension;
use PHPStan\Type\MethodParameterClosureThisExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use Tests\TestCase;

final class PestClosureThisExtension implements FunctionParameterClosureThisExtension, MethodParameterClosureThisExtension
{
    private const array FUNCTIONS = ['test', 'it', 'beforeEach', 'afterEach'];

    public function isFunctionSupported(FunctionReflection $functionReflection, ParameterReflection $parameter): bool
    {
        return $parameter->getName() === 'closure' && in_array($functionReflection->getName(), self::FUNCTIONS, true);
    }

    public function getClosureThisTypeFromFunctionCall(FunctionReflection $functionReflection, FuncCall $functionCall, ParameterReflection $parameter, Scope $scope): Type
    {
        return new ObjectType(TestCase::class);
    }

    public function isMethodSupported(MethodReflection $methodReflection, ParameterReflection $parameter): bool
    {
        return $methodReflection->getDeclaringClass()->getName() === UsesCall::class
            && in_array($methodReflection->getName(), ['beforeEach', 'afterEach'], true);
    }

    public function getClosureThisTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, ParameterReflection $parameter, Scope $scope): Type
    {
        return new ObjectType(TestCase::class);
    }
}
