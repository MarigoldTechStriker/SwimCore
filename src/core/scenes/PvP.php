<?php

namespace core\scenes;

use core\custom\prefabs\rod\FishingHook;
use core\SwimCore;
use core\systems\player\SwimPlayer;
use core\systems\scene\managers\BlocksManager;
use core\systems\scene\managers\DroppedItemManager;
use core\systems\scene\Scene;
use core\utils\ServerSounds;
use core\utils\StackTracer;
use pocketmine\entity\Entity;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\projectile\Arrow as ArrowEntity;
use pocketmine\entity\projectile\EnderPearl as PearlEntity;
use pocketmine\entity\projectile\Snowball as SnowballEntity;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityItemPickupEvent;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\event\entity\EntitySpawnEvent;
use pocketmine\event\entity\ProjectileLaunchEvent;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;

// this is a middle man class for doing common pvp mechanics a duel or FFA scene might need

abstract class PvP extends Scene
{

  public float $vertKB;
  public float $kb;
  public float $controllerVertKB;
  public float $controllerKB;
  public int $hitCoolDown; // in ticks
  public float $pearlKB;
  public float $snowballKB;
  public float $rodKB;
  public float $arrowKB;
  public float $pearlSpeed;
  public float $pearlGravity;

  public bool $naturalRegen;
  public bool $fallDamage;
  protected bool $voidDamage;

  public bool $tntBreaksBlocks = false;
  public bool $canThrowTnt = true;

  protected BlocksManager $blocksManager;
  protected DroppedItemManager $droppedItemManager;

  public function __construct(SwimCore $core, string $name)
  {
    parent::__construct($core, $name);

    $this->blocksManager = new BlocksManager($core, $this->world);
    $this->droppedItemManager = new DroppedItemManager();

    $this->vertKB = 0.4;
    $this->kb = 0.4;
    $this->controllerVertKB = 0.4;
    $this->controllerKB = 0.4;
    $this->hitCoolDown = 10;
    $this->pearlKB = 0.6;
    $this->snowballKB = 0.5;
    $this->rodKB = 0.35;
    $this->arrowKB = 0.5;
    $this->pearlSpeed = 2.5;
    $this->pearlGravity = 0.1;
    $this->naturalRegen = true;
    $this->fallDamage = false;
    $this->voidDamage = false; // most scenes will have custom bounding boxes set for playable region, anything outside no block placement or damage
  }

  public function updateSecond(): void
  {
    parent::updateSecond();
    $this->blocksManager->updateSecond();
  }

  public final function getBlockManager(): BlocksManager
  {
    return $this->blocksManager;
  }

  public final function getDroppedItemManager(): DroppedItemManager
  {
    return $this->droppedItemManager;
  }

  private const GRAVITY_UNIT_PER_TICK = 0.346;

  public function sceneEntityDamageEvent(EntityDamageEvent $event, SwimPlayer $swimPlayer): void
  {
    if ($event->getCause() == EntityDamageEvent::CAUSE_FALL) {
      if (!$this->fallDamage) {
        $event->cancel();
      } else {
        // custom fall damage because pocket-mine's is not consistent with vanilla, it is a tick too strong
        $this->adjustFallDamage($event, $swimPlayer);
      }
    } else if (!$this->voidDamage && $event->getCause() == EntityDamageEvent::CAUSE_VOID) {
      $event->cancel();
    }

    // now handle if that damage killed them (also do generic damage call back)
    if (!$event->isCancelled()) {
      $this->playerTakesMiscDamage($event, $swimPlayer);
      if ($event->getFinalDamage() >= $swimPlayer->getHealth() && !$event->isCancelled()) { // event can be cancelled by player takes misc damage
        $event->cancel();
        // gameplay scripting callback
        $this->playerDiedToMiscDamage($event, $swimPlayer);
      }
    }
  }

  // callback for when taking generic damage that isn't from an attack
  protected function playerTakesMiscDamage(EntityDamageEvent $event, SwimPlayer $swimPlayer): void
  {
  }

  // you really should override this method
  protected function playerDiedToMiscDamage(EntityDamageEvent $event, SwimPlayer $swimPlayer): void
  {
    echo("WARNING | " . $this->sceneName . " DID NOT HANDLE NATURAL CAUSE DEATH OF PLAYER " . $swimPlayer->getName() . "\n");
  }

  private function adjustFallDamage(EntityDamageEvent $event, SwimPlayer $swimPlayer): void
  {
    // Adjust the fall distance to match the vanilla behavior
    $adjustedFallDistance = $swimPlayer->getFallDistance() - self::GRAVITY_UNIT_PER_TICK;
    if ($adjustedFallDistance <= 0) {
      // If the adjusted fall distance is not enough to cause damage, cancel the event
      $event->cancel();
    } else {
      // Recalculate fall damage based on the adjusted fall distance
      $damage = $swimPlayer->calculateFallDamage($adjustedFallDistance);
      if ($damage > 0) {
        $event->setBaseDamage($damage);
      } else {
        $event->cancel();
      }
    }
  }

