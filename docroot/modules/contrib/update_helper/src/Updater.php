<?php

namespace Drupal\update_helper;

use Drupal\Component\Utility\NestedArray;
use Drupal\config_update\ConfigRevertInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Extension\MissingDependencyException;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\update_helper\Events\ConfigurationUpdateEvent;
use Drupal\update_helper\Events\UpdateHelperEvents;
use Drupal\Component\Utility\DiffArray;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Drupal\Core\Extension\ThemeInstallerInterface;

/**
 * Helper class to update configuration.
 */
class Updater implements UpdaterInterface {

  use StringTranslationTrait;

  const CONFIG_NOT_FOUND = 0;
  const CONFIG_ALREADY_APPLIED = 1;
  const CONFIG_NOT_EXPECTED = 2;
  const CONFIG_APPLIED_SUCCESSFULLY = 3;
  const MODULES_FOUND = 4;
  const MODULES_NOT_FOUND = 5;

  /**
   * Site configFactory object.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Module installer service.
   *
   * @var \Drupal\Core\Extension\ModuleInstallerInterface
   */
  protected $moduleInstaller;

  /**
   * The theme installer service.
   *
   * @var \Drupal\Core\Extension\ThemeInstallerInterface
   */
  protected $themeInstaller;

  /**
   * Config reverter service.
   *
   * @var \Drupal\config_update\ConfigRevertInterface
   */
  protected $configReverter;

  /**
   * Configuration handler service.
   *
   * @var \Drupal\update_helper\ConfigHandler
   */
  protected $configHandler;

  /**
   * Logger service.
   *
   * Note: Instead of using this service directly, use provided wrappers for it:
   * - logWarning - for logging warnings, it will mark executed update as failed
   * - logInfo    - for logging information.
   *
   * @var \Drupal\update_helper\UpdateLogger
   */
  protected $logger;

  /**
   * Event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * Config manager.
   *
   * @var \Drupal\Core\Config\ConfigManagerInterface
   */
  protected $configManager;

  /**
   * Constructs the PathBasedBreadcrumbBuilder.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Config factory service.
   * @param \Drupal\Core\Extension\ModuleInstallerInterface $module_installer
   *   Module installer service.
   * @param \Drupal\Core\Extension\ThemeInstallerInterface $theme_installer
   *   Theme installer service.
   * @param \Drupal\config_update\ConfigRevertInterface $config_reverter
   *   Config reverter service.
   * @param \Drupal\update_helper\ConfigHandler $config_handler
   *   Configuration handler service.
   * @param \Drupal\update_helper\UpdateLogger $logger
   *   Update logger.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   Event dispatcher.
   * @param \Drupal\Core\Config\ConfigManagerInterface $config_manager
   *   The config manager.
   */
  public function __construct(ConfigFactoryInterface $config_factory, ModuleInstallerInterface $module_installer, ThemeInstallerInterface $theme_installer, ConfigRevertInterface $config_reverter, ConfigHandler $config_handler, UpdateLogger $logger, EventDispatcherInterface $event_dispatcher, ConfigManagerInterface $config_manager) {
    $this->configFactory = $config_factory;
    $this->moduleInstaller = $module_installer;
    $this->themeInstaller = $theme_installer;
    $this->configReverter = $config_reverter;
    $this->configHandler = $config_handler;
    $this->logger = $logger;
    $this->eventDispatcher = $event_dispatcher;
    $this->configManager = $config_manager;
  }

  /**
   * Keeps the record of total warnings occurred during update execution.
   *
   * @var int
   */
  protected $warningCount = 0;

  /**
   * {@inheritdoc}
   */
  public function logger() {
    return $this->logger;
  }

  /**
   * Log warning message with internal count for reporting update failures.
   *
   * @param string $message
   *   The message used for logging of warning.
   */
  protected function logWarning($message) {
    $this->warningCount++;

    $this->logger->warning($message);
  }

