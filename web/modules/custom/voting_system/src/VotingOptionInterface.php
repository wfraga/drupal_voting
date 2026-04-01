<?php

declare(strict_types=1);

namespace Drupal\voting_system;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface defining a voting option entity type.
 */
interface VotingOptionInterface extends ContentEntityInterface, EntityOwnerInterface, EntityChangedInterface {

}
