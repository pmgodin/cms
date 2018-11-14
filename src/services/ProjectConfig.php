<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\services;

use Craft;
use craft\base\Plugin;
use craft\events\ConfigEvent;
use craft\helpers\DateTimeHelper;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use craft\helpers\Path as PathHelper;
use Symfony\Component\Yaml\Yaml;
use yii\base\Application;
use yii\base\Component;
use yii\base\ErrorException;
use yii\base\Exception;
use yii\base\NotSupportedException;
use yii\web\ServerErrorHttpException;

/**
 * Project config service.
 * An instance of the ProjectConfig service is globally accessible in Craft via [[\craft\base\ApplicationTrait::ProjectConfig()|`Craft::$app->projectConfig`]].
 *
 * @property-read bool $areChangesPending Whether `project.yaml` has any pending changes that need to be applied to the project config
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.1
 */
class ProjectConfig extends Component
{
    // Constants
    // =========================================================================

    // Cache settings
    // -------------------------------------------------------------------------

    const CACHE_KEY = 'project.config.files';
    const CACHE_DURATION = 2592000; // 30 days

    // Array key to use if not using config files.
    const CONFIG_KEY = 'storedConfig';

    // Filename for base config file
    const CONFIG_FILENAME = 'project.yaml';

    // Key to use for schema version storage.
    const CONFIG_SCHEMA_VERSION_KEY = 'system.schemaVersion';

    // TODO move this to UID validator class
    // TODO update StringHelper::isUUID() to use that
    // Regexp patterns
    // -------------------------------------------------------------------------

    const UID_PATTERN = '[a-zA-Z0-9_-]+';

    // Events
    // -------------------------------------------------------------------------

    /**
     * @event ConfigEvent The event that is triggered when an item is added to the config.
     *
     * ```php
     * use craft\events\ParseConfigEvent;
     * use craft\services\ProjectConfig;
     * use yii\base\Event;
     *
     * Event::on(ProjectConfig::class, ProjectConfig::EVENT_ADD_ITEM, function(ParseConfigEvent $e) {
     *     // Ensure the item is also added in the database...
     * });
     * ```
     */
    const EVENT_ADD_ITEM = 'addItem';

    /**
     * @event ConfigEvent The event that is triggered when an item is updated in the config.
     *
     * ```php
     * use craft\events\ParseConfigEvent;
     * use craft\services\ProjectConfig;
     * use yii\base\Event;
     *
     * Event::on(ProjectConfig::class, ProjectConfig::EVENT_UPDATE_ITEM, function(ParseConfigEvent $e) {
     *     // Ensure the item is also updated in the database...
     * });
     * ```
     */
    const EVENT_UPDATE_ITEM = 'updateItem';

    /**
     * @event ConfigEvent The event that is triggered when an item is removed from the config.
     *
     * ```php
     * use craft\events\ParseConfigEvent;
     * use craft\services\ProjectConfig;
     * use yii\base\Event;
     *
     * Event::on(ProjectConfig::class, ProjectConfig::EVENT_REMOVE_ITEM, function(ParseConfigEvent $e) {
     *     // Ensure the item is also removed in the database...
     * });
     * ```
     */
    const EVENT_REMOVE_ITEM = 'removeItem';

    /**
     * @event Event The event that is triggered after pending changes in `config/project.yaml` have been applied.
     */
    const EVENT_AFTER_APPLY_CHANGES = 'afterApplyChanges';

    // Properties
    // =========================================================================

    /**
     * @var bool Whether the project config is read-only.
     */
    public $readOnly = false;

    /**
     * @var array Current config as stored in database.
     */
    private $_storedConfig;

    /**
     * @var array A list of already parsed change paths
     */
    private $_parsedChanges = [];

    /**
     * @var array An array of paths to data structures used as intermediate storage.
     */
    private $_parsedConfigs = [];