  /**
   * Log information message, that will be displayed on update execution.
   *
   * @param string $message
   *   The message used for logging of info.
   */
  protected function logInfo($message) {
    $this->logger->info($message);
  }

  /**
   * {@inheritdoc}
   */
  public function executeUpdate($module, $update_definition_name, $force = FALSE) {
    $this->warningCount = 0;

    $update_definitions = $this->configHandler->loadUpdate($module, $update_definition_name);

    if (isset($update_definitions[UpdateDefinitionInterface::GLOBAL_CONDITIONS])) {
      if (isset($update_definitions[UpdateDefinitionInterface::GLOBAL_CONDITIONS][UpdateDefinitionInterface::GLOBAL_CONDITION_EXPECTED_MODULES])) {
        $result = $this->checkExpectedModulesArray($update_definitions[UpdateDefinitionInterface::GLOBAL_CONDITIONS][UpdateDefinitionInterface::GLOBAL_CONDITION_EXPECTED_MODULES]);
        if (!empty($result)) {
          return $this->logWarning($this->t('The following module(s) "@neededModules" are required for update @updateName.',
            [
              '@neededModules' => implode(", ", $result),
              '@updateName' => $update_definition_name,
            ]));
        }
      }
      unset($update_definitions[UpdateDefinitionInterface::GLOBAL_CONDITIONS]);
    }

    if (isset($update_definitions[UpdateDefinitionInterface::GLOBAL_ACTIONS])) {
      $this->executeGlobalActions($update_definitions[UpdateDefinitionInterface::GLOBAL_ACTIONS]);

      unset($update_definitions[UpdateDefinitionInterface::GLOBAL_ACTIONS]);
    }

    if (!empty($update_definitions)) {
      $this->executeConfigurationActions($update_definitions);
    }

    // Dispatch event after update has finished.
    $event = new ConfigurationUpdateEvent($module, $update_definition_name, $this->warningCount);
    $this->eventDispatcher->dispatch($event, UpdateHelperEvents::CONFIGURATION_UPDATE);

    return $this->warningCount === 0;
  }

  /**
   * Check update status of configuration from update definitions.
   *
   * @param string $module
   *   Module name where update definition is saved.
   * @param string $update_definition_name
   *   Update definition name. Usually same name as update hook.
   *
   * @return bool
   *   Returns update status.
   */
  public function checkUpdate($module, $update_definition_name) {
    $this->warningCount = 0;
    $moduleHandler = \Drupal::service('module_handler');
    $modulesInstalled = [];
    $update_definitions = $this->configHandler->loadUpdate($module, $update_definition_name);

    if (isset($update_definitions[UpdateDefinitionInterface::GLOBAL_CONDITIONS])) {
      if (isset($update_definitions[UpdateDefinitionInterface::GLOBAL_CONDITIONS][UpdateDefinitionInterface::GLOBAL_CONDITION_EXPECTED_MODULES])) {
        $result = $this->checkExpectedModulesArray($update_definitions[UpdateDefinitionInterface::GLOBAL_CONDITIONS][UpdateDefinitionInterface::GLOBAL_CONDITION_EXPECTED_MODULES]);
        if (!empty($result)) {
          return Updater::MODULES_NOT_FOUND;
        }
      }
      unset($update_definitions[UpdateDefinitionInterface::GLOBAL_CONDITIONS]);
    }

    if (isset($update_definitions[UpdateDefinitionInterface::GLOBAL_ACTIONS])) {
      if (isset($update_definitions[UpdateDefinitionInterface::GLOBAL_ACTIONS][UpdateDefinitionInterface::GLOBAL_ACTION_INSTALL_MODULES])) {
        $modules = $update_definitions[UpdateDefinitionInterface::GLOBAL_ACTIONS][UpdateDefinitionInterface::GLOBAL_ACTION_INSTALL_MODULES];
        foreach ($modules as $module) {
          if (!$moduleHandler->moduleExists($module)) {
            return Updater::MODULES_NOT_FOUND;
          }
          $modulesInstalled[] = $module;
        }
      }
      unset($update_definitions[UpdateDefinitionInterface::GLOBAL_ACTIONS]);
    }

    if (!empty($update_definitions)) {
      return $this->executeConfigurationActions($update_definitions, FALSE, TRUE);
    }

    if (empty($update_definitions) && !empty($modulesInstalled)) {
      return Updater::MODULES_FOUND;
    }

    return Updater::CONFIG_NOT_FOUND;
  }

