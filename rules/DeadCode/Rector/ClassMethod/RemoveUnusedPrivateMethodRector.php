<?php

declare(strict_types=1);

namespace Rector\DeadCode\Rector\ClassMethod;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use Rector\Core\Rector\AbstractScopeAwareRector;
use Rector\Core\Reflection\ReflectionResolver;
use Rector\Core\ValueObject\MethodName;
use Rector\DeadCode\NodeAnalyzer\IsClassMethodUsedAnalyzer;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * @see \Rector\Tests\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodRector\RemoveUnusedPrivateMethodRectorTest
 */
final class RemoveUnusedPrivateMethodRector extends AbstractScopeAwareRector
{
    public function __construct(
        private readonly IsClassMethodUsedAnalyzer $isClassMethodUsedAnalyzer,
        private readonly ReflectionResolver $reflectionResolver
    ) {
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Remove unused private method', [
            new CodeSample(
                <<<'CODE_SAMPLE'
final class SomeController
{
    public function run()
    {
        return 5;
    }

    private function skip()
    {
        return 10;
    }
}
CODE_SAMPLE
                ,
                <<<'CODE_SAMPLE'
final class SomeController
{
    public function run()
    {
        return 5;
    }
}
CODE_SAMPLE
            ),
        ]);
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [Class_::class];
    }

    /**
     * @param Class_ $node
     */
    public function refactorWithScope(Node $node, Scope $scope): ?Node
    {
        if ($this->hasDynamicMethodCallOnFetchThis($node)) {
            return null;
        }

        $hasChanged = false;
        $classReflection = null;

        foreach ($node->getMethods() as $classMethod) {
            if (! $classReflection instanceof ClassReflection) {
                $classReflection = $this->reflectionResolver->resolveClassReflection($classMethod);
            }

            if ($this->shouldSkip($classMethod, $classReflection)) {
                continue;
            }

            if ($this->isClassMethodUsedAnalyzer->isClassMethodUsed($node, $classMethod, $scope)) {
                continue;
            }

            $this->removeNode($classMethod);
            $hasChanged = true;
        }

        if ($hasChanged) {
            return $node;
        }

        return null;
    }

    private function shouldSkip(ClassMethod $classMethod, ?ClassReflection $classReflection): bool
    {
        if (! $classReflection instanceof ClassReflection) {
            return true;
        }

        // unreliable to detect trait, interface doesn't make sense
        if ($classReflection->isTrait()) {
            return true;
        }

        if ($classReflection->isInterface()) {
            return true;
        }

        if ($classReflection->isAnonymous()) {
            return true;
        }

        // skips interfaces by default too
        if (! $classMethod->isPrivate()) {
            return true;
        }

        // skip magic methods - @see https://www.php.net/manual/en/language.oop5.magic.php
        if ($classMethod->isMagic()) {
            return true;
        }

        return $classReflection->hasMethod(MethodName::CALL);
    }

    private function hasDynamicMethodCallOnFetchThis(Class_ $class): bool
    {
        foreach ($class->getMethods() as $classMethod) {
            $isFound = (bool) $this->betterNodeFinder->findFirst(
                (array) $classMethod->getStmts(),
                function (Node $subNode): bool {
                    if (! $subNode instanceof MethodCall) {
                        return false;
                    }

                    if (! $subNode->var instanceof Variable) {
                        return false;
                    }

                    if (! $this->nodeNameResolver->isName($subNode->var, 'this')) {
                        return false;
                    }

                    return $subNode->name instanceof Variable;
                }
            );

            if ($isFound) {
                return true;
            }
        }

        return false;
    }
}
