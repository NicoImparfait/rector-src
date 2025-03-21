<?php

declare(strict_types=1);

namespace Rector\DeadCode\Rector\Property;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Property;
use PHPStan\Analyser\Scope;
use Rector\Core\Contract\Rector\AllowEmptyConfigurableRectorInterface;
use Rector\Core\NodeManipulator\PropertyManipulator;
use Rector\Core\Rector\AbstractScopeAwareRector;
use Rector\Removing\NodeManipulator\ComplexNodeRemover;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\ConfiguredCodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * @see \Rector\Tests\DeadCode\Rector\Property\RemoveUnusedPrivatePropertyRector\RemoveUnusedPrivatePropertyRectorTest
 */
final class RemoveUnusedPrivatePropertyRector extends AbstractScopeAwareRector implements AllowEmptyConfigurableRectorInterface
{
    /**
     * @api
     * @var string
     */
    public const REMOVE_ASSIGN_SIDE_EFFECT = 'remove_assign_side_effect';

    /**
     * Default to true, which apply remove assign even has side effect.
     * Set to false will allow to skip when assign has side effect.
     */
    private bool $removeAssignSideEffect = true;

    public function __construct(
        private readonly PropertyManipulator $propertyManipulator,
        private readonly ComplexNodeRemover $complexNodeRemover,
    ) {
    }

    /**
     * @param mixed[] $configuration
     */
    public function configure(array $configuration): void
    {
        $this->removeAssignSideEffect = $configuration[self::REMOVE_ASSIGN_SIDE_EFFECT] ?? (bool) current(
            $configuration
        );
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Remove unused private properties', [
            new ConfiguredCodeSample(
                <<<'CODE_SAMPLE'
class SomeClass
{
    private $property;
}
CODE_SAMPLE
                ,
                <<<'CODE_SAMPLE'
class SomeClass
{
}
CODE_SAMPLE
                ,
                [
                    self::REMOVE_ASSIGN_SIDE_EFFECT => true,
                ]
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
        $hasChanged = false;

        foreach ($node->stmts as $key => $stmt) {
            if (! $stmt instanceof Property) {
                continue;
            }

            if ($this->shouldSkipProperty($stmt)) {
                continue;
            }

            if ($this->propertyManipulator->isPropertyUsedInReadContext($node, $stmt, $scope)) {
                continue;
            }

            // use different variable to avoid re-assign back $hasRemoved to false
            // when already asssigned to true
            $isRemoved = $this->complexNodeRemover->removePropertyAndUsages(
                $node,
                $stmt,
                $this->removeAssignSideEffect,
                $scope,
                $key
            );

            if ($isRemoved) {
                $hasChanged = true;
            }
        }

        return $hasChanged ? $node : null;
    }

    private function shouldSkipProperty(Property $property): bool
    {
        if (count($property->props) !== 1) {
            return true;
        }

        return ! $property->isPrivate();
    }
}
