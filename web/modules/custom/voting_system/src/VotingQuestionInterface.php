<?php

declare(strict_types=1);

namespace Drupal\voting_system;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface defining a voting question entity type.
 */
interface VotingQuestionInterface extends ContentEntityInterface, EntityOwnerInterface, EntityChangedInterface {

}