  /**
   * Get array with defined global actions.
   *
   * Global actions can be:
   * - install_modules: list of modules to install
   * - install_themes: list of themes to install
   * - import_configs: list of configurations to import.
   *
   * @param array $global_actions
   *   Array with list of global actions.
   */
  protected function executeGlobalActions(array $global_actions) {
    if (isset($global_actions[UpdateDefinitionInterface::GLOBAL_ACTION_INSTALL_MODULES])) {
      $this->installModules($global_actions[UpdateDefinitionInterface::GLOBAL_ACTION_INSTALL_MODULES]);
    }

    if (isset($global_actions[UpdateDefinitionInterface::GLOBAL_ACTION_INSTALL_THEMES])) {
      $this->installThemes($global_actions[UpdateDefinitionInterface::GLOBAL_ACTION_INSTALL_THEMES]);
    }

    if (isset($global_actions[UpdateDefinitionInterface::GLOBAL_ACTION_IMPORT_CONFIGS])) {
      $this->importConfigs($global_actions[UpdateDefinitionInterface::GLOBAL_ACTION_IMPORT_CONFIGS]);
    }
  }

    /**
   * Check if modules are enabled and installed.
   *
   * @param string $module
   *   Module name where update definition is saved.
   * @param string $update_definition_name
   *   Update definition name. Usually same name as update hook.
   *
   * @return array
   *   Returns array needed modules.
   */
  public function checkExpectedModules($module, $update_definition_name) {
    $update_definitions = $this->configHandler->loadUpdate($module, $update_definition_name);
    if (isset($update_definitions[UpdateDefinitionInterface::GLOBAL_CONDITIONS])) {
      if (isset($update_definitions[UpdateDefinitionInterface::GLOBAL_CONDITIONS][UpdateDefinitionInterface::GLOBAL_CONDITION_EXPECTED_MODULES])) {
        return $this->checkExpectedModulesArray($update_definitions[UpdateDefinitionInterface::GLOBAL_CONDITIONS][UpdateDefinitionInterface::GLOBAL_CONDITION_EXPECTED_MODULES]);
      }
    }
    return [];
  }

  /**
   * Check if modules are enabled and installed.
   *
   * @param array $expected_modules
   *   Array with list of expected modules.
   *
   * @return array
   *   Returns array needed modules.
   */
  public function checkExpectedModulesArray(array $expected_modules) {
    $needed_modules = [];
    $moduleHandler = \Drupal::service('module_handler');
    foreach ($expected_modules as $expected_module) {
      if (!$moduleHandler->moduleExists($expected_module)) {
        $needed_modules[] = $expected_module;
      }
    }
    return $needed_modules;
  }


  /**
   * Execute configuration update definitions for configurations.
   *
   * @param array $update_definitions
   *   List of configurations with update definitions for them.
   * @param bool $force
   *   Force the update.
   * @param bool $checkOnly
   *   Check the update status and don't apply the update.
   *
   * @return bool
   *   Returns update status if checkOnly flag is set.
   */
  protected function executeConfigurationActions(array $update_definitions, $force = FALSE, $checkOnly = FALSE) {
    foreach ($update_definitions as $configName => $configChange) {
      $result = $this->updateConfig($configName, $configChange['update_actions'], $configChange['expected_config'], $force, $checkOnly);

      if ($checkOnly) {
        return $result;
      }

      switch ($result) {
        case Updater::CONFIG_APPLIED_SUCCESSFULLY:
          $this->logInfo($this->t('Configuration @configName has been successfully updated.', ['@configName' => $configName]));
          break;

        case Updater::CONFIG_ALREADY_APPLIED:
          $this->logWarning($this->t('Configuration @configName is already updated.', ['@configName' => $configName]));
          break;

        case Updater::CONFIG_NOT_EXPECTED:
          $this->logWarning($this->t('Expected current configuration for @configName is not matching. Unable to apply new config.', ['@configName' => $configName]));
          break;

        case Updater::CONFIG_NOT_FOUND:
          $this->logWarning($this->t('Unable to find config @configName. Skipping update.', ['@configName' => $configName]));
          break;
      }
    }
  }