  // projectile launch handling
  public function sceneProjectileLaunchEvent(ProjectileLaunchEvent $event, SwimPlayer $swimPlayer): void
  {
    $player = $event->getEntity()->getOwningEntity();
    if ($player instanceof Player) {
      $item = $event->getEntity();
      if ($item instanceof PearlEntity) {
        $projectile = $event->getEntity();
        $projectile->setScale(0.5);
        $projectile->setMotion($projectile->getDirectionVector()->multiply($this->pearlSpeed));
        $projectile->setGravity($this->pearlGravity);
      }
    }
  }

  // projectile hit handling
  public function sceneEntityDamageByChildEntityEvent(EntityDamageByChildEntityEvent $event, SwimPlayer $swimPlayer): void
  {
    $player = $event->getEntity();
    if ($player instanceof SwimPlayer) {
      $attacker = $event->getChild()->getOwningEntity();
      if ($attacker instanceof SwimPlayer) {

        // we don't want team damage from children entities
        if ($this->arePlayersInSameTeam($attacker, $player)) {
          $event->cancel();
          return;
        }

        $child = $event->getChild();
        if ($child instanceof PearlEntity) {
          $event->setKnockBack($this->pearlKB);
        } else if ($child instanceof ArrowEntity) {
          $event->setKnockBack($this->arrowKB);
        } else if ($child instanceof SnowballEntity) {
          $event->setKnockBack($this->snowballKB);
        } else if ($child instanceof FishingHook) {
          $event->setKnockBack($this->rodKB);
        }

        // this allows projectile combos by turning cool down to 1 tick
        $event->setAttackCooldown(1);

        // scripted event callback derived scenes can override
        $this->hitByProjectile($swimPlayer, $attacker, $child, $event);
        // death check
        if ($event->getFinalDamage() >= $swimPlayer->getHealth()) {
          $event->cancel();
          // scripting event callback
          $this->playerDiedToChildEntity($event, $swimPlayer, $attacker, $child);
        }
      }
    }
  }

  // you should really override this
  protected function playerDiedToChildEntity(EntityDamageByChildEntityEvent $event, SwimPlayer $victim, SwimPlayer $attacker, Entity $childEntity): void
  {
    echo("WARNING | " . $this->sceneName . " DID NOT HANDLE CHILD ENTITY KILL ON PLAYER " . $victim->getName() . "\n");
    if (SwimCore::$DEBUG) StackTracer::PrintStackTrace();
  }

  // optional override
  protected function hitByProjectile(SwimPlayer $hitPlayer, SwimPlayer $hitter, Entity $projectile, EntityDamageByChildEntityEvent $event): void
  {
    if ($projectile instanceof ArrowEntity) {
      ServerSounds::playSoundToPlayer($hitter, 'note.bell', 2, 1);
      $hitter->sendMessage(TextFormat::GREEN . $hitPlayer->getNicks()->getNick() . " Has " . round($hitPlayer->getHealth(), 1) . " HP");
    }
  }

  protected function playerHit(SwimPlayer $attacker, SwimPlayer $victim, EntityDamageByEntityEvent $event): void
  {
    // optional override
  }

  protected function playerKilled(SwimPlayer $attacker, SwimPlayer $victim, EntityDamageByEntityEvent $event): void
  {
    // optional override
  }

  // can set natural regen to be cancelled
  public function sceneEntityRegainHealthEvent(EntityRegainHealthEvent $event, SwimPlayer $swimPlayer): void
  {
    if (!$this->naturalRegen && $event->getRegainReason() == EntityRegainHealthEvent::CAUSE_SATURATION) $event->cancel();
  }

  public function scenePlayerSpawnChildEvent(EntitySpawnEvent $event, SwimPlayer $swimPlayer, Entity $spawnedEntity): void
  {
    if ($spawnedEntity instanceof ItemEntity) {
      $this->droppedItemManager->addDroppedItem($spawnedEntity);
    }
  }

  public function scenePlayerPickupItem(EntityItemPickupEvent $event, SwimPlayer $swimPlayer): void
  {
    $origin = $event->getOrigin();
    if ($origin instanceof ItemEntity) {
      $this->droppedItemManager->removeDroppedItem($origin);
    }
  }

  public function sceneBlockBreakEvent(BlockBreakEvent $event, SwimPlayer $swimPlayer): void
  {
    $this->blocksManager->handleBlockBreak($event);
  }

  public function sceneBlockPlaceEvent(BlockPlaceEvent $event, SwimPlayer $swimPlayer): void
  {
    $this->blocksManager->handleBlockPlace($event);
  }

  public function exit(): void
  {
    $this->blocksManager->cleanMap();
    $this->droppedItemManager->despawnAll();
  }

}