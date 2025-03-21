<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Core\ValueObject\PhpVersionFeature;
use Rector\Php80\Rector\Class_\AnnotationToAttributeRector;
use Rector\Php80\ValueObject\AnnotationToAttribute;
use Rector\Tests\Php80\Rector\Class_\AnnotationToAttributeRector\Source\Annotation\OpenApi\Annotation\NestedPastAnnotation;
use Rector\Tests\Php80\Rector\Class_\AnnotationToAttributeRector\Source\Annotation\OpenApi\PastAnnotation;
use Rector\Tests\Php80\Rector\Class_\AnnotationToAttributeRector\Source\Attribute\OpenApi\Attribute\NestedFutureAttribute;
use Rector\Tests\Php80\Rector\Class_\AnnotationToAttributeRector\Source\Attribute\OpenApi\FutureAttribute;
use Rector\Tests\Php80\Rector\Class_\AnnotationToAttributeRector\Source\GenericAnnotation;
use Rector\Tests\Php80\Rector\Class_\AnnotationToAttributeRector\Source\GenericSingleImplicitAnnotation;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->phpVersion(PhpVersionFeature::NEW_INITIALIZERS - 1);

    $rectorConfig
        ->ruleWithConfiguration(AnnotationToAttributeRector::class, [
            new AnnotationToAttribute(PastAnnotation::class, FutureAttribute::class),
            new AnnotationToAttribute(NestedPastAnnotation::class, NestedFutureAttribute::class),

            // use always this annotation to test inner part of annotation - arguments, arrays, calls...
            new AnnotationToAttribute(GenericAnnotation::class),
            new AnnotationToAttribute(GenericSingleImplicitAnnotation::class),

            new AnnotationToAttribute('inject', 'Nette\DI\Attributes\Inject'),
            new AnnotationToAttribute('Symfony\Component\Routing\Annotation\Route'),

            // doctrine
            new AnnotationToAttribute('Doctrine\ORM\Mapping\Entity'),
            new AnnotationToAttribute('Doctrine\ORM\Mapping\ManyToMany'),
            new AnnotationToAttribute('Doctrine\ORM\Mapping\DiscriminatorMap'),

            // validation
            new AnnotationToAttribute('Symfony\Component\Validator\Constraints\All'),
            new AnnotationToAttribute('Symfony\Component\Validator\Constraints\Length'),

            // JMS + Symfony
            new AnnotationToAttribute('JMS\Serializer\Annotation\AccessType'),
            new AnnotationToAttribute('Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter'),

            // test for alias used
            new AnnotationToAttribute(
                'Rector\Tests\Php80\Rector\Class_\AnnotationToAttributeRector\Source\UseAlias\TestSmth'
            ),
            new AnnotationToAttribute(
                'Rector\Tests\Php80\Rector\Class_\AnnotationToAttributeRector\Source\UseAlias\TestOther'
            ),
            new AnnotationToAttribute('Sensio\Bundle\FrameworkExtraBundle\Configuration\Security'),
        ]);
};
