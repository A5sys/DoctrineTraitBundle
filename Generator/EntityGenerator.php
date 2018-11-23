<?php

namespace A5sys\DoctrineTraitBundle\Generator;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\MappingException;
use Symfony\Bundle\MakerBundle\Doctrine\DoctrineHelper;
use Symfony\Bundle\MakerBundle\Exception\RuntimeCommandException;
use Doctrine\Common\Persistence\Mapping\MappingException as CommonMappingException;

class EntityGenerator
{
    private $renderedTemplate;
    private $twig;
    private $validatorService;

    /** @var DoctrineHelper */
    private $doctrineHelper;

    public function __construct(DoctrineHelper $doctrineHelper, $kernel, $validatorService)
    {
        $this->doctrineHelper = $doctrineHelper;
        $this->kernel = $kernel;
        $this->validatorService = $validatorService;
    }

    public function writeEntityClass(string $classOrNamespace)
    {
        $this->twig = $this->kernel->getContainer()->get('twig');

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
                    $doctrineType = $mapping['type'];
                    $doctrineNullable = $mapping['nullable'];

                    //get the bottom template
                    $getMethodName = 'get' . ucfirst($fieldName);
                    if (!$this->hasMethod($className, $getMethodName)) {
                        if ($fieldName === 'id') {
                            // the getId is always nullable
                            $returnType = $this->getReturnType($doctrineType, true, $className, $fieldName);
                        } else {
                            $returnType = $this->getReturnType($doctrineType, $doctrineNullable, $className, $fieldName);
                        }

                        $this->renderedTemplate .= "\n\n";
                        $this->renderedTemplate .= $this->twig->render('@DoctrineTrait/field/getter.html.twig', [
                            'method' => $getMethodName,
                            'returnType' => $returnType,
                            'fieldName' => $fieldName,
                        ]);
                    }

                    $setMethodName = 'set' . ucfirst($fieldName);
                    if (!$this->hasMethod($className, $setMethodName)) {
                        $returnType = $this->getReturnType($doctrineType, $doctrineNullable, $className, $fieldName);
                        $this->renderedTemplate .= "\n\n";
                        $this->renderedTemplate .= $this->twig->render('@DoctrineTrait/field/setter.html.twig', [
                            'method' => $setMethodName,
                            'returnType' => $returnType,
                            'fieldName' => $fieldName,
                            'returnType' => $returnType,
                        ]);
                    }
                }
                catch(\ReflectionException $ex) {
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
                catch(\ReflectionException $ex) {
                }
            }

            $hasParent = ($classMetadata->getReflectionClass()->getParentClass() !== false);

            if (!$this->hasMethod($className, 'doctrineConstruct')) {
                $this->renderedTemplate .= "\n\n";
                $this->renderedTemplate .= $this->twig->render('@DoctrineTrait/doctrineConstruct.html.twig', [
                    'collections' => $collections,
                    'hasParent' => $hasParent,
                ]);
            }

            if (!$this->hasMethod($className, '__construct')) {
                $this->renderedTemplate .= "\n\n";
                $this->renderedTemplate .= $this->twig->render('@DoctrineTrait/construct.html.twig', [
                    'hasParent' => $hasParent,
                ]);
            }

