<?php

namespace A5sys\DoctrineTraitBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class DoctrineTraitExtension extends AbstractExtension
{
    public function getFilters()
    {
        return array(
            new TwigFilter('ucfirst', array($this, 'ucfirst')),
        );
    }

    public function ucfirst(string $label): string
    {
        return \ucfirst($label);
    }
}
