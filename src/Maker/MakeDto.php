<?php

/*
 * This file is part of the Symfony MakerBundle package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\MakerBundle\Maker;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Bundle\MakerBundle\ConsoleStyle;
use Symfony\Bundle\MakerBundle\DependencyBuilder;
use Symfony\Bundle\MakerBundle\Doctrine\DoctrineHelper;
use Symfony\Bundle\MakerBundle\Exception\RuntimeCommandException;
use Symfony\Bundle\MakerBundle\FileManager;
use Symfony\Bundle\MakerBundle\Generator;
use Symfony\Bundle\MakerBundle\InputConfiguration;
use Symfony\Bundle\MakerBundle\Str;
use Symfony\Bundle\MakerBundle\Util\DTOClassSourceManipulator;
use Symfony\Bundle\MakerBundle\Validator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Validation;

/**
 * @author Javier Eguiluz <javier.eguiluz@gmail.com>
 * @author Ryan Weaver <weaverryan@gmail.com>
 * @author Clemens Krack <info@clemenskrack.com>
 */
final class MakeDto extends AbstractMaker
{
    private $doctrineHelper;
    private $fileManager;

    public function __construct(
        DoctrineHelper $doctrineHelper,
        FileManager $fileManager
    ) {
        $this->doctrineHelper = $doctrineHelper;
        $this->fileManager = $fileManager;
    }

    public static function getCommandName(): string
    {
        return 'make:dto';
    }

    public function configureCommand(Command $command, InputConfiguration $inputConf)
    {
        $command
            ->setDescription('Creates a new "data transfer object" (DTO) class from a Doctrine entity')
            ->addArgument('name', InputArgument::REQUIRED, sprintf('The name of the DTO class (e.g. <fg=yellow>%sData</>)', Str::asClassName(Str::getRandomTerm())))
            ->addArgument('bound-class', InputArgument::REQUIRED, 'The name of Entity that the DTO will be bound to')
            ->setHelp(file_get_contents(__DIR__.'/../Resources/help/MakeDto.txt'))
        ;

        $inputConf->setArgumentAsNonInteractive('bound-class');
    }

    public function interact(InputInterface $input, ConsoleStyle $io, Command $command)
    {
        if (null === $input->getArgument('bound-class')) {
            $argument = $command->getDefinition()->getArgument('bound-class');

            $entities = $this->doctrineHelper->getEntitiesForAutocomplete();

            $question = new Question($argument->getDescription());
            $question->setValidator(function ($answer) use ($entities) {return Validator::existsOrNull($answer, $entities); });
            $question->setAutocompleterValues($entities);
            $question->setMaxAttempts(3);

            $input->setArgument('bound-class', $io->askQuestion($question));
        }
    }

    public function generate(InputInterface $input, ConsoleStyle $io, Generator $generator)
    {
        $dataClassNameDetails = $generator->createClassNameDetails(
            $input->getArgument('name'),
            'Form\\Data\\',
            'Data'
        );

        $boundClass = $input->getArgument('bound-class');

        $boundClassDetails = $generator->createClassNameDetails(
            $boundClass,
            'Entity\\'
        );

        // verify that class is an entity
        if (false === $this->doctrineHelper->isClassAMappedEntity($boundClassDetails->getFullName())) {
            throw new RuntimeCommandException('The bound class is not a valid doctrine entity.');
        }

        // get class metadata (used to copy annotations and generate properties)
        $metaData = $this->doctrineHelper->getMetadata($boundClassDetails->getFullName());

        // get list of fields
        $fields = $metaData->fieldMappings;

        // The result is passed to the template
        $addHelpers = $io->confirm('Add helper extract/fill methods?');
        $omitGettersSetters = $io->confirm('Omit generation of getters/setters?');

        // Filter id from fields
        $fields = array_filter($fields, function ($field) {
            // mapping includes id field when property is an id
            if (!empty($field['id'])) {
                return false;
            }

            return true;
        });

        $boundClassVars = [
            'bounded_full_class_name' => $boundClassDetails->getFullName(),
            'bounded_class_name' => $boundClassDetails->getShortName(),
        ];

        $DTOClassPath = $generator->generateClass(
            $dataClassNameDetails->getFullName(),
            __DIR__.'/../Resources/skeleton/dto/DTO.tpl.php',
            array_merge(
                [
                    'fields' => $fields,
                    'addHelpers' => $addHelpers,
                    'omitGettersSetters' => $omitGettersSetters,
                ],
                $boundClassVars
            )
        );

        $generator->writeChanges();
        $manipulator = $this->createClassManipulator($DTOClassPath, $omitGettersSetters);
        $mappedFields = $this->getMappedFieldsInEntity($metaData);

        // Did we import assert annotations?
        $assertionsImported = false;

        foreach ($fields as $fieldName => $mapping) {
            $annotationReader = new AnnotationReader();

            // Lookup classname for inherited properties
            if (array_key_exists('declared', $mapping)) {
                $fullClassName = $mapping['declared'];
            } else {
                $fullClassName = $boundClassDetails->getFullName();
            }

            // Property Annotations
            $reflectionProperty = new \ReflectionProperty($fullClassName, $fieldName);
            $propertyAnnotations = $annotationReader->getPropertyAnnotations($reflectionProperty);

            $comments = [];

            foreach ($propertyAnnotations as $annotation) {
                // we want to copy the asserts, so look for their interface
                if ($annotation instanceof Constraint) {
                    $assertionsImported = true;
                    $comments[] = $manipulator->buildAnnotationLine('@Assert\\'.(new \ReflectionClass($annotation))->getShortName(), (array) $annotation);
                }
            }

            $manipulator->addEntityField($fieldName, $mapping, $comments);
        }

        $this->fileManager->dumpFile(
            $DTOClassPath,
            $manipulator->getSourceCode()
        );

        $this->writeSuccessMessage($io);

        if (true === $assertionsImported) {
            $io->note([
                'The maker imported assertion annotations.',
                'Consider removing them from the entity or make sure to keep them updated in both places.',
            ]);
        }

        $io->text([
            'Next: Create your form with this DTO and start using it:',
            '$ php bin/console make:form '.$boundClassDetails->getShortName(),
            '<fg=green>Enter fully qualified data class name to bind to the form:</>',
            '> \\'.$dataClassNameDetails->getFullName(),
            '',
            'Find the documentation at <fg=yellow>https://symfony.com/doc/current/forms.html</>',
        ]);
    }

    public function configureDependencies(DependencyBuilder $dependencies)
    {
        $dependencies->requirePHP71();

        $dependencies->addClassDependency(
            Validation::class,
            'validator',
            // add as an optional dependency: the user *probably* wants validation
            false
        );
    }

    private function createClassManipulator(string $classPath, bool $omitGettersSetters = false): DTOClassSourceManipulator
    {
        return new DTOClassSourceManipulator(
            $this->fileManager->getFileContents($classPath),
            // overwrite existing methods
            true,
            // use annotations
            true,
            // use fluent mutators
            true,
            // omit getters setters?
            $omitGettersSetters
        );
    }

    private function getMappedFieldsInEntity(ClassMetadata $classMetadata)
    {
        $targetFields = array_merge(
            array_keys($classMetadata->fieldMappings),
            array_keys($classMetadata->associationMappings)
        );

        return $targetFields;
    }
}