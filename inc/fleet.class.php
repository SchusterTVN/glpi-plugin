<?php
/**
 * LICENSE
 *
 * Copyright © 2016-2018 Teclib'
 * Copyright © 2010-2018 by the FusionInventory Development Team.
 *
 * This file is part of Flyve MDM Plugin for GLPI.
 *
 * Flyve MDM Plugin for GLPI is a subproject of Flyve MDM. Flyve MDM is a mobile
 * device management software.
 *
 * Flyve MDM Plugin for GLPI is free software: you can redistribute it and/or
 * modify it under the terms of the GNU Affero General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * Flyve MDM Plugin for GLPI is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 * You should have received a copy of the GNU Affero General Public License
 * along with Flyve MDM Plugin for GLPI. If not, see http://www.gnu.org/licenses/.
 * ------------------------------------------------------------------------------
 * @author    Thierry Bugier
 * @copyright Copyright © 2018 Teclib
 * @license   AGPLv3+ http://www.gnu.org/licenses/agpl.txt
 * @link      https://github.com/flyve-mdm/glpi-plugin
 * @link      https://flyve-mdm.com/
 * ------------------------------------------------------------------------------
 */

use GlpiPlugin\Flyvemdm\Exception\TaskPublishPolicyPolicyNotFoundException;

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

/**
 * @since 0.1.0
 */
class PluginFlyvemdmFleet extends CommonDBTM implements PluginFlyvemdmNotifiableInterface {

   /**
    * @var string $rightname name of the right in DB
    */
   static $rightname = 'flyvemdm:fleet';

   /**
    * @var bool $dohistory maintain history
    */
   public $dohistory = true;

   /**
    * @var bool $usenotepad enable notepad for the itemtype (GLPi < 0.85)
    */
   protected $usenotepad               = true;

   /**
    * @var bool $usenotepad enable notepad for the itemtype (GLPi >=0.85)
    */
   protected $usenotepadRights         = true;


   protected $deleteDefaultFleet       =  false;

   static $types = [
      'Phone'
   ];

   /**
    * Localized name of the type
    * @param integer $nb number of item in the type (default 0)
    * @return string
    */
   public static function getTypeName($nb = 0) {
      return _n('Fleet', 'Fleets', $nb, 'flyvemdm');
   }

   /**
    * Returns the picture file for the menu
    * @return string the menu picture
    */
   public static function getMenuPicture() {
      return 'fa-group';
   }

   /**
    * @see CommonGLPI::defineTabs()
    */
   public function defineTabs($options = []) {
      $tab = [];
      $this->addDefaultFormTab($tab);
      if (!$this->isNewItem()) {
         $this->addStandardTab(PluginFlyvemdmAgent::class, $tab, $options);
         if ($this->fields['is_default'] == '0') {
            $this->addStandardTab(PluginFlyvemdmTask::class, $tab, $options);
            $this->addStandardTab(PluginFlyvemdmTaskstatus::class, $tab, $options);
         }
         $this->addStandardTab(Notepad::class, $tab, $options);
         $this->addStandardTab(Log::class, $tab, $options);
      } else {
         $tab[1]  = __s('Main');
      }

      return $tab;
   }

   function canPurgeItem() {
      if ($this->fields['is_default'] == '1') {
         return false;
      }
      return parent::checkEntity();
   }

   /**
    * Show form for edition
    * @param $ID
    * @param array $options
    */
   public function showForm($ID, array $options = []) {
      $DbUtil = new DbUtils();
      $this->initForm($ID, $options);
      $this->showFormHeader($options);

      $twig = plugin_flyvemdm_getTemplateEngine();
      $fields              = $this->fields;
      $objectName          = $DbUtil->autoName($this->fields["name"], "name",
            (isset($options['withtemplate']) && $options['withtemplate'] == 2),
            $this->getType(), -1);
      $fields['name']      = Html::autocompletionTextField($this, 'name',
                             ['value' => $objectName, 'display' => false]);
      $fields['is_default'] = $fields['is_default'] ? __('No') : __('Yes');
      $data = [
            'withTemplate' => (isset($options['withtemplate']) && $options['withtemplate'] ? "*" : ""),
            'fleet'        => $fields,
      ];

      echo $twig->render('fleet.html.twig', $data);

      $this->showFormButtons($options);
   }

