<?php

namespace A5sys\DoctrineTraitBundle\Command;

use A5sys\DoctrineTraitBundle\Generator\EntityGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generate entity classes in a trait from mapping information
 *
 */
class EntitiesCommand extends Command
{
    private $entityGenerator;

    public function __construct(EntityGenerator $entityGenerator)
    {
        $this->entityGenerator = $entityGenerator;
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('generate:doctrine:traits')
            ->setAliases(array('generate:doctrine:traits'))
            ->addArgument('name', InputArgument::REQUIRED, 'A namespace')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $name = strtr($input->getArgument('name'), '/', '\\');;
        $this->entityGenerator->writeEntityClass($name);
    }
}
