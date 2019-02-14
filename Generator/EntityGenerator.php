<?php

namespace A5sys\DoctrineTraitBundle\Generator;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\MappingException;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
use Symfony\Bundle\MakerBundle\Doctrine\DoctrineHelper;
use Symfony\Bundle\MakerBundle\Exception\RuntimeCommandException;
use Doctrine\Common\Persistence\Mapping\MappingException as CommonMappingException;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\PropertyInfo\PropertyInfoExtractorInterface;
use Symfony\Component\PropertyInfo\Type;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Twig\Environment;
use Symfony\Component\Validator\Constraints as Assert;

class EntityGenerator
{
    /**
     * @var DoctrineHelper $doctrineHelper
     */
    private $doctrineHelper;

    /**
     * @var PropertyInfoExtractorInterface $propertyInfoExtractor
     */
    private $propertyInfoExtractor;

    /**
     * @var ValidatorInterface $validatorService
     */
    private $validatorService;

    /**
     * @var Environment $twig
     */
    private $twig;

    /**
     * @var string|null $renderedTemplate
     */
    private $renderedTemplate;

    public function __construct(
        DoctrineHelper $doctrineHelper,
        PropertyInfoExtractorInterface $propertyInfoExtractor,
        Environment $engine,
        ValidatorInterface $validatorService
    ) {
        $this->doctrineHelper = $doctrineHelper;
        $this->propertyInfoExtractor = $propertyInfoExtractor;
        $this->twig = $engine;
        $this->validatorService = $validatorService;
    }

