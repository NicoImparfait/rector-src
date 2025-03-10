<?php

declare(strict_types=1);

namespace Rector\Naming\Rector\Foreach_;

use PhpParser\Node;
use PhpParser\Node\Stmt\Foreach_;
use Rector\Core\Rector\AbstractRector;
use Rector\Naming\Guard\BreakingVariableRenameGuard;
use Rector\Naming\Matcher\ForeachMatcher;
use Rector\Naming\Naming\ExpectedNameResolver;
use Rector\Naming\NamingConvention\NamingConventionAnalyzer;
use Rector\Naming\ValueObject\VariableAndCallForeach;
use Rector\Naming\VariableRenamer;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * @see \Rector\Tests\Naming\Rector\Foreach_\RenameForeachValueVariableToMatchMethodCallReturnTypeRector\RenameForeachValueVariableToMatchMethodCallReturnTypeRectorTest
 */
final class RenameForeachValueVariableToMatchMethodCallReturnTypeRector extends AbstractRector
{
    public function __construct(
        private readonly BreakingVariableRenameGuard $breakingVariableRenameGuard,
        private readonly ExpectedNameResolver $expectedNameResolver,
        private readonly NamingConventionAnalyzer $namingConventionAnalyzer,
        private readonly VariableRenamer $variableRenamer,
        private readonly ForeachMatcher $foreachMatcher
    ) {
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Renames value variable name in foreach loop to match method type',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
class SomeClass
{
    public function run()
    {
        $array = [];
        foreach ($object->getMethods() as $property) {
            $array[] = $property;
        }
    }
}
CODE_SAMPLE

                    ,
                    <<<'CODE_SAMPLE'
class SomeClass
{
    public function run()
    {
        $array = [];
        foreach ($object->getMethods() as $method) {
            $array[] = $method;
        }
    }
}
CODE_SAMPLE
                ),
            ]
        );
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [Foreach_::class];
    }

    /**
     * @param Foreach_ $node
     */
    public function refactor(Node $node): ?Node
    {
        $variableAndCallForeach = $this->foreachMatcher->match($node);
        if (! $variableAndCallForeach instanceof VariableAndCallForeach) {
            return null;
        }

        $expectedName = $this->expectedNameResolver->resolveForForeach($variableAndCallForeach->getCall());
        if ($expectedName === null) {
            return null;
        }

        if ($this->isName($variableAndCallForeach->getVariable(), $expectedName)) {
            return null;
        }

        if ($this->shouldSkip($variableAndCallForeach, $expectedName)) {
            return null;
        }

        $hasRenamed = $this->variableRenamer->renameVariableInFunctionLike(
            $variableAndCallForeach->getFunctionLike(),
            $variableAndCallForeach->getVariableName(),
            $expectedName,
            null
        );

        if ($hasRenamed) {
            return $node;
        }

        return null;
    }

    private function shouldSkip(VariableAndCallForeach $variableAndCallForeach, string $expectedName): bool
    {
        if ($this->namingConventionAnalyzer->isCallMatchingVariableName(
            $variableAndCallForeach->getCall(),
            $variableAndCallForeach->getVariableName(),
            $expectedName
        )) {
            return true;
        }

        return $this->breakingVariableRenameGuard->shouldSkipVariable(
            $variableAndCallForeach->getVariableName(),
            $expectedName,
            $variableAndCallForeach->getFunctionLike(),
            $variableAndCallForeach->getVariable()
        );
    }
}
