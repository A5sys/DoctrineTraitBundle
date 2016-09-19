<?php

namespace A5sys\DoctrineTraitBundle\Generator;

use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Tools\EntityGenerator as DoctrineEntityGenerator;

/**
 *
 */
class EntityGenerator extends DoctrineEntityGenerator
{
    protected $validatorService;
    protected static $pathPrefix = 'Traits';

    /**
     * @var string
     */
    protected static $classTemplate = '<?php

<namespace>

<entityAnnotation>
<entityClassName>
{
<entityBody>
}
';

    /**
     * @var string
     */
    protected static $constructorMethodTemplate =
'/**
 * Constructor
 */
public function __construct()
{
    $this->doctrineConstruct();
}
';

    /**
     * @var string
     */
    protected static $doctrineConstructorMethodTemplate =
'/**
 * Doctrine Constructor
 */
public function doctrineConstruct()
{
<spaces><collections>
}
';

    /**
     *
     */
    public function __construct()
    {
        parent::__construct();
        parent::$classTemplate = static::$classTemplate;
    }

    /**
     * Generates and writes entity class to disk for the given ClassMetadataInfo instance.
     *
     * @param ClassMetadataInfo $metadata
     * @param string            $outputDirectory
     *
     * @return void
     *
     * @throws \RuntimeException
     */
    public function writeEntityClass(ClassMetadataInfo $metadata, $outputDirectory)
    {
        $path = $outputDirectory.'/'.str_replace('\\', DIRECTORY_SEPARATOR, $metadata->name).$this->extension;
        $this->isNew = !file_exists($path) || (file_exists($path) && $this->regenerateEntityIfExists);

        if (!$this->isNew) {
            $this->parseTokensInEntityFile(file_get_contents($path));
        } else {
            $this->staticReflection[$metadata->name] = array('properties' => array(), 'methods' => array());
        }

        $traitPath = $outputDirectory.'/'.str_replace('\\', DIRECTORY_SEPARATOR, $metadata->name).'Trait'.$this->extension;
        $traitPath = str_replace('/Entity/', '/Entity/'.static::$pathPrefix.'/', $traitPath);

        $dir = dirname($traitPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        //remove the trailing spaces and tabs
        $pattern = '/[ ]*\n/';

        $replacement = "\n";
        $cleanedContent = preg_replace($pattern, $replacement, $this->generateEntityClass($metadata));
        file_put_contents($traitPath, $cleanedContent);

        chmod($traitPath, 0664);
    }

    /**
     *
     * @param type $validatorService
     */
    public function setValidatorService($validatorService)
    {
        $this->validatorService = $validatorService;
    }

    /**
     * @param ClassMetadataInfo $metadata
     *
     * @return string
     */
    protected function generateEntityNamespace(ClassMetadataInfo $metadata)
    {
        if ($this->hasNamespace($metadata)) {
            $namespace = 'namespace '.str_replace('\\Entity\\', '\\Entity\\'.static::$pathPrefix.'\\', $this->getNamespace($metadata)).';';
            $namespace = str_replace('\\Entity;', '\\Entity\\'.static::$pathPrefix.';', $namespace);

            return $namespace;
        }
    }

    /**
     * @param ClassMetadataInfo $metadata
     *
     * @return string
     */
    protected function generateEntityClassName(ClassMetadataInfo $metadata)
    {
        return 'trait '.$this->getClassName($metadata).'Trait';
    }

    /**
     * @param string            $method
     * @param ClassMetadataInfo $metadata
     *
     * @return bool
     */
    protected function hasMethod($method, ClassMetadataInfo $metadata)
    {
        if ($this->extendsClass() || (!$this->isNew && class_exists($metadata->name))) {
            // don't generate method if it is already on the base class.
            $reflClass = new \ReflectionClass($this->getClassToExtend() ?: $metadata->name);

            //check that the generated trait is not the one having the method.
            $namespace = str_replace('\\Entity\\', '\\Entity\\'.static::$pathPrefix.'\\', $this->getNamespace($metadata));
            $namespaceReplaced = preg_replace("/\\\\Entity$/", '\\Entity\\'.static::$pathPrefix, $namespace);
            $generatedTraitPath = $namespaceReplaced.'\\'.$this->getClassName($metadata).'Trait';

            if (trait_exists($generatedTraitPath)) {
                $traitRc = new \ReflectionClass($generatedTraitPath);
                if ($traitRc->hasMethod($method)) {
                    return false;
                }
            }

            if ($reflClass->hasMethod($method)) {
                return true;
            }
        }

        return (
            isset($this->staticReflection[$metadata->name]) &&
            in_array($method, $this->staticReflection[$metadata->name]['methods'])
        );
    }

    /**
     * @param array $associationMapping
     *
     * @return bool
     */
    protected function isAssociationIsNullable($associationMapping)
    {
        $isAssociationIsNullable = parent::isAssociationIsNullable($associationMapping);

        //add the null default value for the setter when an assert NotNull is on the column
        if ($isAssociationIsNullable === false) {
            $entity = $associationMapping['sourceEntity'];
            $fieldName = $associationMapping['fieldName'];
            $entityMetadatas = $this->validatorService->getMetadataFor($entity);
            if (isset($entityMetadatas->members[$fieldName])) {
                $fieldMetadatas = $entityMetadatas->members[$fieldName];
                foreach ($fieldMetadatas as $fieldMetadata) {
                    foreach ($fieldMetadata->constraints as $constraint) {
                        if (get_class($constraint) == 'Symfony\\Component\\Validator\\Constraints\\NotNull') {
                            $isAssociationIsNullable = true;
                        }
                    }
                }
            }
        }

        return $isAssociationIsNullable;
    }

    /**
     * @param ClassMetadataInfo $metadata
     *
     * @return string
     */
    protected function generateEntityConstructor(ClassMetadataInfo $metadata)
    {
        $content = '';

        $collections = array();

        foreach ($metadata->associationMappings as $mapping) {
            if ($mapping['type'] & ClassMetadataInfo::TO_MANY) {
                $collections[] = '$this->'.$mapping['fieldName'].' = new \Doctrine\Common\Collections\ArrayCollection();';
            }
        }

        if ($collections) {
            $content = $this->prefixCodeWithSpaces(str_replace("<collections>", implode("\n".$this->spaces, $collections), self::$doctrineConstructorMethodTemplate));
        }

        if (!$this->hasMethod('__construct', $metadata)) {
            $content .= "\n";
            $content .= $this->prefixCodeWithSpaces(self::$constructorMethodTemplate);
        }

        return $content;
    }
}
