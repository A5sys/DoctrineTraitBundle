<?php

namespace A5sys\DoctrineTraitBundle\Command;

use A5sys\DoctrineTraitBundle\Generator\EntityGenerator;
use Doctrine\Bundle\DoctrineBundle\Mapping\DisconnectedMetadataFactory;
use Doctrine\Bundle\DoctrineBundle\Command\DoctrineCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generate entity classes in a trait from mapping information
 *
 */
class EntitiesCommand extends DoctrineCommand
{
    /**
     *
     */
    protected function configure()
    {
        $this
            ->setName('generate:doctrine:traits')
            ->setAliases(array('generate:doctrine:traits'))
            ->addArgument('name', InputArgument::REQUIRED, 'A bundle name, a namespace, or a class name')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'The path where to generate entities when it cannot be guessed')
            ->setHelp(<<<EOT
The <info>%command.name%</info> command generates trait method stubs from your mapping information:

You have to limit generation of entities:

* To a bundle:

  <info>php %command.full_name% MyCustomBundle</info>

* To a single entity:

  <info>php %command.full_name% MyCustomBundle:User</info>
  <info>php %command.full_name% MyCustomBundle/Entity/User</info>
EOT
            );
    }

    /**
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $manager = new DisconnectedMetadataFactory($this->getContainer()->get('doctrine'));

        try {
            $bundle = $this->getApplication()->getKernel()->getBundle($input->getArgument('name'));

            $output->writeln(sprintf('Generating entities for bundle "<info>%s</info>"', $bundle->getName()));
            $metadata = $manager->getBundleMetadata($bundle);
        } catch (\InvalidArgumentException $e) {
            $name = strtr($input->getArgument('name'), '/', '\\');

            if (false !== $pos = strpos($name, ':')) {
                $name = $this->getContainer()->get('doctrine')->getAliasNamespace(substr($name, 0, $pos)).'\\'.substr($name, $pos + 1);
            }

            if (class_exists($name)) {
                $output->writeln(sprintf('Generating entity "<info>%s</info>"', $name));
                $metadata = $manager->getClassMetadata($name, $input->getOption('path'));
            } else {
                $output->writeln(sprintf('Generating entities for namespace "<info>%s</info>"', $name));
                $metadata = $manager->getNamespaceMetadata($name, $input->getOption('path'));
            }
        }

        $generator = $this->getEntityGenerator();

        foreach ($metadata->getMetadata() as $m) {
            // Getting the metadata for the entity class once more to get the correct path if the namespace has multiple occurrences
            try {
                $entityMetadata = $manager->getClassMetadata($m->getName(), $input->getOption('path'));
            } catch (\RuntimeException $e) {
                // fall back to the bundle metadata when no entity class could be found
                $entityMetadata = $metadata;
            }

            $output->writeln(sprintf('  > generating <comment>%s</comment>', $m->name));
            $generator->generate(array($m), $entityMetadata->getPath());
        }
    }

    /**
     * get a doctrine entity generator
     *
     * @return EntityGenerator
     */
    protected function getEntityGenerator()
    {
        $entityGenerator = new EntityGenerator();
        $entityGenerator->setGenerateAnnotations(false);
        $entityGenerator->setGenerateStubMethods(true);
        $entityGenerator->setRegenerateEntityIfExists(false);
        $entityGenerator->setUpdateEntityIfExists(true);
        $entityGenerator->setNumSpaces(4);
        $entityGenerator->setAnnotationPrefix('ORM\\');
        $entityGenerator->setBackupExisting(false);
        $entityGenerator->setFieldVisibility('protected');

        return $entityGenerator;
    }
}