   /**
    * @see CommonDBTM::prepareInputForAdd()
    */
   public function prepareInputForAdd($input) {
      if (!isset($input['is_default'])) {
         $input['is_default'] = '0';
      }

      if (!isset($input['entities_id'])) {
         $input['entities_id'] = $_SESSION['glpiactive_entity'];
      }

      return $input;
   }

   /**
    * @see CommonDBTM::prepareInputForUpdate()
    */
   public function prepareInputForUpdate($input) {
      unset($input['is_default']);
      if ($this->fields['is_default'] == '1'
          && isset($input['is_recursive'])
          && $this->fields['is_recursive'] != $input['is_recursive']) {
         // Do not change recursivity of default fleet
         unset($input['is_recursive']);
      }

      return $input;
   }

   /**
    * Actions done before the DELETE of the item in the database /
    * Maybe used to add another check for deletion
    * @return bool : true if item need to be deleted else false
    */
   public function pre_deleteItem() {
      // move agents in the fleet into the default one
      $fleetId = $this->getID();
      $agent = new PluginFlyvemdmAgent();
      $entityId = $this->fields['entities_id'];
      $defaultFleet = self::getDefaultFleet($entityId);
      $agents = $this->getAgents();
      if ($defaultFleet === null && count($agents) > 0) {
         if (!$this->deleteDefaultFleet) {
            // No default fleet
            // TODO : Create it again ?
            Session::addMessageAfterRedirect(__('No default fleet found to move devices', 'flyvemdm'));
            return false;
         }
      }

      foreach ($agents as $agent) {
         if (!$agent->update([
               'id'                          => $agent->getID(),
               'plugin_flyvemdm_fleets_id'   => $defaultFleet->getID()
         ])) {
            Session::addMessageAfterRedirect(__('Could not move all devices to the not managed fleet', 'flyvemdm'));
            return false;
         }
      }

      // Delete policies on the fleet
      $itemtype = $this->getType();
      $fleetId = $this->getID();
      $task = new PluginFlyvemdmTask();
      $rows = $task->find("`itemtype_applied` = '$itemtype' AND `items_id_applied` = '$fleetId'");

      // Disable replacement of a task by an other for app or file deployment
      // TODO : needs a better implementation relying on instances of PolicyInterface
      foreach ($rows as $row) {
         $decodedValue = json_decode($row['value'], JSON_OBJECT_AS_ARRAY);
         if (isset($decodedValue['remove_on_delete']) && $decodedValue['remove_on_delete'] != '0') {
            $decodedValue['remove_on_delete'] = '0';
            $row['value'] = $decodedValue;
            $task->update($row);
         }
      }

      $deleteSuccess = $task->deleteByCriteria([
         'AND' => [
            'itemtype_applied'  => $itemtype,
            'items_id_applied' => $fleetId,
         ]
      ], true);
      if (!$deleteSuccess) {
         Session::addMessageAfterRedirect(__('Could not delete policies on the fleet', 'flyvemdm'));
         return false;
      }

      return true;
   }

   /**
    * @return array
    */
   public function getSearchOptionsNew() {
      $tab = parent::getSearchOptionsNew();

      $tab[] = [
         'id'                 => '2',
         'table'              => $this->getTable(),
         'field'              => 'id',
         'name'               => __('ID'),
         'massiveaction'      => false,
         'datatype'           => 'number'
      ];

      $tab[] = [
         'id'                 => '3',
         'table'              => PluginFlyvemdmPolicy::getTable(),
         'field'              => 'name',
         'name'               => __('Applied policy', 'flyvemdm'),
         'datatype'           => 'dropdown',
         'comments'           => '1',
         'nosort'             => true,
         'joinparams'         => [
            'beforejoin'         => [
               'table'           => PluginFlyvemdmTask::getTable(),
               'joinparams'      => [
                  'jointype'     => 'child',
                  'linkfield'    => 'items_id_applied',
                  'condition'    => "AND NEWTABLE.`itemtype_applied`='" . PluginFlyvemdmFleet::class . "'",
               ],
            ],
            'jointype'           => 'empty',
         ],
         'massiveaction'      => false
      ];

      $tab[] = [
         'id'                 => '5',
         'table'              => $this->getTable(),
         'field'              => 'is_default',
         'name'               => __('Not managed'),
         'datatype'           => 'bool',
         'massiveaction'      => false
      ];

      return $tab;
   }