    public function writeEntityClass(string $classOrNamespace)
    {
        try {
            $metadata = $this->doctrineHelper->getMetadata($classOrNamespace);
        } catch (MappingException | CommonMappingException $mappingException) {
            $metadata = $this->doctrineHelper->getMetadata($classOrNamespace, true);
        }

        if ($metadata instanceof ClassMetadata) {
            $metadata = [$metadata];
        } elseif (class_exists($classOrNamespace)) {
            throw new RuntimeCommandException(sprintf('Could not find Doctrine metadata for "%s". Is it mapped as an entity?', $classOrNamespace));
        } elseif (empty($metadata)) {
            throw new RuntimeCommandException(sprintf('No entities were found in the "%s" namespace.', $classOrNamespace));
        }

        /** @var ClassMetadata $classMetadata */
        foreach ($metadata as $classMetadata) {
            $this->renderedTemplate = '';
            $className = $classMetadata->name;
            $classPath = $this->getPathOfClass($className);
            $classPathTrait = str_replace('.php', 'Trait.php', $classPath);
            $shortName = $classMetadata->getReflectionClass()->getShortName();
            $namespaceName = $classMetadata->getReflectionClass()->getNamespaceName();

            $this->renderedTemplate .= $this->twig->render('@DoctrineTrait/top.html.twig', [
                'namespace' => $namespaceName,
                'traitName' => $shortName.'Trait',
            ]);

            foreach ($classMetadata->fieldMappings as $fieldName => $mapping) {
                try {
                    // throw exception if is not directly in class
                    $classMetadata->getReflectionClass()->getProperty($fieldName);
                } catch(\ReflectionException $ex) {
                    continue;
                }

                $types = $this
                    ->propertyInfoExtractor
                    ->getTypes($className, $fieldName)
                ;

                if (!$types) {
                    throw new RuntimeCommandException(sprintf('Unable to find types for %s.', $className));
                }

                $type = $types[0];

                // get the bottom template
                $getMethodName = 'get' . ucfirst($fieldName);
                if (!$this->hasMethod($className, $getMethodName)) {
                    if ($fieldName === 'id') {
                        // the getId is always nullable
                        $returnType = $this->getReturnType($type, true, $className, $fieldName);
                    } else {
                        $returnType = $this->getReturnType($type, $type->isNullable(), $className, $fieldName);
                    }

                    $this->renderedTemplate .= PHP_EOL.PHP_EOL;
                    $this->renderedTemplate .= $this->twig->render('@DoctrineTrait/field/getter.html.twig', [
                        'method' => $getMethodName,
                        'returnType' => $returnType,
                        'fieldName' => $fieldName,
                    ]);
                }

                $setMethodName = 'set' . ucfirst($fieldName);
                if (!$this->hasMethod($className, $setMethodName)) {
                    $returnType = $this->getReturnType($type, $type->isNullable(), $className, $fieldName);
                    $this->renderedTemplate .= PHP_EOL.PHP_EOL;
                    $this->renderedTemplate .= $this->twig->render('@DoctrineTrait/field/setter.html.twig', [
                        'method' => $setMethodName,
                        'returnType' => $returnType,
                        'fieldName' => $fieldName,
                    ]);
                }
            }

            $getIsNullable = function (array $mapping) {
                if (!isset($mapping['joinColumns'][0]['nullable'])) {
                    // the default for relationships IS nullable
                    return true;
                }

                return $mapping['joinColumns'][0]['nullable'];
            };
            $collections = [];

            foreach ($classMetadata->associationMappings as $fieldName => $mapping) {
                try {
                    // throw exception if is not directly in class
                    $classMetadata->getReflectionClass()->getProperty($fieldName);
                } catch(\ReflectionException $ex) {
                    continue;
                }

                switch ($mapping['type']) {
                    case ClassMetadata::ONE_TO_ONE:
                        $isNullable = $getIsNullable($mapping);
                        if ($isNullable === false && $this->isFieldWithAssertNotNull($className, $fieldName)) {
                            $isNullable = true;
                        }
                        $nullableLabel = '';
                        if ($isNullable) {
                            $nullableLabel = '?';
                        }
                        $fieldName = $mapping['fieldName'];
                        $reflexion = new \ReflectionClass($mapping['targetEntity']);
                        $shortName = $reflexion->getShortName();
                        $shortName = $classMetadata->getReflectionClass()->getShortName();
                        $this->renderRelationOneTemplate($className, $mapping, $fieldName, $nullableLabel);
                        break;
                    case ClassMetadata::MANY_TO_ONE:
                        $isNullable = $getIsNullable($mapping);

                        if ($isNullable === false && $this->isFieldWithAssertNotNull($className, $fieldName)) {
                            $isNullable = true;
                        }
                        $nullableLabel = '';
                        if ($isNullable) {
                            $nullableLabel = '?';
                        }
                        $fieldName = $mapping['fieldName'];
                        $reflexion = new \ReflectionClass($mapping['targetEntity']);
                        $shortName = $reflexion->getShortName();
                        $shortName = $classMetadata->getReflectionClass()->getShortName();
                        $this->renderRelationOneTemplate($className, $mapping, $fieldName, $nullableLabel);
                        break;
                    case ClassMetadata::MANY_TO_MANY:
                        $collections[] = $fieldName;
                        if ($mapping['isOwningSide']) {
                            $mappedBy = $mapping['inversedBy'];
                        } else {
                            $mappedBy = $mapping['mappedBy'];
                        }

                        $fieldName = $mapping['fieldName'];
                        $reflexion = new \ReflectionClass($mapping['targetEntity']);
                        $shortName = $reflexion->getShortName();
                        $shortName = $classMetadata->getReflectionClass()->getShortName();
                        $this->renderRelationManyToManyTemplate($className, $mapping, $fieldName, $mappedBy);
                        break;
                    case ClassMetadata::ONE_TO_MANY:
                        $collections[] = $fieldName;
                        $mappedBy = $mapping['mappedBy'];
                        $fieldName = $mapping['fieldName'];
                        $reflexion = new \ReflectionClass($mapping['targetEntity']);
                        $shortName = $reflexion->getShortName();
                        $shortName = $classMetadata->getReflectionClass()->getShortName();

                        $this->renderRelationOneToManyTemplate($className, $mapping, $fieldName, $mappedBy);
                        break;
                    default:
                        throw new \Exception('Unknown association type.');
                }
            }

            $hasParent = ($classMetadata->getReflectionClass()->getParentClass() !== false);

            if (!$this->hasMethod($className, 'doctrineConstruct')) {
                $this->renderedTemplate .= PHP_EOL.PHP_EOL;
                $this->renderedTemplate .= $this->twig->render('@DoctrineTrait/doctrineConstruct.html.twig', [
                    'collections' => $collections,
                    'hasParent' => $hasParent,
                ]);
            }

            if (!$this->hasMethod($className, '__construct')) {
                $this->renderedTemplate .= PHP_EOL.PHP_EOL;
                $this->renderedTemplate .= $this->twig->render('@DoctrineTrait/construct.html.twig', [
                    'hasParent' => $hasParent,
                ]);
            }

            $this->renderedTemplate.= PHP_EOL;
            $this->renderedTemplate .= $this->twig->render('@DoctrineTrait/bottom.html.twig', [
                'namespace' => $namespaceName,
                'traitName' => $shortName.'Trait',
            ]);

            file_put_contents($classPathTrait, $this->renderedTemplate);
            chmod($classPathTrait, 0664);
        }
    }