            $this->renderedTemplate.= "\n";
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
            $this->renderedTemplate .= "\n\n";
            $this->renderedTemplate .= $this->twig->render('@DoctrineTrait/association/manyToMany/get.html.twig', [
                'fieldname' => $fieldName,
                'targetEntity' => '\\' . $mapping['targetEntity'],
            ]);
        }

        $mappedBySingular = substr($mappedBy, 0 , -1);
        $fieldNameSingular = substr($fieldName, 0 , -1);
        $methodName = 'add' . ucfirst($fieldNameSingular);
        if (!$this->hasMethod($className, $methodName)) {
            $this->renderedTemplate .= "\n\n";
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
            $this->renderedTemplate .= "\n\n";
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
            $this->renderedTemplate .= "\n\n";
            $this->renderedTemplate .= $this->twig->render('@DoctrineTrait/association/oneToMany/get.html.twig', [
                'fieldname' => $fieldName,
                'targetEntity' => '\\' . $mapping['targetEntity'],
            ]);
        }

        $fieldNameSingular = substr($fieldName, 0 , -1);
        $methodName = 'add' . ucfirst($fieldNameSingular);
        if (!$this->hasMethod($className, $methodName)) {
            $this->renderedTemplate .= "\n\n";
            $this->renderedTemplate .= $this->twig->render('@DoctrineTrait/association/oneToMany/add.html.twig', [
                'fieldname' => $fieldName,
                'targetEntity' => '\\' . $mapping['targetEntity'],
                'fieldNameSingular' => $fieldNameSingular,
                'mappedBy' => $mappedBy,
            ]);
        }

        $methodName = 'remove' . ucfirst($fieldNameSingular);
        if (!$this->hasMethod($className, $methodName)) {
            $this->renderedTemplate .= "\n\n";
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
            $this->renderedTemplate .= "\n\n";
            $this->renderedTemplate .= $this->twig->render('@DoctrineTrait/association/manyToOne/get.html.twig', [
                'fieldname' => $fieldName,
                'targetEntity' => '\\'.$mapping['targetEntity'],
                'nullableLabel' => $nullableLabel,
            ]);
        }

        $methodName = 'set' . ucfirst($fieldName);
        if (!$this->hasMethod($className, $methodName)) {
            $this->renderedTemplate .= "\n\n";
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
        $reflClass = new \ReflectionClass($className);

        if (trait_exists($className.'Trait')) {
            $traitRc = new \ReflectionClass($className.'Trait');
            if ($traitRc->hasMethod($method)) {
                return false;
            }
        }

        if ($reflClass->hasMethod($method)) {
            return true;
        }

        return false;
    }

    private function isFieldWithAssertNotNull(string $className, string $fieldName)
    {
        $entityMetadatas = $this->validatorService->getMetadataFor($className);
        if (isset($entityMetadatas->members[$fieldName])) {
            $fieldMetadatas = $entityMetadatas->members[$fieldName];
            foreach ($fieldMetadatas as $fieldMetadata) {
                foreach ($fieldMetadata->constraints as $constraint) {
                    if (get_class($constraint) == 'Symfony\\Component\\Validator\\Constraints\\NotNull') {
                        return true;
                    }
                    if (get_class($constraint) == 'Symfony\\Component\\Validator\\Constraints\\NotBlank') {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function getEntityTypeHint($doctrineType)
    {
        switch ($doctrineType) {
            case 'string':
            case 'text':
            case 'guid':
                return 'string';

            case 'array':
            case 'simple_array':
            case 'json':
                return 'array';

            case 'boolean':
                return 'bool';

            case 'integer':
            case 'smallint':
            case 'bigint':
                return 'int';

            case 'float':
                return 'float';

            case 'datetime':
            case 'datetimetz':
            case 'date':
            case 'time':
                return '\\'.\DateTimeInterface::class;

            case 'datetime_immutable':
            case 'datetimetz_immutable':
            case 'date_immutable':
            case 'time_immutable':
                return '\\'.\DateTimeImmutable::class;

            case 'dateinterval':
                return '\\'.\DateInterval::class;

            case 'object':
            case 'decimal':
            case 'binary':
            case 'blob':
            default:
                return null;
        }
    }

    private function getReturnType(string $doctrineType, bool $doctrineNullable, string $className, string $fieldName): string
    {
        $returnType = null;
        if ($doctrineType) {
            $returnType = '';
            if ($doctrineNullable) {
                $returnType = '?';
            } else {
                if ($this->isFieldWithAssertNotNull($className, $fieldName)) {
                    $returnType = '?';
                }
            }

            $returnType .= $this->getEntityTypeHint($doctrineType);
        }

        return $returnType;
    }
}