  /**
   * Apply configuration changes on configuration array.
   *
   * Order is following:
   * 1. Delete
   *   - this options is first, in that way, it's possible to delete bigger
   *     configuration block before adding new one.
   * 2. Add
   *   - add is seconds, in that way we can add whole block deleted before.
   * 3. Change
   *   - change is last, because we want to apply smaller modifications after
   *     all bigger changes are done.
   *
   * @param array $base_config
   *   The base configuration.
   * @param array $update_actions
   *   The configuration update actions.
   *
   * @return array
   *   Returns modified configuration.
   */
  protected function applyConfigActions(array $base_config, array $update_actions): array {
    // 1. Define configuration keys that should be deleted.
    if (isset($update_actions['delete'])) {
      $delete_keys = $this->getFlatKeys($update_actions['delete']);

      foreach ($delete_keys as $key_path) {
        NestedArray::unsetValue($base_config, $key_path);
      }
    }

    // 2. Add configuration that is added.
    if (isset($update_actions['add'])) {
      $base_config = NestedArray::mergeDeep($base_config, $update_actions['add']);
    }

    // 3. Add configuration that is changed.
    if (isset($update_actions['change'])) {
      $base_config = NestedArray::mergeDeep($base_config, $update_actions['change']);
    }

    return $base_config;
  }

  /**
   * Installs modules.
   *
   * @param array $modules
   *   List of module names.
   */
  protected function installModules(array $modules) {
    foreach ($modules as $module) {
      try {
        if ($this->moduleInstaller->install([$module])) {
          $this->logInfo($this->t('Module @module is successfully enabled.', ['@module' => $module]));
        }
        else {
          $this->logWarning($this->t('Unable to enable @module.', ['@module' => $module]));
        }
      }
      catch (MissingDependencyException $e) {
        $this->logWarning($this->t('Unable to enable @module because of missing dependencies.', ['@module' => $module]));
      }
    }
  }

  /**
   * Installs themes.
   *
   * @param array $themes
   *   List of theme names.
   */
  protected function installThemes(array $themes): void {
    foreach ($themes as $theme) {
      try {
        if ($this->themeInstaller->install([$theme])) {
          $this->logInfo($this->t('Theme @theme is successfully enabled.', ['@theme' => $theme]));
        }
        else {
          $this->logWarning($this->t('Unable to enable @theme.', ['@theme' => $theme]));
        }
      }
      catch (MissingDependencyException $e) {
        $this->logWarning($this->t('Unable to enable @theme because of missing dependencies.', ['@theme' => $theme]));
      }
    }
  }

  /**
   * Imports configurations.
   *
   * @param array $config_list
   *   List of full configuration names.
   */
  protected function importConfigs(array $config_list) {
    // Import configurations.
    foreach ($config_list as $full_config_name) {
      $config_name = ConfigName::createByFullName($full_config_name);

      if (!empty($this->configFactory->get($full_config_name)->getRawData())) {
        $this->logWarning($this->t('Importing of @full_name config will be skipped, because configuration already exists.', [
          '@full_name' => $full_config_name,
        ]));

        continue;
      }

      if (!$this->configReverter->import($config_name->getType(), $config_name->getName())) {
        $this->logWarning($this->t('Unable to import @full_name config, because configuration file is not found.', [
          '@full_name' => $full_config_name,
        ]));

        continue;
      }

      $this->logInfo($this->t('Configuration @full_name has been successfully imported.', [
        '@full_name' => $full_config_name,
      ]));
    }
  }

