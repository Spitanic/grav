<?php

/**
 * @package    Grav\Framework\Flex
 *
 * @copyright  Copyright (C) 2015 - 2020 Trilby Media, LLC. All rights reserved.
 * @license    MIT License; see LICENSE file for details.
 */

namespace Grav\Framework\Flex;

use Grav\Common\Data\Blueprint;
use Grav\Common\Data\Data;
use Grav\Common\Grav;
use Grav\Common\Twig\Twig;
use Grav\Framework\Flex\Interfaces\FlexDirectoryFormInterface;
use Grav\Framework\Flex\Interfaces\FlexFormInterface;
use Grav\Framework\Flex\Interfaces\FlexObjectInterface;
use Grav\Framework\Form\Interfaces\FormFlashInterface;
use Grav\Framework\Form\Traits\FormTrait;
use Grav\Framework\Route\Route;
use RocketTheme\Toolbox\ArrayTraits\NestedArrayAccessWithGetters;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;
use Twig\Template;
use Twig\TemplateWrapper;

/**
 * Class FlexForm
 * @package Grav\Framework\Flex
 */
class FlexDirectoryForm implements FlexDirectoryFormInterface, \JsonSerializable
{
    use NestedArrayAccessWithGetters {
        NestedArrayAccessWithGetters::get as private traitGet;
        NestedArrayAccessWithGetters::set as private traitSet;
    }
    use FormTrait {
        FormTrait::doSerialize as doTraitSerialize;
        FormTrait::doUnserialize as doTraitUnserialize;
    }

    /** @var array|null */
    private $form;

    /** @var FlexDirectory|null */
    private $directory;

    /** @var string */
    private $flexName;

    /**
     * @param array $options    Options to initialize the form instance:
     *                          (string) name: Form name, allows you to use custom form.
     *                          (string) unique_id: Unique id for this form instance.
     *                          (array) form: Custom form fields.
     *                          (FlexDirectory) directory: Flex Directory, mandatory.
     *
     * @return FlexFormInterface
     */
    public static function instance(array $options = [])
    {
        if (isset($options['directory'])) {
            $directory = $options['directory'];
            if (!$directory instanceof FlexDirectory) {
                throw new \RuntimeException(__METHOD__ . "(): 'directory' should be instance of FlexDirectory", 400);
            }
            unset($options['directory']);
        } else {
            throw new \RuntimeException(__METHOD__ . "(): You need to pass option 'directory'", 400);
        }

        $name = $options['name'] ?? '';

        return $directory->getConfigureForm($name, $options);
    }

    /**
     * FlexForm constructor.
     * @param string $name
     * @param FlexDirectory $object
     * @param array $options
     */
    public function __construct(string $name, FlexDirectory $directory, array $options = null)
    {
        $this->name = $name;
        $this->setDirectory($directory);
        $this->setName($directory->getFlexType(), $name);
        $this->setId($this->getName());

        $uniqueId = $options['unique_id'] ?? null;
        if (!$uniqueId) {
            $uniqueId = md5($directory->getFlexType());
        }
        $this->setUniqueId($uniqueId);
        $this->setFlashLookupFolder($directory->getConfigureBlueprint($name)->get('form/flash_folder') ?? 'tmp://forms/[SESSIONID]');
        $this->form = $options['form'] ?? null;

        $this->initialize();
    }

    /**
     * @return $this
     */
    public function initialize()
    {
        $this->messages = [];
        $this->submitted = false;
        $this->data = null;
        $this->files = [];
        $this->unsetFlash();

        /** @var FlexFormFlash $flash */
        $flash = $this->getFlash();
        if ($flash->exists()) {
            $data = $flash->getData();
            $includeOriginal = (bool)($this->getBlueprint()->form()['images']['original'] ?? null);

            $this->directory = $flash->getDirectory();
            $this->data = $data ? new Data($data, $this->getBlueprint()) : null;
            $this->files = $flash->getFilesByFields($includeOriginal);
        }

        return $this;
    }

    /**
     * @param string $name
     * @param mixed $default
     * @param string|null $separator
     * @return mixed
     */
    public function get($name, $default = null, $separator = null)
    {
        switch (strtolower($name)) {
            case 'id':
            case 'uniqueid':
            case 'name':
            case 'noncename':
            case 'nonceaction':
            case 'action':
            case 'data':
            case 'files':
            case 'errors';
            case 'fields':
            case 'blueprint':
            case 'page':
                $method = 'get' . $name;
                return $this->{$method}();
        }

        return $this->traitGet($name, $default, $separator);
    }

    /**
     * @param string $name
     * @param mixed $value
     * @param string|null $separator
     * @return FlexForm
     */
    public function set($name, $value, $separator = null)
    {
        switch (strtolower($name)) {
            case 'id':
            case 'uniqueid':
                $method = 'set' . $name;
                return $this->{$method}();
        }

        return $this->traitSet($name, $value, $separator);
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->flexName;
    }

    protected function setName(string $type, string $name): void
    {
        // Make sure that both type and name do not have dash (convert dashes to underscores).
        $type = str_replace('-', '_', $type);
        $name = str_replace('-', '_', $name);
        // FIXME:
        $this->flexName = $name ? "flex-{$type}-{$name}" : "flex-{$type}";
    }

    /**
     * @return Data|FlexObjectInterface|object
     */
    public function getData()
    {
        return $this->data ?? $this->getObject();
    }

    /**
     * Get a value from the form.
     *
     * Note: Used in form fields.
     *
     * @param string $name
     * @return mixed
     */
    public function getValue(string $name)
    {
        // Attempt to get value from the form data.
        $value = $this->data ? $this->data[$name] : null;

        // Return the form data or fall back to the object property.
        return $value ?? $this->getObject()->getFormValue($name);
    }