   /**
    *
    * @see PluginFlyvemdmNotifiableInterface::getTopic()
    */
   public function getTopic() {
      if (!isset($this->fields['id'])) {
         return null;
      }

      return $this->fields['entities_id'] . '/fleet/' . $this->fields['id'];
   }

   /**
    * Actions done after the ADD of the item in the database
    */
   public function post_addItem() {
      // Generate default policies for groups of policies
      $policy = new PluginFlyvemdmPolicy();
      foreach ($policy->find() as $row) {
         $policyName = $row['symbol'];
         $topic = $this->getTopic();
         $this->notify("$topic/Policy/$policyName", null, 0, 1);
      }
   }

   /**
    *
    * @see CommonDBTM::post_deleteItem()
    */
   public function post_deleteItem() {
      // unlink agents
      $this->post_purgeItem();
   }

   /**
    *
    * @see CommonDBTM::post_purgeItem()
    */
   public function post_purgeItem() {
      global $DB;

      // now the fleet is empty, delete MQTT topcis
      $groups = [];
      $result = false;
      $table_policy = PluginFlyvemdmPolicy::getTable();
      $query = "SELECT DISTINCT `group` FROM `$table_policy`";
      try {
         $result = $DB->query($query);
      } catch (GlpitestSQLError $e) {
         Toolbox::logInFile('php-errors', 'plugin Flyve MDM: ' . $e->getMessage() . PHP_EOL);
      }
      if ($result) {
         while ($row = $DB->fetch_assoc($result)) {
            $groups[] = $row['group'];
         }
      }
      PluginFlyvemdmTask::cleanupPolicies($this, $groups);
   }

   /**
    * @see CommonDBTM::cleanDBonPurge()
    */
   public function cleanDBonPurge() {
      global $DB;

      // Unsuscribe all agents from the fleet
      $result = false;
      $fleetId = $this->getID();
      $agentTable = PluginFlyvemdmAgent::getTable();
      $query = "SELECT `id` FROM `$agentTable` WHERE `$agentTable`.`plugin_flyvemdm_fleets_id` = '$fleetId'";
      try {
         $result = $DB->query($query);
      } catch (GlpitestSQLError $e) {
         Toolbox::logInFile('php-errors', 'plugin Flyve MDM: ' . $e->getMessage() . PHP_EOL);
      }
      if ($result) {
         while ($row = $DB->fetch_assoc($result)) {
            $agent = new PluginFlyvemdmAgent();
            if ($agent->getFromDB($row['id'])) {
               $agent->unsubscribe();
            }
         }
      }

      // Force deletion regardless a file or application removal policy should take place
      $taskTable = PluginFlyvemdmTask::getTable();
      $itemtype = $this->getType();
      $itemId = $this->getID();
      $query = "DELETE FROM `$taskTable` WHERE `itemtype_applied` = '$itemtype' AND `items_id_applied` = '$itemId'";
      try {
         $DB->query($query);
      } catch (GlpitestSQLError $e) {
         Toolbox::logInFile('php-errors', 'plugin Flyve MDM: ' . $e->getMessage() . PHP_EOL);
      }
   }

   /**
    * Get all agents in the fleet
    *
    * @return array instances of agents belonging to the fleet
    */
   public function getAgents() {
      $id = $this->getID();
      if (! ($id > 0)) {
         return [];
      }
      $agents = [];
      $agent = new PluginFlyvemdmAgent();
      $rows = $agent->find("`plugin_flyvemdm_fleets_id`='$id'");

      foreach ($rows as $row) {
         $agent = new PluginFlyvemdmAgent();
         if ($agent->getFromDB($row['id'])) {
            $agents[] = $agent;
         }
      }

      return $agents;
   }

   /**
    * @see PluginFlyvemdmNotifiableInterface::getFleet()
    */
   public function getFleet() {
      if ($this->isNewItem()) {
         return null;
      }

      return $this;
   }

   /**
    * @return array
    */
   public function getPackages() {
      $packages = [];

      $itemtype = $this->getType();
      $fleetId = $this->getID();
      if ($fleetId > 0) {
         $task = new PluginFlyvemdmTask();
         $rows = $task->find("`itemtype_applied` = '$itemtype' AND `items_id_applied` = '$fleetId' AND `itemtype`='" . PluginFlyvemdmPackage::class . "'");
         foreach ($rows as $row) {
            $package = new PluginFlyvemdmPackage();
            $package->getFromDB($row['plugin_flyvemdm_packages_id']);
            $packages[] = $package;
         }
      }

      return $packages;
   }