    /**
     * @var array A list of all config files, defined by import directives in configuration files.
     */
    private $_configFileList = [];

    /**
     * @var array A list of Yaml files that have been modified during this request and need to be saved.
     */
    private $_modifiedYamlFiles = [];

    /**
     * @var array Config map currently used
     * @see _getStoredConfigMap()
     */
    private $_configMap;

    /**
     * @var bool Whether to update the config map on request end
     */
    private $_updateConfigMap = false;

    /**
     * @var bool Whether to update the config on request end
     */
    private $_updateConfig = false;

    /**
     * @var bool Whether we’re listening for the request end, to update the Yaml caches.
     * @see updateParsedConfigTimes()
     */
    private $_waitingToUpdateParsedConfigTimes = false;

    /**
     * @var bool Whether we’re listening for the request end, to update the modified config data.
     * @see saveModifiedConfigData()
     */
    private $_waitingToSaveModifiedConfigData = false;

    /**
     * @var bool Whether we're saving project configs to project.yaml
     * @see _useConfigFile()
     */
    private $_useConfigFile;

    /**
     * @var bool Whether the config's dateModified timestamp has been updated by this request.
     */
    private $_timestampUpdated = false;

    // Public methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init()
    {
        $this->saveDataAfterRequest();

        // If we're not using the project config file, load the stored config to emulate config files.
        // This is needed so we can make comparisons between the existing config and the modified config, as we're firing events.
        if (!$this->_useConfigFile()) {
            $this->_getConfigurationFromYaml();
        }

        parent::init();
    }

    /**
     * Set up an event handler to save modified data after request is over. This is called automatically when service is initialized.
     *
     * @return void
     */
    public function saveDataAfterRequest()
    {
        if (!$this->_waitingToSaveModifiedConfigData) {
            Craft::$app->on(Application::EVENT_AFTER_REQUEST, [$this, 'saveModifiedConfigData']);
            $this->_waitingToSaveModifiedConfigData = true;
        }
    }

    /**
     * Disable the event handler that would save modified data after request is over.
     *
     * @return void
     */
    public function preventSavingDataAfterRequest()
    {
        if ($this->_waitingToSaveModifiedConfigData) {
            Craft::$app->off(Application::EVENT_AFTER_REQUEST, [$this, 'saveModifiedConfigData']);
            $this->_waitingToSaveModifiedConfigData = false;
        }
    }

    /**
     * Returns a config item value value by its path.
     *
     * ---
     *
     * ```php
     * $value = Craft::$app->projectConfig->get('foo.bar');
     * ```
     *
     * @param string $path The config item path
     * @param bool $getFromYaml whether data should be fetched from `config/project.yaml` instead of the stored config. Defaults to `false`.
     * @return mixed The config item value
     */
    public function get(string $path, $getFromYaml = false)
    {
        if ($getFromYaml) {
            $source = $this->_getConfigurationFromYaml();
        } else {
            $source = $this->_getStoredConfig();
        }

        return $this->_traverseDataArray($source, $path);
    }