    public function getDefaultValue(string $name)
    {
        return $this->object->getDefaultValue($name);
    }

    /**
     * @return array
     */
    public function getDefaultValues(): array
    {
        return $this->object->getDefaultValues();
    }
    /**
     * @return string
     */
    public function getFlexType(): string
    {
        return $this->directory->getFlexType();
    }

    /**
     * Get form flash object.
     *
     * @return FormFlashInterface|FlexFormFlash
     */
    public function getFlash()
    {
        if (null === $this->flash) {
            $grav = Grav::instance();
            $config = [
                'session_id' => $this->getSessionId(),
                'unique_id' => $this->getUniqueId(),
                'form_name' => $this->getName(),
                'folder' => $this->getFlashFolder(),
                'object' => $this->getObject()
            ];

            $this->flash = new FlexFormFlash($config);
            $this->flash
                ->setUrl($grav['uri']->url)
                ->setUser($grav['user'] ?? null);
        }

        return $this->flash;
    }

    /**
     * @return FlexObjectInterface
     */
    public function getDirectory(): FlexDirectory
    {
        return $this->directory;
    }

    public function updateObject(): FlexObjectInterface
    {
        $data = $this->data instanceof Data ? $this->data->toArray() : [];
        $files = $this->files;

        return $this->getObject()->update($data, $files);
    }

    /**
     * @return Blueprint
     */
    public function getBlueprint(): Blueprint
    {
        if (null === $this->blueprint) {
            try {
                $blueprint = $this->getObject()->getBlueprint($this->name);
                if ($this->form) {
                    // We have field overrides available.
                    $blueprint->extend(['form' => $this->form], true);
                    $blueprint->init();
                }
            } catch (\RuntimeException $e) {
                if (!isset($this->form['fields'])) {
                    throw $e;
                }

                // Blueprint is not defined, but we have custom form fields available.
                $blueprint = new Blueprint(null, ['form' => $this->form]);
                $blueprint->load();
                $blueprint->setScope('object');
                $blueprint->init();
            }

            $this->blueprint = $blueprint;
        }

        return $this->blueprint;
    }

    /**
     * @return Route|null
     */
    public function getFileUploadAjaxRoute(): ?Route
    {
        $object = $this->getObject();
        if (!method_exists($object, 'route')) {
            return null;
        }

        return $object->route('/edit.json/task:media.upload');
    }

    /**
     * @param string $field
     * @param string $filename
     * @return Route|null
     */
    public function getFileDeleteAjaxRoute($field, $filename): ?Route
    {
        $object = $this->getObject();
        if (!method_exists($object, 'route')) {
            return null;
        }

        return $object->route('/edit.json/task:media.delete');
    }

    public function getMediaTaskRoute(array $params = [], $extension = null): string
    {
        $grav = Grav::instance();
        /** @var Flex $flex */
        $flex = $grav['flex'];

        if (method_exists($flex, 'adminRoute')) {
            return $flex->adminRoute($this->getObject(), $params, $extension ?? 'json');
        }

        return '';
    }

    /**
     * Implements \Serializable::unserialize().
     *
     * @param string $data
     */
    public function unserialize($data): void
    {
        $data = unserialize($data, ['allowed_classes' => [FlexObject::class]]);

        $this->doUnserialize($data);
    }

    public function __get($name)
    {
        $method = "get{$name}";
        if (method_exists($this, $method)) {
            return $this->{$method}();
        }

        $form = $this->getBlueprint()->form();

        return $form[$name] ?? null;
    }

    public function __set($name, $value)
    {
        $method = "set{$name}";
        if (method_exists($this, $method)) {
            $this->{$method}($value);
        }
    }

    public function __isset($name)
    {
        $method = "get{$name}";
        if (method_exists($this, $method)) {
            return true;
        }

        $form = $this->getBlueprint()->form();

        return isset($form[$name]);
    }

    public function __unset($name)
    {
    }

    /**
     * Note: this method clones the object.
     *
     * @param FlexDirectory $directory
     * @return $this
     */
    protected function setDirectory(FlexDirectory $directory): self
    {
        $this->directory = $directory;

        return $this;
    }

    /**
     * @param string $layout
     * @return Template|TemplateWrapper
     * @throws LoaderError
     * @throws SyntaxError
     */
    protected function getTemplate($layout)
    {
        $grav = Grav::instance();

        /** @var Twig $twig */
        $twig = $grav['twig'];

        return $twig->twig()->resolveTemplate(
            [
                "flex-objects/layouts/{$this->getFlexType()}/form/{$layout}.html.twig",
                "flex-objects/layouts/_default/form/{$layout}.html.twig",
                "forms/{$layout}/form.html.twig",
                'forms/default/form.html.twig'
            ]
        );
    }

    /**
     * @param array $data
     * @param array $files
     * @throws \Exception
     */
    protected function doSubmit(array $data, array $files)
    {
        // FIXME:
        /** @var FlexObject $object */
        $object = clone $this->getObject();

        $object->update($data, $files);
        $object->save();

        $this->setObject($object);
        $this->reset();
    }

    protected function doSerialize(): array
    {
        return $this->doTraitSerialize() + [
                'directory' => $this->directory,
            ];
    }

    protected function doUnserialize(array $data): void
    {
        $this->doTraitUnserialize($data);

        $this->directory = $data['directory'];
    }

    /**
     * Filter validated data.
     *
     * @param \ArrayAccess|Data|null $data
     */
    protected function filterData($data = null): void
    {
        if ($data instanceof Data) {
            $data->filter(true, true);
        }
    }
}