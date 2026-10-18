<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use Illuminate\Foundation\Testing\Concerns\InteractsWithConsole;
use Illuminate\Testing\PendingCommand;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use Tests\TestCase;

final class ArtisanReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    public function getClass(): string
    {
        return TestCase::class;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'artisan'
            && $methodReflection->getDeclaringClass()->hasTraitUse(InteractsWithConsole::class);
    }

    public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): ?Type
    {
        return new ObjectType(PendingCommand::class);
    }
}