    /**
     * Sets a config item value at the given path.
     *
     * ---
     *
     * ```php
     * Craft::$app->projectConfig->set('foo.bar', 'value');
     * ```
     *
     * @param string $path The config item path
     * @param mixed $value The config item value
     * @throws NotSupportedException if the service is set to read-only mode
     * @throws ErrorException
     * @throws Exception
     * @throws ServerErrorHttpException
     * @todo make sure $value is serializable and unserialable
     */
    public function set(string $path, $value)
    {
        if ($this->readOnly) {
            throw new NotSupportedException('Changes to the project config are not possible while in read-only mode.');
        }

        $pathParts = explode('.', $path);

        $targetFilePath = null;

        if (!$this->_timestampUpdated) {
            $this->_timestampUpdated = true;
            $this->set('dateModified', DateTimeHelper::currentTimeStamp());
        }

        if ($this->_useConfigFile()) {
            $configMap = $this->_getStoredConfigMap();

            $topNode = array_shift($pathParts);
            $targetFilePath = $configMap[$topNode] ?? Craft::$app->getPath()->getConfigPath() . DIRECTORY_SEPARATOR . self::CONFIG_FILENAME;

            $config = $this->_parseYamlFile($targetFilePath);

            // For new top nodes, update the map
            if (empty($configMap[$topNode])) {
                $this->_mapNodeLocation($topNode, Craft::$app->getPath()->getConfigPath() . DIRECTORY_SEPARATOR . self::CONFIG_FILENAME);
                $this->_updateConfigMap = true;
            }
        } else {
            $config = $this->_getConfigurationFromYaml();
        }

        $this->_traverseDataArray($config, $path, $value, $value === null);

        $this->_saveConfig($config, $targetFilePath);

        // Ensure that new data is processed
        unset($this->_parsedChanges[$path]);

        return $this->processConfigChanges($path);
    }

    /**
     * Removes a config item at the given path.
     *
     * ---
     * ```php
     * Craft::$app->projectConfig->remove('foo.bar');
     * ```
     *
     * @param string $path The config item path
     */
    public function remove(string $path)
    {
        $this->set($path, null);
    }

    /**
     * Regenerates `project.yaml` based on the loaded project config.
     */
    public function regenerateYamlFromConfig()
    {
        $storedConfig = $this->_getStoredConfig();

        $basePath = Craft::$app->getPath()->getConfigPath();
        $baseFile = $basePath . '/' . self::CONFIG_FILENAME;

        $this->_saveConfig($storedConfig, $baseFile);
        $this->updateParsedConfigTimesAfterRequest();
    }