    private function renderRelationManyToManyTemplate($className, $mapping, $fieldName, $mappedBy): void
    {
        $methodName = 'get' . ucfirst($fieldName);
        if (!$this->hasMethod($className, $methodName)) {
            $this->renderedTemplate .= PHP_EOL.PHP_EOL;
            $this->renderedTemplate .= $this->twig->render('@DoctrineTrait/association/manyToMany/get.html.twig', [
                'fieldname' => $fieldName,
                'targetEntity' => '\\' . $mapping['targetEntity'],
            ]);
        }

        $mappedBySingular = substr($mappedBy, 0 , -1);
        $fieldNameSingular = substr($fieldName, 0 , -1);
        $methodName = 'add' . ucfirst($fieldNameSingular);
        if (!$this->hasMethod($className, $methodName)) {
            $this->renderedTemplate .= PHP_EOL.PHP_EOL;
            $this->renderedTemplate .= $this->twig->render('@DoctrineTrait/association/manyToMany/add.html.twig', [
                'fieldname' => $fieldName,
                'targetEntity' => '\\' . $mapping['targetEntity'],
                'fieldNameSingular' => $fieldNameSingular,
                'mappedBy' => $mappedBy,
                'mappedBySingular' => $mappedBySingular,
            ]);
        }

        $methodName = 'remove' . ucfirst($fieldNameSingular);
        if (!$this->hasMethod($className, $methodName)) {
            $this->renderedTemplate .= PHP_EOL.PHP_EOL;
            $this->renderedTemplate .= $this->twig->render('@DoctrineTrait/association/manyToMany/remove.html.twig', [
                'fieldname' => $fieldName,
                'targetEntity' => '\\' . $mapping['targetEntity'],
                'fieldNameSingular' => $fieldNameSingular,
                'mappedBy' => $mappedBy,
            ]);
        }
    }

