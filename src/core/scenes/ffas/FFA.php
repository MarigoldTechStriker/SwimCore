<?php

namespace core\scenes\ffas;

use core\scenes\PvP;
use core\systems\player\SwimPlayer;
use core\utils\CoolAnimations;
use core\utils\InventoryUtil;
use jackmd\scorefactory\ScoreFactory;
use jackmd\scorefactory\ScoreFactoryException;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\player\GameMode;
use pocketmine\scheduler\ClosureTask;
use pocketmine\Server;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;

/**
 * You MUST implement a constructor for FFA derived classes, as that is where the world is set
 */
abstract class FFA extends PvP
{

  protected int $x;
  protected int $y;
  protected int $z;
  protected int $spawnOffset;

  protected bool $interruptAllowed = true;
  protected bool $respawnInArena = false; // by default warps back to hub, if true then warps back to arena

  // all FFA scenes autoload and are persistent
  public static function AutoLoad(): bool
  {
    return true;
  }

  public function init(): void
  {
    $this->teamManager->makeTeam('players', TextFormat::RESET);
    $this->teamManager->makeTeam('spectators', TextFormat::RESET);
  }

  protected function teleportToArena(SwimPlayer $player): void
  {
    $offSetX = mt_rand(-1 * $this->spawnOffset, $this->spawnOffset);
    $offSetZ = mt_rand(-1 * $this->spawnOffset, $this->spawnOffset);
    $pos = new Position($this->x + $offSetX, $this->y, $this->z + $offSetZ, $this->world);
    $player->teleport($pos);
  }

  // pvp mechanics
  public function sceneEntityDamageByEntityEvent(EntityDamageByEntityEvent $event, SwimPlayer $swimPlayer): void
  {
    $victim = $event->getEntity(); // which should be $swimPlayer
    $attacker = $event->getDamager();
    if ($victim instanceof SwimPlayer && $attacker instanceof SwimPlayer) {

      // combat logger is used for this to prevent 3rd partying
      if (!$this->interruptAllowed) {
        if (!$attacker->getCombatLogger()->handleAttack($swimPlayer)) {
          $event->cancel();
          return;
        }
      }

      // KB logic
      $event->setVerticalKnockBackLimit($this->vertKB);
      $event->setKnockBack($this->kb);
      $event->setAttackCooldown($this->hitCoolDown);

      // callback scripting event
      $this->playerHit($attacker, $victim, $event);

      // Death logic to set spec and send message and warp to hub after a few seconds (also checks if wasn't cancelled by player hit)
      if ($event->getFinalDamage() >= $victim->getHealth() && !$event->isCancelled()) {
        $event->cancel(); // cancel event so we don't kill them

        // callback scripting events
        $this->playerKilled($attacker, $victim, $event);
        $this->defaultDeathHandle($attacker, $victim);
      }
    }
  }

  protected function defaultDeathHandle(?SwimPlayer $attacker, SwimPlayer $victim): void
  {
    // cancel cool downs for the attacker since we just re-kitted them
    if ($attacker) {
      $attacker->getCoolDowns()?->clearAll();
      $kills = $attacker->getAttributes()?->emplaceIncrementIntegerAttribute("kill streak") ?? 0; // update kill streak
      if ($kills >= 3) {
        $name = ($attacker->getCosmetics()?->getNameColor() ?? "") . ($attacker->getNicks()?->getNick() ?? $attacker->getName());
        $this->sceneAnnouncement($name . TextFormat::GREEN . " is on a " . $kills . " Kill Streak!");
      }
    }

    // reset the victim inventory and set them to spec
    InventoryUtil::fullPlayerReset($victim);
    $victim->setGamemode(GameMode::SPECTATOR());
    $victim->getAttributes()->setAttribute("kill streak", 0); // reset kill streak

    // kill effect
    // CoolAnimations::lightningBolt($victim->getPosition(), $victim->getWorld());
    CoolAnimations::bloodDeathAnimation($victim->getPosition(), $victim->getWorld());
    CoolAnimations::explodeAnimation($victim->getPosition(), $victim->getWorld());

    if (!$this->respawnInArena) {
      // warp back to hub after a few seconds
      $this->core->getScheduler()->scheduleDelayedTask(new ClosureTask(function () use ($victim) {
        // must use safety checks when scheduling a task that uses a player reference, also check if session data still valid
        if ($victim) {
          if ($victim->isConnected()) {
            $victim?->getSceneHelper()?->setNewScene('Hub');
          }
        }
      }), 70);
    } else {
      $this->restart($victim);
    }
  }

  protected function ffaNameTag(SwimPlayer $player): void
  {
    if ($player->getNicks()->isNicked()) {
      $player->setNameTag(TextFormat::GRAY . $player->getNicks()->getNick());
    } else {
      $player->getCosmetics()->tagNameTag();
      // $color = Rank::getRankColor($player->getRank()->getRankLevel());
      // $player->setNameTag($color . $player->getName());
    }
  }

  protected function ffaScoreTag(SwimPlayer $player): void
  {
    $cps = $player->getClickHandler()->getCPS();
    $ping = $player->getNslHandler()->getPing();
    $player->setScoreTag(TextFormat::AQUA . $cps . TextFormat::WHITE . " CPS" .
      TextFormat::GRAY . " | " . TextFormat::AQUA . $ping . TextFormat::WHITE . " MS");
  }

  /**
   * @throws ScoreFactoryException
   */
  protected function ffaScoreboard(SwimPlayer $player): void
  {
    if ($player->isScoreboardEnabled()) {
      try {
        $player->refreshScoreboard(TextFormat::AQUA . "Swimgg.club");
        $p = $player;
        ScoreFactory::sendObjective($p);

        // variables needed
        $onlineCount = count($p->getWorld()->getPlayers()); // might want to replace this with get scene count for nodebuff ffa
        $ping = $player->getNslHandler()->getPing();
        $coolDown = $player->getCombatLogger()->getCombatCoolDown();
        $kills = strval($player->getAttributes()->getAttribute("kill streak") ?? 0);
        $indent = "  ";

        // define lines
        ScoreFactory::setScoreLine($p, 1, "  =============   ");
        ScoreFactory::setScoreLine($p, 2, $indent . "§bFFA: §3" . $onlineCount . " Players" . $indent);
        ScoreFactory::setScoreLine($p, 3, $indent . "§bPing: §3" . $ping . $indent);
        ScoreFactory::setScoreLine($p, 4, $indent . "§bKill Streak: §3" . $kills . $indent);

        $line = 5;
        if (!$this->interruptAllowed) {
          ScoreFactory::setScoreLine($p, $line, $indent . "§bCombat: §3" . $coolDown . $indent);
        } else {
          $line--;
        }

        ScoreFactory::setScoreLine($p, ++$line, $indent . "§bdiscord.gg/§3swim" . $indent);
        ScoreFactory::setScoreLine($p, ++$line, "  =============  ");
        // send lines
        ScoreFactory::sendLines($p);
      } catch (ScoreFactoryException $e) {
        Server::getInstance()->getLogger()->info($e->getMessage());
      }
    }
  }

}