  /**
   * Get flatten array keys as list of paths.
   *
   * Example:
   *   $nestedArray = [
   *      'a' => [
   *          'b' => [
   *              'c' => 'c1',
   *          ],
   *          'bb' => 'bb1'
   *      ],
   *      'aa' => 'aa1'
   *   ]
   *
   * Result: [
   *   ['a', 'b', 'c'],
   *   ['a', 'bb']
   *   ['aa']
   * ]
   *
   * @param array $nested_array
   *   Array with nested keys.
   *
   * @return array
   *   List of flattened keys.
   */
  protected function getFlatKeys(array $nested_array) {
    $keys = [];
    foreach ($nested_array as $key => $value) {
      if (is_array($value) && !empty($value)) {
        $list_of_sub_keys = $this->getFlatKeys($value);

        foreach ($list_of_sub_keys as $subKeys) {
          $keys[] = array_merge([$key], $subKeys);
        }
      }
      else {
        $keys[] = [$key];
      }
    }

    return $keys;
  }

  /**
   * Update configuration.
   *
   * It's possible to provide expected configuration that should be checked,
   * before new configuration is applied in order to ensure existing
   * configuration is expected one.
   *
   * @param string $config_name
   *   Configuration name that should be updated.
   * @param array $update_actions
   *   Configuration update actions.
   * @param array $expected_configuration
   *   Only if current config is same like old config we are updating.
   *    * @param bool $force
   *   Force the update.
   * @param bool $checkOnly
   *   Check the update status and don't apply the update.
   *
   * @return bool
   *   Returns TRUE if update of configuration was successful.
   */
  protected function updateConfig($config_name, array $update_actions, array $expected_configuration = [], $force = FALSE, $checkOnly = FALSE) {
    $config = $this->configFactory->getEditable($config_name);

    $config_data = $config->get();

    // Reset expected_config in case of force flag.
    if ($force) {
      $expected_configuration = [];
    }

    // Check that configuration exists before executing update.
    if (empty($config_data)) {
      return Updater::CONFIG_NOT_FOUND;
    }

    // Apply configuration update actions.
    $update_config_data = $this->applyConfigActions($config_data, $update_actions);

    // Check if configuration is already in new state.
    if (!$force
      && empty(DiffArray::diffAssocRecursive($config_data, $update_config_data))
      && empty(DiffArray::diffAssocRecursive($update_config_data, $config_data))) {
        return Updater::CONFIG_ALREADY_APPLIED;
    }

    if (empty($expected_configuration) || !DiffArray::diffAssocRecursive($expected_configuration, $config_data)) {
      if ($checkOnly) {
        return Updater::CONFIG_APPLIED_SUCCESSFULLY;
      }

      $config->setData($update_config_data);
      $config->save();

      return Updater::CONFIG_APPLIED_SUCCESSFULLY;
    }
    else {
      return Updater::CONFIG_NOT_EXPECTED;
    }

    // Update configuration entities using their API to ensure dependencies are
    // recalculated.
    if ($entity_type = $this->configManager->getEntityTypeIdByName($config_name)) {
      /** @var \Drupal\Core\Config\Entity\ConfigEntityStorageInterface $entity_storage */
      $entity_storage = $this->configManager
        ->getEntityTypeManager()
        ->getStorage($entity_type);
      $id = $entity_storage->getIDFromConfigName($config_name, $entity_storage->getEntityType()->getConfigPrefix());
      $entity = $entity_storage->load($id);
      $entity = $entity_storage->updateFromStorageRecord($entity, $update_config_data);
      $entity->save();
    }
    else {
      $config->setData($update_config_data);
      $config->save();
    }

    return TRUE;
  }

}