    private function renderRelationOneToManyTemplate($className, $mapping, $fieldName, $mappedBy): void
    {
        $methodName = 'get' . ucfirst($fieldName);
        if (!$this->hasMethod($className, $methodName)) {
            $this->renderedTemplate .= PHP_EOL.PHP_EOL;
            $this->renderedTemplate .= $this->twig->render('@DoctrineTrait/association/oneToMany/get.html.twig', [
                'fieldname' => $fieldName,
                'targetEntity' => '\\' . $mapping['targetEntity'],
            ]);
        }

        $fieldNameSingular = substr($fieldName, 0 , -1);
        $methodName = 'add' . ucfirst($fieldNameSingular);
        if (!$this->hasMethod($className, $methodName)) {
            $this->renderedTemplate .= PHP_EOL.PHP_EOL;
            $this->renderedTemplate .= $this->twig->render('@DoctrineTrait/association/oneToMany/add.html.twig', [
                'fieldname' => $fieldName,
                'targetEntity' => '\\' . $mapping['targetEntity'],
                'fieldNameSingular' => $fieldNameSingular,
                'mappedBy' => $mappedBy,
            ]);
        }

        $methodName = 'remove' . ucfirst($fieldNameSingular);
        if (!$this->hasMethod($className, $methodName)) {
            $this->renderedTemplate .= PHP_EOL.PHP_EOL;
            $this->renderedTemplate .= $this->twig->render('@DoctrineTrait/association/oneToMany/remove.html.twig', [
                'fieldname' => $fieldName,
                'targetEntity' => '\\' . $mapping['targetEntity'],
                'fieldNameSingular' => $fieldNameSingular,
                'mappedBy' => $mappedBy,
            ]);
        }
    }

    private function renderRelationOneTemplate($className, $mapping, $fieldName, $nullableLabel): void
    {
        $methodName = 'get' . ucfirst($fieldName);
        if (!$this->hasMethod($className, $methodName)) {
            $this->renderedTemplate .= PHP_EOL.PHP_EOL;
            $this->renderedTemplate .= $this->twig->render('@DoctrineTrait/association/manyToOne/get.html.twig', [
                'fieldname' => $fieldName,
                'targetEntity' => '\\'.$mapping['targetEntity'],
                'nullableLabel' => $nullableLabel,
            ]);
        }

        $methodName = 'set' . ucfirst($fieldName);
        if (!$this->hasMethod($className, $methodName)) {
            $this->renderedTemplate .= PHP_EOL.PHP_EOL;
            $this->renderedTemplate .= $this->twig->render('@DoctrineTrait/association/manyToOne/set.html.twig', [
                'fieldname' => $fieldName,
                'targetEntity' => '\\'.$mapping['targetEntity'],
                'nullableLabel' => $nullableLabel,
            ]);
        }
    }

    private function getPathOfClass(string $class): string
    {
        return (new \ReflectionClass($class))->getFileName();
    }

    private function hasMethod($className, $method)
    {
        // don't generate method if it is already on the base class.
        $traitName = $className.'Trait';

        if (trait_exists($traitName)) {
            $traitRc = new \ReflectionClass($traitName);
            if ($traitRc->hasMethod($method)) {
                return false;
            }
        }

        $reflClass = new \ReflectionClass($className);
        if ($reflClass->hasMethod($method)) {
            return true;
        }

        return false;
    }

    private function getReturnType(Type $type, bool $nullable, string $className, string $fieldName): string
    {
        $returnType = '';
        if ($nullable) {
            $returnType = '?';
        } else {
            if ($this->isFieldWithAssertNotNull($className, $fieldName)) {
                throw new RuntimeCommandException(
                    sprintf(
                        'The property "$%s" in "%s" is not nullable but has an assert not null/blank.',
                        $fieldName,
                        $className
                    )
                );
            }
        }

        $returnType .= $type->getClassName()
            ? '\\'.$type->getClassName()
            : $type->getBuiltinType()
        ;

        return $returnType;
    }

    private function isFieldWithAssertNotNull(string $className, string $fieldName)
    {
        $entityMetadatas = $this->validatorService->getMetadataFor($className);
        if (isset($entityMetadatas->members[$fieldName])) {
            $fieldMetadatas = $entityMetadatas->members[$fieldName];
            $nullableAssertions = [
                Assert\NotNull::class,
                Assert\NotBlank::class,
            ];

            foreach ($fieldMetadatas as $fieldMetadata) {
                foreach ($fieldMetadata->constraints as $constraint) {
                    if (in_array(get_class($constraint), $nullableAssertions, true)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