    /**
     * Applies changes in `project.yaml` to the project config.
     */
    public function applyYamlChanges()
    {
        try {
            $changes = $this->_getPendingChanges();

            Craft::info('Looking for pending changes', __METHOD__);

            // If we're parsing all the changes, we better work the actual config map.
            $this->_configMap = $this->_generateConfigMap();

            if (!empty($changes['removedItems'])) {
                Craft::info('Parsing ' . count($changes['removedItems']) . ' removed configuration items', __METHOD__);
                foreach ($changes['removedItems'] as $itemPath) {
                    $this->processConfigChanges($itemPath);
                }
            }

            if (!empty($changes['changedItems'])) {
                Craft::info('Parsing ' . count($changes['changedItems']) . ' changed configuration items', __METHOD__);
                foreach ($changes['changedItems'] as $itemPath) {
                    $this->processConfigChanges($itemPath);
                }
            }

            if (!empty($changes['newItems'])) {
                Craft::info('Parsing ' . count($changes['newItems']) . ' new configuration items', __METHOD__);
                foreach ($changes['newItems'] as $itemPath) {
                    $this->processConfigChanges($itemPath);
                }
            }

            Craft::info('Finalizing configuration parsing', __METHOD__);

            // Fire an 'afterApplyChanges' event
            if ($this->hasEventHandlers(self::EVENT_AFTER_APPLY_CHANGES)) {
                $this->trigger(self::EVENT_AFTER_APPLY_CHANGES);
            }

            $this->updateParsedConfigTimesAfterRequest();
            $this->_updateConfigMap = true;
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    /**
     * Returns whether `project.yaml` has any pending changes that need to be applied to the project config.
     *
     * @return bool
     */
    public function getAreChangesPending(): bool
    {
        // TODO remove after next breakpoint
        if (version_compare(Craft::$app->getInfo()->version, '3.1', '<')) {
            return false;
        }

        // If the file does not exist, but should, generate it
        if ($this->_useConfigFile() && !file_exists(Craft::$app->getPath()->getConfigPath() . '/' . self::CONFIG_FILENAME)) {
            $this->regenerateYamlFromConfig();
            $this->saveModifiedConfigData();
        }

        if ($this->_useConfigFile() && $this->_areConfigFilesModified()) {
            $changes = $this->_getPendingChanges();

            foreach ($changes as $changeType) {
                if (!empty($changeType)) {
                    return true;
                }
            }

            $this->updateParsedConfigTimes();
        }

        return false;
    }

    /**
     * Processes changes in `config/project.yaml` for a given path.
     *
     * @param string $path The config item path
     */
    public function processConfigChanges(string $path)
    {
        if (!empty($this->_parsedChanges[$path])) {
            return;
        }

        $this->_parsedChanges[$path] = true;

        $oldValue = $this->get($path);
        $newValue = $this->get($path, true);

        $event = new ConfigEvent(compact('path', 'oldValue', 'newValue'));

        if ($oldValue && !$newValue) {
            // Fire a 'removeItem' event
            $this->trigger(self::EVENT_REMOVE_ITEM, $event);
        } else if (!$oldValue && $newValue) {
            // Fire an 'addItem' event
            $this->trigger(self::EVENT_ADD_ITEM, $event);
        } else if (
            $newValue !== null &&
            $oldValue !== null &&
            Json::encode($oldValue) !== Json::encode($newValue)
        ) {
            // Fire an 'updateItem' event
            $this->trigger(self::EVENT_UPDATE_ITEM, $event);
        } else {
            return;
        }

        $this->updateStoredConfigAfterRequest();
        $this->updateParsedConfigTimesAfterRequest();
    }

    /**
     * Updates the stored config after the request ends.
     */
    public function updateStoredConfigAfterRequest()
    {
        $this->_updateConfig = true;
    }

    /**
     * Updates cached config file modified times after the request ends.
     */
    public function updateParsedConfigTimesAfterRequest()
    {
        if ($this->_waitingToUpdateParsedConfigTimes || !$this->_useConfigFile()) {
            return;
        }

        Craft::$app->on(Application::EVENT_AFTER_REQUEST, [$this, 'updateParsedConfigTimes']);
        $this->_waitingToUpdateParsedConfigTimes = true;
    }

    /**
     * Updates cached config file modified times immediately.
     *
     * @return bool
     */
    public function updateParsedConfigTimes(): bool
    {
        $fileList = $this->_getConfigFileModifiedTimes();
        return Craft::$app->getCache()->set(self::CACHE_KEY, $fileList, self::CACHE_DURATION);
    }

    /**
     * Saves all the config data that has been modified up to now.
     *
     * @throws ErrorException
     */
    public function saveModifiedConfigData()
    {
        $traverseAndClean = function(&$array) use (&$traverseAndClean) {
            $remove = [];
            foreach ($array as $key => &$value) {
                if (\is_array($value)) {
                    $traverseAndClean($value);
                    if (empty($value)) {
                        $remove[] = $key;
                    }
                }
            }

            // Remove empty stuff
            foreach ($remove as $removeKey) {
                unset($array[$removeKey]);
            }

            ksort($array);
        };

        if (!empty($this->_modifiedYamlFiles) && $this->_useConfigFile()) {
            // Save modified yaml files
            $fileList = array_keys($this->_modifiedYamlFiles);

            foreach ($fileList as $filePath) {
                $data = $this->_parsedConfigs[$filePath];
                $traverseAndClean($data);
                FileHelper::writeToFile($filePath, Yaml::dump($data, 20, 2));
            }
        }

        if (($this->_updateConfigMap && $this->_useConfigFile()) || $this->_updateConfig) {
            $info = Craft::$app->getInfo();

            if ($this->_updateConfigMap && $this->_useConfigFile()) {
                $info->configMap = Json::encode($this->_generateConfigMap());
            }

            if ($this->_updateConfig) {
                $info->config = serialize($this->_getConfigurationFromYaml());
            }

            Craft::$app->saveInfo($info);
        }
    }

    /**
     * Returns a summary of all pending config changes.
     *
     * @return array
     */
    public function getPendingChangeSummary(): array
    {
        $pendingChanges = $this->_getPendingChanges();

        $summary = [];

        // Reduce all the small changes to overall item changes.
        foreach ($pendingChanges as $type => $changes) {
            $summary[$type] = [];
            foreach ($changes as $path) {
                $pathParts = explode('.', $path);
                if (count($pathParts) > 1) {
                    $summary[$type][$pathParts[0] . '.' . $pathParts[1]] = true;
                }
            }
        }

        return $summary;
    }

    /**
     * Returns whether all schema versions stored in the config are compatible with the actual codebase.
     *
     * @return bool
     */
    public function getAreConfigSchemaVersionsCompatible(): bool
    {
        // TODO remove after next breakpoint
        if (version_compare(Craft::$app->getInfo()->version, '3.1', '<')) {
            return true;
        }

        $configSchemaVersion = (string)$this->get(self::CONFIG_SCHEMA_VERSION_KEY, true);

        if (version_compare((string)Craft::$app->schemaVersion, $configSchemaVersion, '<')) {
            return false;
        }

        $plugins = Craft::$app->getPlugins()->getAllPlugins();

        foreach ($plugins as $plugin) {
            /** @var Plugin $plugin */
            $configSchemaVersion = (string)$this->get(Plugins::CONFIG_PLUGINS_KEY . '.' . $plugin->handle . '.schemaVersion', true);

            if (version_compare((string)$plugin->schemaVersion, $configSchemaVersion, '<')) {
                return false;
            }
        }

        return true;
    }

    // Config Change Event Registration
    // -------------------------------------------------------------------------

    /**
     * Attaches an event handler for when an item is added to the config at a given path.
     *
     * ---
     *
     * ```php
     * use craft\events\ConfigEvent;
     * use craft\helpers\Db;
     *
     * Craft::$app->projectConfig->onAdd('foo.{uid}', function(ConfigEvent $event) {
     *     // Get the UID from the item path
     *     $uid = $event->tokenMatches[0];
     *
     *     // Prep the row data
     *     $data = array_merge($event->newValue);
     *
     *     // See if the row already exists (maybe it was soft-deleted)
     *     $id = Db::idByUid('{{%tablename}}', $uid);
     *
     *     if ($id) {
     *         $data['dateDeleted'] = null;
     *         Craft::$app->db->createCommand()->update('{{%tablename}}', $data, [
     *             'id' => $id,
     *         ]);
     *     } else {
     *         $data['uid'] = $uid;
     *         Craft::$app->db->createCommand()->insert('{{%tablename}}', $data);
     *     }
     * });
     * ```
     *
     * @param string $path The config path pattern. Can contain `{uri}` tokens, which will be passed to the handler.
     * @param callable $handler The handler method.
     * @param mixed $data The data to be passed to the event handler when the event is triggered.
     * When the event handler is invoked, this data can be accessed via [[ConfigEvent::data]].
     */
    public function onAdd(string $path, $handler, $data = null)
    {
        $this->registerChangeEventHandler(self::EVENT_ADD_ITEM, $path, $handler, $data);
    }

    /**
     * Attaches an event handler for when an item is updated in the config at a given path.
     *
     * ---
     *
     * ```php
     * use craft\events\ConfigEvent;
     *
     * Craft::$app->projectConfig->onUpdate('foo.{uid}', function(ConfigEvent $event) {
     *     // Get the UID from the item path
     *     $uid = $event->tokenMatches[0];
     *
     *     // Update the item in the database
     *     $data = array_merge($event->newValue);
     *     Craft::$app->db->createCommand()->update('{{%tablename}}', $data, [
     *         'uid' => $uid,
     *     ]);
     * });
     * ```
     *
     * @param string $path The config path pattern. Can contain `{uri}` tokens, which will be passed to the handler.
     * @param callable $handler The handler method.
     * @param mixed $data The data to be passed to the event handler when the event is triggered.
     * When the event handler is invoked, this data can be accessed via [[ConfigEvent::data]].
     */
    public function onUpdate(string $path, $handler, $data = null)
    {
        $this->registerChangeEventHandler(self::EVENT_UPDATE_ITEM, $path, $handler, $data);
    }

    /**
     * Attaches an event handler for when an item is removed from the config at a given path.
     *
     * ---
     *
     * ```php
     * use craft\events\ConfigEvent;
     *
     * Craft::$app->projectConfig->onRemove('foo.{uid}', function(ConfigEvent $event) {
     *     // Get the UID from the item path
     *     $uid = $event->tokenMatches[0];
     *
     *     // Soft-delete the item from the database
     *     Craft::$app->db->createCommand()->softDelete('{{%tablename}}', [
     *         'uid' => $uid,
     *     ]);
     * });
     * ```
     *
     * @param string $path The config path pattern. Can contain `{uri}` tokens, which will be passed to the handler.
     * @param callable $handler The handler method.
     * @param mixed $data The data to be passed to the event handler when the event is triggered.
     * When the event handler is invoked, this data can be accessed via [[ConfigEvent::data]].
     */
    public function onRemove(string $path, $handler, $data = null)
    {
        $this->registerChangeEventHandler(self::EVENT_REMOVE_ITEM, $path, $handler, $data);
    }

    /**
     * Registers a config change event listener, for a specific config path pattern.
     *
     * @param string $event The event name
     * @param string $path The config path pattern. Can contain `{uid}` tokens, which will be passed to the handler.
     * @param callable $handler The handler method.
     * @param mixed $data The data to be passed to the event handler when the event is triggered.
     * When the event handler is invoked, this data can be accessed via [[ConfigEvent::data]].
     */
    public function registerChangeEventHandler(string $event, string $path, $handler, $data = null)
    {
        $pattern = '/^(?P<path>' . preg_quote($path, '/') . ')(?P<extra>\..+)?$/';
        $pattern = str_replace('\\{uid\\}', '(' . self::UID_PATTERN . ')', $pattern);

        $this->on($event, function(ConfigEvent $event) use ($pattern, $handler) {
            if (preg_match($pattern, $event->path, $matches)) {
                // Is this a nested path?
                if (isset($matches['extra'])) {
                    $this->processConfigChanges($matches['path']);
                    return;
                }

                // Chop off [0] (full match) and ['path'] & [1] (requested path)
                $event->tokenMatches = array_values(array_slice($matches, 3));
                $handler($event);
                $event->tokenMatches = null;
            }
        }, $data);
    }

    // Private methods
    // =========================================================================

    /**
     * Retrieve a a config file tree with modified times based on the main configuration file.
     *
     * @return array
     */
    private function _getConfigFileModifiedTimes(): array
    {
        $fileList = $this->_getConfigFileList();

        $output = [];

        clearstatcache();
        foreach ($fileList as $file) {
            $output[$file] = FileHelper::lastModifiedTime($file);
        }

        return $output;
    }

    /**
     * Generate the configuration based on the configuration files.
     *
     * @return array
     */
    private function _getConfigurationFromYaml(): array
    {
        if ($this->_useConfigFile()) {
            $fileList = $this->_getConfigFileList();
            $fileConfigs = [];
            foreach ($fileList as $file) {
                $fileConfigs[] = $this->_parseYamlFile($file);
            }
            $generatedConfig = array_merge(...$fileConfigs);
        } else {
            if (empty($this->_parsedConfigs[self::CONFIG_KEY])) {
                $this->_parsedConfigs[self::CONFIG_KEY] = $this->_getStoredConfig();
            }
            $generatedConfig = $this->_parsedConfigs[self::CONFIG_KEY];
        }

        return $generatedConfig;
    }

    /**
     * Return parsed YAML contents of a file, holding the data in cache.
     *
     * @param string $file
     * @return mixed
     */
    private function _parseYamlFile(string $file)
    {
        if (empty($this->_parsedConfigs[$file])) {
            $this->_parsedConfigs[$file] = file_exists($file) ? Yaml::parse(file_get_contents($file)) : [];
        }

        return $this->_parsedConfigs[$file];
    }

    /**
     * Map a new node to a yaml file.
     *
     * @param $node
     * @param $location
     * @throws ServerErrorHttpException
     */
    private function _mapNodeLocation($node, $location)
    {
        $this->_getStoredConfigMap();
        $this->_configMap[$node] = $location;
    }

    /**
     * Get the stored config map.
     *
     * @return array
     * @throws ServerErrorHttpException
     */
    private function _getStoredConfigMap(): array
    {
        if ($this->_configMap !== null) {
            return $this->_configMap;
        }

        return $this->_configMap = Json::decode(Craft::$app->getInfo()->configMap) ?? [];
    }

    /**
     * Get the stored config.
     *
     * @return array
     */
    private function _getStoredConfig(): array
    {
        if (empty($this->_storedConfig)) {
            $configData = Craft::$app->getInfo()->config;
            $this->_storedConfig = $configData ? unserialize($configData, ['allowed_classes' => false]) : [];
        }

        return $this->_storedConfig;
    }

    /**
     * Return a nested array for pending config changes
     *
     * @return array
     */
    private function _getPendingChanges(): array
    {
        $newItems = [];
        $changedItems = [];

        $configData = $this->_getConfigurationFromYaml();
        $currentConfig = $this->_getStoredConfig();

        $flatConfig = [];
        $flatCurrent = [];

        unset($configData['dateModified'], $currentConfig['dateModified'], $configData['imports'], $currentConfig['imports']);

        // flatten both configs so we can compare them.

        $flatten = function($array, $path, &$result) use (&$flatten) {
            foreach ($array as $key => $value) {
                $thisPath = ltrim($path . '.' . $key, '.');

                if (is_array($value)) {
                    $flatten($value, $thisPath, $result);
                } else {
                    $result[$thisPath] = $value;
                }
            }
        };

        $flatten($configData, '', $flatConfig);
        $flatten($currentConfig, '', $flatCurrent);

        // Compare and if something is different, mark the immediate parent as changed.
        foreach ($flatConfig as $key => $value) {
            // Drop the last part of path
            $immediateParent = pathinfo($key, PATHINFO_FILENAME);

            if (!array_key_exists($key, $flatCurrent)) {
                $newItems[] = $immediateParent;
            } elseif ($flatCurrent[$key] !== $value) {
                $changedItems[] = $immediateParent;
            }

            unset($flatCurrent[$key]);
        }

        $removedItems = array_keys($flatCurrent);

        foreach ($removedItems as &$removedItem) {
            // Drop the last part of path
            $removedItem = pathinfo($removedItem, PATHINFO_FILENAME);
        }

        // Sort by number of dots to ensure deepest paths listed first
        $sorter = function($a, $b) {
            $aDepth = substr_count($a, '.');
            $bDepth = substr_count($b, '.');

            if ($aDepth === $bDepth) {
                return 0;
            }

            return $aDepth > $bDepth ? -1 : 1;
        };

        $newItems = array_unique($newItems);
        $removedItems = array_unique($removedItems);
        $changedItems = array_unique($changedItems);

        uasort($newItems, $sorter);
        uasort($removedItems, $sorter);
        uasort($changedItems, $sorter);

        return compact('newItems', 'removedItems', 'changedItems');
    }

    /**
     * Generate the configuration mapping data from configuration files.
     *
     * @return array
     */
    private function _generateConfigMap(): array
    {
        $fileList = $this->_getConfigFileList();
        $nodes = [];

        foreach ($fileList as $file) {
            $config = $this->_parseYamlFile($file);

            // Take record of top nodes
            $topNodes = array_keys($config);
            foreach ($topNodes as $topNode) {
                $nodes[$topNode] = $file;
            }
        }

        unset($nodes['imports']);
        return $nodes;
    }

    /**
     * Return true if any of the config files have been modified since last we checked.
     *
     * @return bool
     */
    private function _areConfigFilesModified(): bool
    {
        $cachedModifiedTimes = Craft::$app->getCache()->get(self::CACHE_KEY);

        if (!is_array($cachedModifiedTimes) || empty($cachedModifiedTimes)) {
            return true;
        }

        foreach ($cachedModifiedTimes as $file => $modified) {
            if (!file_exists($file) || FileHelper::lastModifiedTime($file) > $modified) {
                return true;
            }
        }

        // Re-cache
        Craft::$app->getCache()->set(self::CACHE_KEY, $cachedModifiedTimes, self::CACHE_DURATION);

        return false;
    }

    /**
     * Load the config file and figure out all the files imported and used.
     *
     * @return array
     */
    private function _getConfigFileList(): array
    {
        if (!empty($this->_configFileList)) {
            return $this->_configFileList;
        }

        $basePath = Craft::$app->getPath()->getConfigPath();
        $baseFile = $basePath . DIRECTORY_SEPARATOR . self::CONFIG_FILENAME;

        $traverseFile = function($filePath) use (&$traverseFile) {
            $fileList = [$filePath];
            $config = $this->_parseYamlFile($filePath);
            $fileDir = pathinfo($filePath, PATHINFO_DIRNAME);

            if (isset($config['imports'])) {
                foreach ($config['imports'] as $file) {
                    if (PathHelper::ensurePathIsContained($file)) {
                        $fileList = array_merge($fileList, $traverseFile($fileDir . DIRECTORY_SEPARATOR . $file));
                    }
                }
            }

            return $fileList;
        };

        return $this->_configFileList = $traverseFile($baseFile);
    }

    /**
     * Save configuration data to a path.
     *
     * @param array $data
     * @param string|null $path
     * @throws ErrorException
     */
    private function _saveConfig(array $data, string $path = null)
    {
        if ($this->_useConfigFile() && $path) {
            $this->_parsedConfigs[$path] = $data;
            $this->_modifiedYamlFiles[$path] = true;
        } else {
            $this->_parsedConfigs[self::CONFIG_KEY] = $data;
        }
    }

    /**
     * Whether to use the config file or not.
     *
     * @return bool
     */
    private function _useConfigFile(): bool
    {
        if ($this->_useConfigFile !== null) {
            return $this->_useConfigFile;
        }

        return $this->_useConfigFile = Craft::$app->getConfig()->getGeneral()->useProjectConfigFile;
    }

    /**
     * Traverse a nested data array according to path and perform an action depending on parameters.
     *
     * @param array $data A nested array of data to traverse
     * @param array|string $path Path used to traverse the array. Either an array or a dot.based.path
     * @param mixed $value Value to set at the destination. If null, will return the value, unless deleting
     * @param bool $delete Whether to delete the value at the destination or not.
     * @return mixed|null
     */
    private function _traverseDataArray(array &$data, $path, $value = null, $delete = false)
    {
        if (is_string($path)) {
            $path = explode('.', $path);
        }

        $nextSegment = array_shift($path);

        // Last piece?
        if (count($path) === 0) {
            if ($delete) {
                unset($data[$nextSegment]);
            } else if ($value === null) {
                return $data[$nextSegment] ?? null;
            } else {
                $data[$nextSegment] = $value;
            }
        } else {
            if (!isset($data[$nextSegment])) {
                // If the path doesn't exist, it's fine if we wanted to delete or read
                if ($delete || $value === null) {
                    return null;
                }

                $data[$nextSegment] = [];
            }

            return $this->_traverseDataArray($data[$nextSegment], $path, $value, $delete);
        }
    }
}