   /**
    * @see PluginFlyvemdmNotifiableInterface::getFiles()
    */
   public function getFiles() {
      $files = [];

      $itemtype = $this->getType();
      $fleetId = $this->getID();
      if ($fleetId > 0) {
         $task = new PluginFlyvemdmTask();
         $rows = $task->find("`itemtype_applied` = '$itemtype' AND `items_id_applied`='$fleetId' AND `itemtype`='" . PluginFlyvemdmFile::class . "'");
         foreach ($rows as $row) {
            $file = new PluginFlyvemdmPackage();
            $file->getFromDB($row['plugin_flyvemdm_packages_id']);
            $files[] = $file;
         }
      }

      return $files;
   }

   /**
    * Gets the default fleet for an entity
    * @param string $entityId ID of the entity to search in
    * @return integer
    */
   public function getFromDBByDefaultForEntity($entityId = null) {
      if ($entityId === null) {
         $entityId = $_SESSION['glpiactive_entity'];
      }

      $rows = $this->find("`is_default`='1' AND `entities_id`='$entityId'", "`id` ASC");
      if (count($rows) < 1) {
         return $this->add([
            'is_default'  => '1',
            'name'        => __("not managed fleet", 'flyvemdm'),
            'entities_id' => $entityId,
         ]);
      }
      reset($rows);
      $this->getFromDB(current(array_keys($rows)));
      return $this->getID();
   }

   /**
    * Gets the default fleet for an entity
    * @param string $entityId ID of the entoty to search in
    * @return PluginFlyvemdmFleet|null
    */
   public static function getDefaultFleet($entityId = null) {
      if ($entityId === null) {
         $entityId = $_SESSION['glpiactive_entity'];
      }
      $defaultFleet = new PluginFlyvemdmFleet();
      $request = [
         'AND' => [
            'is_default' => '1',
            Entity::getForeignKeyField() => $entityId
         ]
      ];
      if (!$defaultFleet->getFromDBByCrit($request)) {
         return null;
      }
      return $defaultFleet;
   }

   /**
    *
    * @see PluginFlyvemdmNotifiableInterface::notify()
    * @param string $topic
    * @param string $mqttMessage
    * @param integer $qos
    * @param integer $retain
    */
   public function notify($topic, $mqttMessage, $qos = 0, $retain = 0) {
      $mqttClient = PluginFlyvemdmMqttclient::getInstance();
      $mqttClient->publish($topic, $mqttMessage, $qos, $retain);
   }

   /**
    * create folders and initial setup of the entity related to MDM
    * @param CommonDBTM $item
    */
   public function hook_entity_add(CommonDBTM $item) {
      if ($item instanceof Entity) {
         // Create the default fleet for a new entity
         $this->getFromDBByDefaultForEntity($item->getID());
      }
   }

   /**
    * delete fleets in the entity being purged
    * @param CommonDBTM $item
    */
   public function hook_entity_purge(CommonDBTM $item) {
      if ($item instanceof Entity) {
         $fleet = new static();
         $fleet->deleteDefaultFleet = true;
         $fleet->deleteByCriteria(['entities_id' => $item->getField('id')], 1);
      }
   }

   public function refreshPersistedNotifications() {
      global $DB;

      if ($this->isNewItem()) {
         return;
      }

      $task = new PluginFlyvemdmTask();
      $request = [
         'FROM' => $task::getTable(),
         'WHERE' => [$this::getForeignKeyField() => $this->getID()]
      ];
      foreach ($DB->request($request) as $row) {
         $task->getFromDB($row['id']);
         try {
            $task->publishPolicy($this);
         } catch (TaskPublishPolicyPolicyNotFoundException $exception) {
            Session::addMessageAfterRedirect(__($exception->getMessage(), 'flyvemdm'), true, ERROR);
         }
      }
   }

   /**
    * Is the fleet notifiable ?
    *
    * @return boolean
    */
   public function isNotifiable() {
      if ($this->isNewItem()) {
         return false;
      }

      return ($this->fields['is_default'] === '0');
   }
}
