<?php

namespace Wvision\Bundle\ImportDefinitionsBundle\Importer;

use CoreShop\Component\Registry\ServiceRegistryInterface;
use Pimcore\File;
use Pimcore\Mail;
use Pimcore\Model\Document;
use Pimcore\Model\Object\AbstractObject;
use Pimcore\Model\Object\ClassDefinition;
use Pimcore\Model\Object\Concrete;
use Pimcore\Model\Object\Listing;
use Pimcore\Model\Object\Service;
use Pimcore\Model\Version;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Wvision\Bundle\ImportDefinitionsBundle\Event\ImportDefinitionEvent;
use Wvision\Bundle\ImportDefinitionsBundle\Filter\FilterInterface;
use Wvision\Bundle\ImportDefinitionsBundle\Model\DefinitionInterface;
use Wvision\Bundle\ImportDefinitionsBundle\Model\Mapping;
use Wvision\Bundle\ImportDefinitionsBundle\Provider\ProviderInterface;
use Wvision\Bundle\ImportDefinitionsBundle\Runner\RunnerInterface;

final class Importer implements ImporterInterface
{
    /**
     * @var ServiceRegistryInterface
     */
    protected $providerRegistry;

    /**
     * @var ServiceRegistryInterface
     */
    protected $filterRegistry;

    /**
     * @var ServiceRegistryInterface
     */
    protected $runnerRegistry;

    /**
     * @var ServiceRegistryInterface
     */
    protected $interpreterRegistry;

    /**
     * @var ServiceRegistryInterface
     */
    protected $setterRegistry;

    /**
     * @var ServiceRegistryInterface
     */
    protected $cleanerRegistry;

    /**
     * @var EventDispatcherInterface
     */
    protected $eventDispatcher;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var array
     */
    protected $objectIds = [];

    /**
     * @var array
     */
    protected $exceptions = [];

