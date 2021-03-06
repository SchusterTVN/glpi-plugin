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
 * @author    Domingo Oropeza <doropeza@teclib.com>
 * @copyright Copyright © 2018 Teclib
 * @license   http://www.gnu.org/licenses/agpl.txt AGPLv3+
 * @link      https://github.com/flyve-mdm/glpi-plugin
 * @link      https://flyve-mdm.com/
 * ------------------------------------------------------------------------------
 */

namespace GlpiPlugin\Flyvemdm\Broker;

use GlpiPlugin\Flyvemdm\Interfaces\BrokerEnvelopeItemInterface;

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

final class BrokerEnvelope {

   private $items = [];
   private $message;

   /**
    * @param object $message
    * @param BrokerEnvelopeItemInterface[] $items
    */
   public function __construct($message, array $items = []) {
      $this->message = $message;
      foreach ($items as $item) {
         $this->items[\get_class($item)] = $item;
      }
   }

   /**
    * Wrap a message into an envelope if not already wrapped.
    *
    * @param BrokerEnvelope|object $message
    * @return object|BrokerEnvelope
    */
   public static function wrap($message) {
      return $message instanceof self ? $message : new self($message);
   }

   /**
    * new Envelope instance with additional item
    * @param BrokerEnvelopeItemInterface $item
    * @return BrokerEnvelope
    */
   public function with(BrokerEnvelopeItemInterface $item) {
      $cloned = clone $this;
      $cloned->items[\get_class($item)] = $item;
      return $cloned;
   }

   public function withMessage($message) {
      $cloned = clone $this;
      $cloned->message = $message;
      return $cloned;
   }

   public function get($itemFqcn) {
      return isset($this->items[$itemFqcn]) ? $this->items[$itemFqcn] : null;
   }

   /**
    * @return BrokerEnvelopeItemInterface[] indexed by fqcn
    */
   public function all() {
      return $this->items;
   }

   /**
    * @return object The original message contained in the envelope
    */
   public function getMessage() {
      return $this->message;
   }
}