    /**
     * Importer constructor.
     * @param ServiceRegistryInterface $providerRegistry
     * @param ServiceRegistryInterface $filterRegistry
     * @param ServiceRegistryInterface $runnerRegistry
     * @param ServiceRegistryInterface $interpreterRegistry
     * @param ServiceRegistryInterface $setterRegistry
     * @param ServiceRegistryInterface $cleanerRegistry
     * @param EventDispatcherInterface $eventDispatcher
     * @param LoggerInterface $logger
     */
    public function __construct(
        ServiceRegistryInterface $providerRegistry,
        ServiceRegistryInterface $filterRegistry,
        ServiceRegistryInterface $runnerRegistry,
        ServiceRegistryInterface $interpreterRegistry,
        ServiceRegistryInterface $setterRegistry,
        ServiceRegistryInterface $cleanerRegistry,
        EventDispatcherInterface $eventDispatcher,
        LoggerInterface $logger
    )
    {
        $this->providerRegistry = $providerRegistry;
        $this->filterRegistry = $filterRegistry;
        $this->runnerRegistry = $runnerRegistry;
        $this->interpreterRegistry = $interpreterRegistry;
        $this->setterRegistry = $setterRegistry;
        $this->cleanerRegistry = $cleanerRegistry;
        $this->eventDispatcher = $eventDispatcher;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function doImport(DefinitionInterface $definition, $params)
    {
        $filter = null;

        if ($definition->getCreateVersion()) {
            Version::enable();
        } else {
            Version::disable();
        }

        if ($definition->getFilter()) {
            $filter = $this->filterRegistry->get($definition->getFilter());
        }

        $data = $this->getData($definition, $params);

        if (count($data) > 0) {
            $this->eventDispatcher->dispatch('total', new ImportDefinitionEvent($definition));

            $this->runImport($definition, $params, $filter, $data);
        }

        if ($definition->getCleaner()) {
            $cleaner = $this->cleanerRegistry->get($definition->getCleaner());

            $this->logger->info(sprintf("Running Cleaner '%s", $definition->getCleaner()));
            $this->eventDispatcher->dispatch('import_definition.status', new ImportDefinitionEvent($definition, sprintf("Running Cleaner '%s", $type)));

            $cleaner->cleanup($definition, $this->objectIds);

            $this->logger->info(sprintf("Finished Cleaner '%s", $definition->getCleaner()));
            $this->eventDispatcher->dispatch('import_definition.status', new ImportDefinitionEvent($definition, sprintf("Finished Cleaner '%s", $type)));
        }

        if (count($this->exceptions) > 0) {
            $this->sendDocument($definition, Document::getById($definition->getFailureNotificationDocument()));
        } else {
            $this->sendDocument($definition, Document::getById($definition->getSuccessNotificationDocument()));
        }

        $this->eventDispatcher->dispatch('import_definition.finished', new ImportDefinitionEvent($definition));
    }

    /**
     * @param DefinitionInterface $definition
     * @param $document
     */
    protected function sendDocument(DefinitionInterface $definition, $document)
    {
        if ($document instanceof Document) {
            $params = [
                "exceptions" => $this->exceptions,
                "objectIds" => $this->objectIds,
                "className" => $definition->getClass(),
                "countObjects" => count($this->objectIds),
                "countExceptions" => count($this->exceptions),
                "name" => $definition->getName(),
                "provider" => $definition->getProvider()
            ];

            if ($document instanceof Document\Email) {
                $mail = new Mail();
                $mail->setDocument($document);
                $mail->setParams($params);

                $mail->send();
            } else if (is_a($document, "\\Pimcore\\Model\\Document\\Pushover")) {
                $document->send($params);
            }
        }
    }

    protected function getData(DefinitionInterface $definition, $params)
    {
        /**
         * @var $provider ProviderInterface
         */
        $provider = $this->providerRegistry->get($definition->getProvider());

        return $provider->getData($definition, $params);
    }

    /**
     * @param DefinitionInterface $definition
     * @param $params
     * @param null $filter
     * @param array $data
     * @throws \Exception
     */
    protected function runImport(DefinitionInterface $definition, $params, $filter = null, $data = array())
    {
        $count = 0;
        $countToClean = 1000;

        if (is_array($data)) {
            foreach ($data as $row) {
                try {
                    $object = $this->importRow($definition, $row, $params, $filter);

                    if ($object instanceof Concrete) {
                        $this->objectIds[] = $object->getId();
                    }

                    if (($count + 1) % $countToClean === 0) {
                        \Pimcore::collectGarbage();
                        $this->logger->info("Clean Garbage");
                        $this->eventDispatcher->dispatch('import_definition.status', new ImportDefinitionEvent($definition, 'Collect Garbage'));
                    }

                    $count++;
                } catch (\Exception $ex) {
                    $this->logger->error($ex);

                    $this->exceptions[] = $ex;

                    $this->eventDispatcher->dispatch('import_definition.status', new ImportDefinitionEvent($definition, "Error: " . $ex->getMessage()));

                    if ($definition->getStopOnException()) {
                        throw $ex;
                    }
                }

                $this->eventDispatcher->dispatch('import_definition.progress', new ImportDefinitionEvent($definition));
            }
        }
    }

    /**
     * @param DefinitionInterface $definition
     * @param $data
     * @param $params
     * @param null $filter
     * @return null|Concrete
     */
    protected function importRow(DefinitionInterface $definition, $data, $params, $filter = null)
    {
        $runner = null;

        $object = $this->getObjectForPrimaryKey($definition, $data);

        if (!$object->getId()) {
            if ($definition->getSkipNewObjects()) {
                $this->eventDispatcher->dispatch('import_definition.status', new ImportDefinitionEvent($definition, 'Ignoring new Object'));
                return null;
            }
        } else {
            if ($definition->getSkipExistingObjects()) {
                $this->eventDispatcher->dispatch('import_definition.status', new ImportDefinitionEvent($definition, 'Ignoring existing Object'));
                return null;
            }
        }

        if ($filter instanceof FilterInterface) {
            if (!$filter->filter($definition, $data, $object)) {
                $this->eventDispatcher->dispatch('import_definition.status', new ImportDefinitionEvent($definition, 'Filtered Object'));
                return null;
            }
        }

        $this->eventDispatcher->dispatch('import_definition.status', new ImportDefinitionEvent($definition, sprintf('Import Object %s', ($object->getId() ? $object->getFullPath() : "new"))));
        $this->eventDispatcher->dispatch('import_definition.object.start', new ImportDefinitionEvent($definition, $object));

        if ($definition->getRunner()) {
            $runner = $this->runnerRegistry->get($definition->getRunner());
        }


        if ($runner instanceof RunnerInterface) {
            $runner->preRun($object, $data, $definition, $params);
        }

        $this->logger->info("Imported Object: " . $object->getRealFullPath());

        foreach ($definition->getMapping() as $mapItem) {
            $value = null;

            if (array_key_exists($mapItem->getFromColumn(), $data)) {
                $value = $data[$mapItem->getFromColumn()];
            }

            $this->setObjectValue($object, $mapItem, $value, $data, $definition, $params);
        }

        $object->setUserModification(0); //Set User to "system"
        $object->save();

        $this->eventDispatcher->dispatch('import_definition.status', new ImportDefinitionEvent($definition, sprintf('Imported Object %s', $object->getFullPath())));
        $this->eventDispatcher->dispatch('import_definition.object.finished', new ImportDefinitionEvent($definition, $object));

        if ($runner instanceof RunnerInterface) {
            $runner->postRun($object, $data, $definition, $params);
        }

        return $object;
    }

    /**
     * @param Concrete $object
     * @param Mapping $map
     * @param $value
     * @param $data
     * @param DefinitionInterface $definition
     * @param $params
     * @throws \Exception
     */
    protected function setObjectValue(Concrete $object, Mapping $map, $value, $data, DefinitionInterface $definition, $params)
    {
        $mapConfig = $map->getConfig();

        if ($mapConfig['interpreter']) {
            $interpreter = $this->interpreterRegistry->get($mapConfig['interpreter']);
            $value = $interpreter->interpret($object, $value, $map, $data, $definition, $params);
        }

        if ($map->getToColumn() === "type") {
            if ($mapConfig['setter'] !== "objectType") {
                throw new \Exception("Type has to be used with ObjectType Setter!");
            }
        }

        if ($mapConfig['setter']) {
            $setter = $this->setterRegistry->get($mapConfig['setter']);
            $setter->set($object, $value, $map, $data);
        } else {
            $object->setValue($map->getToColumn(), $value);
        }
    }

    /**
     * @param DefinitionInterface $definition
     * @param $data
     * @return null|Concrete
     * @throws \Exception
     */
    protected function getObjectForPrimaryKey(DefinitionInterface $definition, $data)
    {
        $class = $definition->getClass();
        $classDefinition = ClassDefinition::getByName($class);
        $obj = null;

        if (!$classDefinition instanceof ClassDefinition) {
            throw new \Exception("Class not found $class");
        }

        $classObject = '\Pimcore\Model\Object\\' . ucfirst($class);
        $classList = '\Pimcore\Model\Object\\' . ucfirst($class) . '\Listing';

        $list = new $classList();

        if ($list instanceof Listing) {
            $mapping = $definition->getMapping();
            $condition = [];
            $conditionValues = [];
            foreach ($mapping as $map) {
                if ($map->getPrimaryIdentifier()) {
                    $condition[] = $map->getToColumn() . " = ?";
                    $conditionValues[] = $data[$map->getFromColumn()];
                }
            }

            if (count($condition) === 0) {
                throw new \Exception("No primary identifier defined!");
            }

            $list->setUnpublished(true);
            $list->setCondition(implode(" AND ", $condition), $conditionValues);
            $list->setObjectTypes([Concrete::OBJECT_TYPE_VARIANT, Concrete::OBJECT_TYPE_OBJECT, Concrete::OBJECT_TYPE_FOLDER]);
            $list->load();
            $objectData = $list->getObjects();

            if (count($objectData) === 1) {
                $obj = $objectData[0];
            }

            if (!isset($obj)) {
                $obj = new $classObject();
            }

            if ($obj instanceof AbstractObject) {
                $key = File::getValidFilename($definition->createKey($data));

                if ($definition->getRelocateExistingObjects() || !$obj->getId()) {
                    $obj->setParent(Service::createFolderByPath($definition->createPath($data)));
                }

                if ($definition->getRenameExistingObjects() || !$obj->getId()) {
                    if ($definition->getKey() && $key) {
                        $obj->setKey($key);
                    } else {
                        $obj->setKey(File::getValidFilename(implode("-", $conditionValues)));
                    }
                }

                $obj->setKey(Service::getUniqueKey($obj));

                return $obj;
            }

            if (count($objectData) > 1) {
                throw new \Exception("Object with the same primary key was found multiple times");
            }
        }

        return null;
    }
}